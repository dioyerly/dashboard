<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';require dirname(__DIR__).'/app/Business.php';
if(PHP_SAPI!=='cli' || $config['db']['host']!=='127.0.0.1' || !str_starts_with($config['db']['name'],'centro_auth_'))exit(1);
$pdo=Centro\Database::connection($config['db']);$s=new Centro\Business($pdo);$checks=[];
function check(string $name,bool $ok):void{global $checks;$checks[]=['check'=>$name,'passed'=>$ok];if(!$ok)throw new RuntimeException($name);}
function ch(string $res,string $id,array $data=[],string $act='create',int $v=0):array{return ['resource'=>$res,'id'=>$id,'data'=>$data,'action'=>$act,'expected_version'=>$v];}
function run(array $changes,?string $token=null,string $env='test'):array{global $s;return $s->command($env,$token??bin2hex(random_bytes(16)),1,['changes'=>$changes]);}
function fails(callable $fn,string $reason):bool{try{$fn();return false;}catch(Centro\BusinessError $e){return $e->reason===$reason;}}
check('003 checksum registered', $pdo->query("SELECT checksum FROM schema_migrations WHERE version='003_catalogs_products'")->fetchColumn()===hash_file('sha256',dirname(__DIR__).'/database/migrations/003_catalogs_products.sql'));
check('Recovery retained sentinel row in partially created table',(int)$pdo->query("SELECT COUNT(*) FROM formats WHERE id='recovery_sentinel'")->fetchColumn()===1);
$data=[
ch('formats','fmt_pdf',['name'=>'PDF','sort_order'=>2]),ch('languages','lang_es',['name'=>'Español']),ch('payment_methods','pm_wire',['name'=>'Transferencia']),ch('expense_groups','eg_tech',['name'=>'Technology']),ch('expense_categories','ec_host',['group_id'=>'eg_tech','name'=>'Hosting']),ch('product_categories','cat_test',['brand_id'=>'br_test','name'=>'Books']),ch('channels','ch_live',['name'=>'Synthetic live channel']),
ch('plans','pl_test',['project_id'=>'pj_test','name'=>'Monthly','status'=>'activa','trial_days'=>14]),ch('plan_prices','pp_1',['plan_id'=>'pl_test','valid_from'=>'2026-01-01','price'=>'10.00','currency_id'=>'ARS','period'=>'mensual','provenance'=>'known']),
ch('products','pd_one',['brand_id'=>'br_test','project_id'=>'pj_test','category_id'=>'cat_test','category_label_snapshot'=>'Books','name'=>'One','status'=>'publicado','pending_updates'=>'Fix chapter','source_created_at'=>'2026-01-01T00:00:00.123Z']),
ch('products','pd_bundle',['brand_id'=>'br_test','name'=>'Bundle','status'=>'borrador']),ch('product_versions','vr_one',['product_id'=>'pd_one','format_id'=>'fmt_pdf','format_label'=>'PDF','language_id'=>'lang_es','language_label'=>'Español','version_label'=>'1','price'=>'12.34','currency_id'=>'ARS']),
ch('product_links','lk_one',['product_id'=>'pd_one','link_value'=>'C:/synthetic/package.zip','sort_order'=>1]),ch('bundle_items','bi_one',['bundle_product_id'=>'pd_bundle','component_product_id'=>'pd_one']),ch('product_channels','pc_one',['product_id'=>'pd_one','channel_id'=>'ch_live']),ch('product_version_channels','vc_one',['version_id'=>'vr_one','channel_id'=>'ch_live']),
ch('project_user_stats','us_0',['project_id'=>'pj_test','business_date'=>'2026-01-02','sequence_no'=>0,'registered'=>0,'active'=>3,'origin'=>'manual']),ch('project_user_stats','us_1',['project_id'=>'pj_test','business_date'=>'2026-01-02','sequence_no'=>1,'registered'=>10,'active'=>3,'origin'=>'integracion'])];
$token=bin2hex(random_bytes(16));$r=run($data,$token);check('Compound catalog, plan, product, version, bundle and channels commit atomically',count($r['result'])===18);
check('Batch retry replays without duplicates',run($data,$token)['replayed']);
check('Local file/resource path preserved as text',$s->list('test','product_links')[0]['link_value']==='C:/synthetic/package.zip');
check('Source creation timestamp preserved separately from technical time',$s->list('test','products')[1]['source_created_at']==='2026-01-01 00:00:00.123000');
check('Multiple project statistics on same day retain sequence and partial counts',count($s->list('test','project_user_stats'))===2);
check('Historical statistics cannot be overwritten',fails(fn()=>run([ch('project_user_stats','us_0',['active'=>9],'update',1)]),'immutable_history'));
run([ch('project_user_stats','us_no_origin',['project_id'=>'pj_test','business_date'=>'2026-01-02','sequence_no'=>2,'registered'=>5,'active'=>2])]);
check('Original statistics without origin remain NULL instead of fabricated manual origin',$s->list('test','project_user_stats')[2]['origin']===null);
run([ch('product_versions','vr_one',['price'=>'15.20'],'update',1)]);
$hist=$s->page('test','product_version_history',100,null,false,['version_id'=>'vr_one'])['records'];usort($hist,fn($a,$b)=>$a['revision']<=>$b['revision']);
check('Product version edits retain immutable previous price and labels',count($hist)===2 && $hist[0]['price']==='12.34' && $hist[1]['price']==='15.20');
check('History is read-only through services',fails(fn()=>run([ch('product_version_history',$hist[0]['id'],['price'=>'1.00'],'update',1)]),'immutable_history'));
run([ch('plan_prices','pp_1',['valid_to'=>'2026-02-01'],'update',1),ch('plan_prices','pp_2',['plan_id'=>'pl_test','valid_from'=>'2026-02-01','price'=>'20.00','currency_id'=>'ARS','period'=>'mensual','provenance'=>'known'])]);
$prices=$s->page('test','plan_prices',100,null,false,['plan_id'=>'pl_test'])['records'];
check('Plan prices use non-overlapping half-open historical intervals',count($prices)===2 && $prices[0]['price']==='10.00' && $prices[0]['valid_to']==='2026-02-01');
check('Historical plan price cannot be rewritten',fails(fn()=>run([ch('plan_prices','pp_1',['price'=>'30.00'],'update',2)]),'immutable_price'));
check('Overlapping price rolls back',fails(fn()=>run([ch('plan_prices','pp_overlap',['plan_id'=>'pl_test','valid_from'=>'2026-01-15','valid_to'=>'2026-01-20','price'=>'1.00','currency_id'=>'ARS','period'=>'anual','provenance'=>'known'])]),'price_overlap'));
check('Archived price is not a way to erase history',fails(fn()=>run([ch('plan_prices','pp_1',[],'archive',2)]),'immutable_price'));
check('Catalog parent with active dependents cannot be archived',fails(fn()=>run([ch('formats','fmt_pdf',[],'archive',1)]),'entity_in_use'));
check('Duplicate normalized version rejected',fails(fn()=>run([ch('product_versions','vr_dup',['product_id'=>'pd_one','format_label'=>'pdf','language_label'=>'ESPAÑOL','version_label'=>'1','price'=>'1.00','currency_id'=>'ARS'])]),'integrity_conflict'));
check('Bundle graph rejects indirect cycle',fails(fn()=>run([ch('bundle_items','bi_cycle',['bundle_product_id'=>'pd_one','component_product_id'=>'pd_bundle'])]),'bundle_cycle'));
check('Self-bundle rejected by SQL constraint',fails(fn()=>run([ch('bundle_items','bi_self',['bundle_product_id'=>'pd_one','component_product_id'=>'pd_one'])]),'integrity_conflict'));
check('Duplicate channel association rejected',fails(fn()=>run([ch('product_version_channels','vc_dup',['version_id'=>'vr_one','channel_id'=>'ch_live'])]),'integrity_conflict'));
run([ch('product_version_channels','vc_one',[],'archive',1),ch('product_version_channels','vc_one',[],'restore',2)]);
check('Removing and re-adding a version channel restores same identity without duplicate',$s->list('test','product_version_channels')[0]['id']==='vc_one' && $s->list('test','product_version_channels')[0]['row_version']==3);
check('Cross-environment product reference rejected',fails(fn()=>run([ch('product_links','bad',['product_id'=>'pd_one','link_value'=>'Synthetic'])],null,'prod'),'invalid_reference'));
check('Category must belong to product brand and entire batch rolls back',fails(fn()=>run([ch('brands','br_other',['name'=>'Other','color'=>'#112233','dark'=>'#223344','alt'=>'#334455']),ch('products','pd_bad',['brand_id'=>'br_other','category_id'=>'cat_test','name'=>'Bad','status'=>'borrador'])]),'category_brand_mismatch'));
check('No orphan brand remains from failed compound operation',!(bool)$pdo->query("SELECT id FROM brands WHERE id='br_other'")->fetchColumn());
run([ch('product_versions','vr_legacy',['product_id'=>'pd_one','format_label'=>'Retired custom format','language_label'=>'Free text language','version_label'=>'v2','price'=>'0.00','currency_id'=>'ARS','sort_order'=>9])]);
check('Retired/free-text format and language snapshots do not require invented catalog references',count($s->page('test','product_versions',100,null,false,['product_id'=>'pd_one'])['records'])===2);
run([ch('payment_methods','pm_wire',['name'=>'Renamed'],'update',1),ch('payment_methods','pm_wire',[],'archive',2)]);
check('Catalog edit/archive preserves identity and increments version',$s->list('test','payment_methods')===[] && $s->list('test','payment_methods',true)[0]['row_version']==3);
run([ch('projects','pj_test',['source_created_at'=>'2025-12-31T23:00:00-03:00'],'update',2)]);
check('Project source timestamp accepted without replacing technical creation',$s->list('test','projects')[0]['source_created_at']==='2026-01-01 02:00:00.000000');
check('Price exponent and float data rejected',fails(fn()=>run([ch('product_versions','vr_bad',['product_id'=>'pd_one','format_label'=>'PDF','version_label'=>'bad','price'=>'1e2','currency_id'=>'ARS'])]),'invalid_decimal'));
// More than 500 records are created through normal transactional commands.
for($i=0;$i<620;$i+=50){$batch=[];for($j=$i;$j<min(620,$i+50);$j++)$batch[]=ch('formats','page_'.str_pad((string)$j,4,'0',STR_PAD_LEFT),['name'=>'Page format '.$j,'sort_order'=>$j]);run($batch);}
$all=[];$cursor=null;$pages=0;
do{$page=$s->page('test','formats',73,$cursor);$all=[...$all,...array_column($page['records'],'id')];$cursor=$page['next_cursor'];$pages++;}while($cursor!==null);
check('Keyset pagination traverses more than 500 records without loss or duplicates',count($all)===622 && count(array_unique($all))===622 && $pages===9);
$sorted=$all;sort($sorted,SORT_STRING);check('Pagination order is stable binary ID',$sorted===$all);
$first=$s->page('test','formats',17);$cursor=$first['next_cursor'];$next=$s->page('test','formats',17,$cursor);
check('Repeating the same cursor returns identical records',$next['records']===$s->page('test','formats',17,$cursor)['records']);
check('Cursor is bound to query and page size',fails(fn()=>$s->page('test','formats',18,$cursor),'invalid_cursor'));
check('Cursor is bound to environment',fails(fn()=>$s->page('prod','formats',17,$cursor),'invalid_cursor'));
check('Invalid cursor/page size/filter rejected',fails(fn()=>$s->page('test','formats',0),'invalid_page_size') && fails(fn()=>$s->page('test','formats',17,'not-a-cursor'),'invalid_cursor') && fails(fn()=>$s->page('test','formats',17,null,false,['name'=>'injection']),'invalid_filter'));
run([ch('formats','new_during_page',['name'=>'Concurrent addition'])]);
check('Mid-pagination mutation returns conflict instead of mixing snapshots',fails(fn()=>$s->page('test','formats',17,$cursor),'pagination_conflict'));
run([ch('plans','pl_test',['name'=>'Renamed plan'],'update',1),ch('plans','pl_test',[],'archive',2)]);
check('Plan archive keeps historical price references resolvable',count($s->list('test','plans',true))===1 && count($s->list('test','plan_prices'))===2);
echo json_encode(['checks'=>$checks,'page_record_count'=>count($all)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
