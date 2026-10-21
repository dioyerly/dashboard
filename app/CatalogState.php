<?php
declare(strict_types=1);
namespace Centro;
require_once __DIR__.'/Business.php';

/** Native form DTOs mapped to the existing normalized catalogs, in the state transaction. */
final class CatalogState
{
 private const MAPS=[
  'currencies'=>['settings.currencies','code',['symbol'=>'symbol'],[]],
  'brands'=>['settings.brands','id',['name'=>'name','color'=>'color','dark'=>'dark','alt'=>'alt','desc'=>'description'],[]],
  'channels'=>['settings.channels','id',['name'=>'name','commission'=>'commission_pct'],['commission'=>null]],
  'accounts'=>['settings.accounts','id',['name'=>'name','currency'=>'currency_id'],[]],
  'projects'=>['projects','id',['name'=>'name','brandId'=>'brand_id','kind'=>'kind','status'=>'status','launchDate'=>'launch_date','url'=>'url','desc'=>'description','domain'=>'domain','repo'=>'repo','hosting'=>'hosting','database'=>'database_description','tech'=>'tech','milestone'=>'milestone','initialInvestment'=>'initial_investment','investCurrency'=>'investment_currency','notes'=>'notes','createdAt'=>'source_created_at','updatedAt'=>'source_updated_at'],['launchDate'=>null,'initialInvestment'=>null,'investCurrency'=>null,'createdAt'=>null,'updatedAt'=>null]],
  'customers'=>['customers','id',['name'=>'name','email'=>'email','country'=>'country','createdAt'=>'business_date','consent'=>'consent','notes'=>'notes','origin'=>'origin'],['email'=>null,'createdAt'=>null,'consent'=>null,'origin'=>null]],
  'suppliers'=>['suppliers','id',['name'=>'name','kind'=>'kind','url'=>'url','contact'=>'contact','taxId'=>'tax_id','notes'=>'notes'],[]],
  'time_logs'=>['timeLogs','id',['projectId'=>'project_id','date'=>'logged_on','hours'=>'hours','kind'=>'kind','note'=>'note'],[]],
  'integrations'=>['integrations','id',['name'=>'name','kind'=>'kind','method'=>'method','status'=>'status','projectId'=>'project_id','lastSync'=>'last_sync','errors'=>'errors_json','notes'=>'notes','stats'=>'source_stats'],['projectId'=>null,'lastSync'=>null,'stats'=>null]],
  'products'=>['products','id',['name'=>'name','brandId'=>'brand_id','projectId'=>'project_id','category'=>'category_label_snapshot','status'=>'status','launchDate'=>'launch_date','pendingUpdates'=>'pending_updates','createdAt'=>'source_created_at','updatedAt'=>'source_updated_at'],['projectId'=>null,'launchDate'=>null,'createdAt'=>null,'updatedAt'=>null]],
  'plans'=>['plans','id',['name'=>'name','projectId'=>'project_id','trialDays'=>'trial_days'],['trialDays'=>null]],
 ];
 private string $environment="";
 public function __construct(private readonly \PDO $pdo){}
 private function q(string $sql,array $a=[]):array{$q=$this->pdo->prepare($sql);$q->execute($a);return $q->fetchAll(\PDO::FETCH_ASSOC);}
 public function ready():bool{$rows=$this->q('SELECT checksum FROM schema_migrations WHERE version=?',['017_operational_completion']);return isset($rows[0])&&hash_equals(hash_file('sha256',dirname(__DIR__).'/database/migrations/017_operational_completion.sql'),$rows[0]['checksum']);}
 private static function get(array $s,string $path):mixed{foreach(explode('.',$path) as $part)$s=$s[$part]??[];return $s;}
 private static function child(mixed ...$parts):string{return 'imp_'.substr(hash('sha256',json_encode($parts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),0,60);}
 private function named(string $table,string $legacy,array $identity):string{
  $where=implode(' AND ',array_map(static fn($f)=>$f.'=?',array_keys($identity)));$rows=$this->q("SELECT id FROM $table WHERE environment_id=? AND $where ORDER BY archived_at IS NULL DESC,id",[$this->environment,...array_values($identity)]);if($rows)return $rows[0]['id'];
  $existing=$this->q("SELECT id FROM $table WHERE environment_id=? AND id=?",[$this->environment,$legacy]);return $existing?'cat_'.substr(hash('sha256',json_encode([$table,$identity],JSON_THROW_ON_ERROR)),0,55):$legacy;
 }
 private static function scalar(mixed $value):mixed{if($value===null)return null;if(is_bool($value))return (int)$value;if(is_int($value)||is_float($value))return json_encode($value,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);if(!is_string($value))throw new BusinessError('invalid_catalog_value');return $value;}
 private function same(string $field,mixed $a,mixed $b):bool{if($a===null||$b===null)return $a===$b;if(in_array($field,['price','commission_pct','initial_investment','trial_days','sort_order','rate','upcoming_days','no_sales_days','drop_pct','hourly_rate'],true)&&is_numeric($a)&&is_numeric($b))return bccomp((string)$a,(string)$b,18)===0;return (string)$a===(string)$b;}
 private function projection(array $s):array{
  $rows=[];$add=function(string $table,string $id,array $data,?string $archived=null)use(&$rows):void{if(isset($rows[$table][$id]))throw new BusinessError('duplicate_catalog_identity');$rows[$table][$id]=['data'=>$data,'archived'=>$archived];};
  foreach(self::MAPS as $table=>[$path,$identity,$map,$defaults])foreach(self::get($s,$path) as $i=>$record){
   if(!is_array($record)||!is_string($record[$identity]??null))throw new BusinessError('invalid_catalog_record');$data=[];
   foreach($map as $field=>$column){$v=array_key_exists($field,$record)?$record[$field]:(array_key_exists($field,$defaults)?$defaults[$field]:'');if(array_key_exists($field,$defaults)&&$defaults[$field]===null&&$v==='')$v=null;if(in_array($column,['errors_json','source_stats'],true))$data[$column]=$v===null?($column==='errors_json'?'[]':null):json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);else $data[$column]=self::scalar($v);}
   if($table==='plans'){$data['sort_order']=$i;$data['status']='activa';if($data['trial_days']!==null){if(!ctype_digit((string)$data['trial_days']))throw new BusinessError('invalid_trial_days');$data['trial_days']=(int)$data['trial_days'];}}
   if($table==='products')$data['category_id']=null;if($table==='integrations')$data['source_stats_present']=array_key_exists('stats',$record)?'1':'0';
   $add($table,$record[$identity],$data,$record['archivedAt']??null);
  }
  foreach(['formats'=>'formats','languages'=>'languages','payMethods'=>'payment_methods'] as $key=>$table)foreach($s['settings'][$key]??[] as $i=>$name)$add($table,$this->named($table,self::child($key,$i),['name'=>$name]),['name'=>$name,'sort_order'=>$i]);
  foreach($s['settings']['categories']??[] as $i=>$c)$add('product_categories',$this->named('product_categories',self::child('categories',$i),['brand_id'=>$c['brandId'],'name'=>$c['name']]),['brand_id'=>$c['brandId'],'name'=>$c['name'],'sort_order'=>$i]);
  foreach($s['settings']['expenseGroups']??[] as $i=>$g){$id=$this->named('expense_groups',self::child('groups',$i),['name'=>$g['name']]);$add('expense_groups',$id,['name'=>$g['name'],'sort_order'=>$i]);foreach($g['items'] as $j=>$name)$add('expense_categories',$this->named('expense_categories',self::child('groups',$i,$j),['group_id'=>$id,'name'=>$name]),['group_id'=>$id,'name'=>$name,'sort_order'=>$j]);}
  if(isset($rows['products']))foreach($rows['products'] as $id=>&$product){foreach($rows['product_categories']??[] as $cid=>$category)if($category['data']['brand_id']===$product['data']['brand_id']&&$category['data']['name']===$product['data']['category_label_snapshot'])$product['data']['category_id']=$cid;}unset($product);
  foreach($s['products']??[] as $p){$id=$p['id'];$archive=$p['archivedAt']??null;
   foreach($p['versions']??[] as $i=>$v){$vid=$v['id'];$data=['product_id'=>$id,'format_id'=>null,'format_label'=>$v['format'],'language_id'=>null,'language_label'=>$v['language']??'','version_label'=>$v['version']??'','price'=>self::scalar($v['price']),'currency_id'=>$v['currency'],'sort_order'=>$i];foreach(['formats'=>['format_id','format_label'],'languages'=>['language_id','language_label']] as $t=>[$ref,$label])foreach($rows[$t]??[] as $rid=>$r)if($r['data']['name']===$data[$label])$data[$ref]=$rid;$add('product_versions',$vid,$data,$v['archivedAt']??$archive);
    foreach($v['channels']??[] as $j=>$ch)$add('product_version_channels',self::child('channels',$vid,$j),['version_id'=>$vid,'channel_id'=>$ch,'sort_order'=>$j],$archive);
   }
   foreach(['resources'=>['product_links','product_id','link_value'],'bundle'=>['bundle_items','bundle_product_id','component_product_id']] as $key=>[$table,$owner,$column])foreach($p[$key]??[] as $i=>$value)$add($table,self::child($key,$id,$i),[$owner=>$id,$column=>$value,'sort_order'=>$i],$archive);
  }
  foreach($s['projects']??[] as $project)foreach($project['userStats']??[] as $i=>$stats)$add('project_user_stats',self::child('stats',$project['id'],$i),['project_id'=>$project['id'],'business_date'=>$stats['date'],'sequence_no'=>$i,'registered'=>$stats['registered'],'active'=>$stats['active'],'origin'=>$stats['origin']??null]);
  $settings=$s['settings']??[];$business=$settings['business']??[];
  if(($business['name']??'')!=='')$add('business_settings','main',['name'=>$business['name'],'owner'=>$business['owner']??'','site'=>$business['site']??'','base_currency'=>$settings['baseCurrency'],'num_format'=>$settings['numFormat']??'es']);
  if(isset($settings['alerts'])){$a=$settings['alerts'];$h=$settings['hourly']??[];$add('dataset_settings','main',['schema_version'=>1,'setup_done'=>isset($s['setupDone'])?(int)$s['setupDone']:null,'source_created_at'=>$s['createdAt']??null,'timezone'=>$s['timezone']??($settings['timezone']??null),'upcoming_days'=>isset($a['upcomingDays'])?self::scalar($a['upcomingDays']):null,'no_sales_days'=>isset($a['noSalesDays'])?self::scalar($a['noSalesDays']):null,'drop_pct'=>isset($a['dropPct'])?self::scalar($a['dropPct']):null,'hourly_rate'=>isset($h['rate'])?self::scalar($h['rate']):null,'hourly_currency'=>($h['currency']??'')?:null]);}
  foreach($s['rates']??[] as $rate)$add('exchange_rates',$rate['id'],['currency_id'=>$rate['currency'],'base_currency_id'=>$settings['baseCurrency'],'business_date'=>$rate['date'],'rate'=>self::scalar($rate['rate']),'source'=>$rate['source']??null]);
  foreach($s['plans']??[] as $plan)if(isset($plan['price'],$plan['currency'],$plan['period']))$add('plan_prices',self::child('price',$plan['id']),['plan_id'=>$plan['id'],'valid_from'=>null,'valid_to'=>null,'price'=>self::scalar($plan['price']),'currency_id'=>$plan['currency'],'period'=>$plan['period'],'provenance'=>'unknown']);
  return $rows;
 }
 public function synchronize(string $env,array $old,array $new):array{
  if(!isset($new['settings']['brands'])||!$this->ready())return [];
  $this->environment=$env;$before=$this->projection($old);$after=$this->projection($new);$changes=[];$remove=[];$service=new Business($this->pdo);
  // Parent tables first. All products precede bundles, all versions precede channels.
  $order=['currencies','brands','channels','accounts','business_settings','product_categories','formats','languages','payment_methods','expense_groups','expense_categories','projects','customers','suppliers','plans','products','product_versions','product_links','bundle_items','product_version_channels','dataset_settings','exchange_rates','plan_prices','time_logs','integrations','project_user_stats'];
  foreach($order as $table){$actual=[];foreach($this->q("SELECT * FROM $table WHERE environment_id=? FOR UPDATE",[$env]) as $r)$actual[$r['id']]=$r;
   foreach($after[$table]??[] as $id=>$r){$prev=$before[$table][$id]??null;$row=$actual[$id]??null;
    // Don't invent normalization for unchanged legacy fields; import already validated them.
    if($prev===$r&&$row)continue;
    if($row&&$row['archived_at']!==null&&$r['archived']!==null){$validated=$service->validateImportRow($table,(string)$id,$r['data']);foreach($validated as $field=>$value)if(!$this->same($field,$value,$row[$field]??null))throw new BusinessError('archived_catalog_read_only',409);continue;}
    $data=$r['data'];if($table==='project_user_stats'&&$row){$validated=$service->validateImportRow($table,(string)$id,$data);foreach($validated as $field=>$value)if(!$this->same($field,$value,$row[$field]??null))throw new BusinessError('immutable_history',409);continue;}if($table==='plan_prices'&&!$row){$known=$this->q('SELECT * FROM plan_prices WHERE environment_id=? AND plan_id=? AND archived_at IS NULL',[$env,$data['plan_id']]);if($known){$matching=array_filter($known,fn($price)=>$this->same('price',$price['price'],$data['price'])&&$price['currency_id']===$data['currency_id']&&$price['period']===$data['period']);if(!$matching)throw new BusinessError('catalog_price_evidence_requires_review',409);continue;}}if($table==='plan_prices'&&$row){$different=false;foreach($data as $field=>$value)if(is_numeric($value)&&is_numeric($row[$field]??null)?bccomp((string)$value,(string)$row[$field],18)!==0:(string)$value!==(string)($row[$field]??null))$different=true;if($different)throw new BusinessError('immutable_price',409);continue;}$validated=$service->validateImportRow($table,(string)$id,$data);
    if($row&&$row['archived_at']!==null&&$r['archived']===null){$changes[]=['resource'=>$table,'id'=>(string)$id,'action'=>'restore','expected_version'=>(int)$row['row_version'],'data'=>[]];if(array_filter($validated,fn($v,$k)=>!$this->same($k,$v,$row[$k]??null),ARRAY_FILTER_USE_BOTH))$changes[]=['resource'=>$table,'id'=>(string)$id,'action'=>'update','expected_version'=>(int)$row['row_version']+1,'data'=>$data];}
    elseif(!$row||array_filter($validated,fn($v,$k)=>!$this->same($k,$v,$row[$k]??null),ARRAY_FILTER_USE_BOTH))$changes[]=['resource'=>$table,'id'=>(string)$id,'action'=>$row?'update':'create','expected_version'=>$row?(int)$row['row_version']:0,'data'=>$data];
    if($r['archived']!==null&&(!$row||$row['archived_at']===null))$remove[]=['resource'=>$table,'id'=>(string)$id,'action'=>'archive','expected_version'=>$row?(int)$row['row_version']+((end($changes)['id']??null)===$id?1:0):1,'data'=>[]];
   }
   foreach($before[$table]??[] as $id=>$r)if(!isset($after[$table][$id])&&isset($actual[$id])&&$actual[$id]['archived_at']===null)$remove[]=['resource'=>$table,'id'=>(string)$id,'action'=>'archive','expected_version'=>(int)$actual[$id]['row_version'],'data'=>[]];
  }
  $applied=[...$changes,...array_reverse($remove)];$service->applyStateChanges($env,$applied);return array_map(static fn($c)=>array_intersect_key($c,array_flip(['resource','id','action','expected_version'])),$applied);
 }
 public function hydrate(string $env,array $s):array{
  if(!isset($s['settings']['brands'])||!$this->ready())return $s;
  foreach(self::MAPS as $table=>[$path,$identity,$map,$defaults]){
   $old=[];foreach(self::get($s,$path) as $record)$old[$record[$identity]]=$record;$result=[];
   foreach($this->q("SELECT * FROM $table WHERE environment_id=? ORDER BY created_at,id",[$env]) as $row){if($row['archived_at']!==null&&!isset($old[$row['id']]))continue;$record=$old[$row['id']]??[$identity=>$row['id']];
    foreach($map as $field=>$column){$v=$row[$column];if(in_array($column,['errors_json','source_stats'],true))$v=$v===null?null:json_decode($v,true,64,JSON_THROW_ON_ERROR);$prior=$record[$field]??null;if(!array_key_exists($field,$record)&&($v===null||$v===''))continue;if($column==='trial_days'&&$v!==null)$v=(int)$v;if($column==='consent'&&$v!==null)$v=(bool)$v;$record[$field]=$v===null&&$prior===''?'':$v;}
    if($row['archived_at']!==null)$record['archivedAt']=$row['archived_at'];else unset($record['archivedAt']);$result[]=$record;
   }
   $positions=array_flip(array_keys($old));usort($result,static fn($a,$b)=>($positions[$a[$identity]]??PHP_INT_MAX)<=>($positions[$b[$identity]]??PHP_INT_MAX));
   $parts=explode('.',$path);if(count($parts)===2)$s[$parts[0]][$parts[1]]=$result;else $s[$path]=$result;
  }
  foreach(['formats'=>'formats','languages'=>'languages','payMethods'=>'payment_methods'] as $key=>$table)$s['settings'][$key]=array_column($this->q("SELECT name FROM $table WHERE environment_id=? AND archived_at IS NULL ORDER BY sort_order,id",[$env]),'name');
  $s['settings']['categories']=array_map(static fn($r)=>['brandId'=>$r['brand_id'],'name'=>$r['name']],$this->q('SELECT brand_id,name FROM product_categories WHERE environment_id=? AND archived_at IS NULL ORDER BY sort_order,id',[$env]));
  $groups=[];foreach($this->q('SELECT * FROM expense_groups WHERE environment_id=? AND archived_at IS NULL ORDER BY sort_order,id',[$env]) as $g)$groups[]=['name'=>$g['name'],'items'=>array_column($this->q('SELECT name FROM expense_categories WHERE environment_id=? AND group_id=? AND archived_at IS NULL ORDER BY sort_order,id',[$env,$g['id']]),'name')];$s['settings']['expenseGroups']=$groups;
  foreach($s['products'] as &$p){$old=[];foreach($p['versions']??[] as $v)$old[$v['id']]=$v;$versions=[];
   foreach($this->q('SELECT * FROM product_versions WHERE environment_id=? AND product_id=? ORDER BY sort_order,id',[$env,$p['id']]) as $v){$record=$old[$v['id']]??['id'=>$v['id']];foreach(['format'=>'format_label','language'=>'language_label','version'=>'version_label','price'=>'price','currency'=>'currency_id'] as $field=>$column)$record[$field]=$v[$column];$record['channels']=array_column($this->q('SELECT channel_id FROM product_version_channels WHERE environment_id=? AND version_id=? AND archived_at IS NULL ORDER BY sort_order,id',[$env,$v['id']]),'channel_id');if($v['archived_at']!==null)$record['archivedAt']=$v['archived_at'];$versions[]=$record;}$p['versions']=$versions;
   foreach(['resources'=>['product_links','product_id','link_value'],'bundle'=>['bundle_items','bundle_product_id','component_product_id']] as $key=>[$t,$owner,$col])$p[$key]=array_column($this->q("SELECT $col FROM $t WHERE environment_id=? AND $owner=? AND archived_at IS NULL ORDER BY sort_order,id",[$env,$p['id']]),$col);
  }unset($p);
  $business=$this->q('SELECT * FROM business_settings WHERE environment_id=? AND id="main" AND archived_at IS NULL',[$env]);if($business){$r=$business[0];$s['settings']['business']=array_replace($s['settings']['business']??[],['name'=>$r['name'],'owner'=>$r['owner'],'site'=>$r['site']]);$s['settings']['baseCurrency']=$r['base_currency'];$s['settings']['numFormat']=$r['num_format'];}
  foreach($s['projects'] as &$project){$stats=$this->q('SELECT * FROM project_user_stats WHERE environment_id=? AND project_id=? ORDER BY sequence_no,id',[$env,$project['id']]);if($stats||array_key_exists('userStats',$project))$project['userStats']=array_map(static fn($r)=>['date'=>$r['business_date'],'registered'=>(int)$r['registered'],'active'=>(int)$r['active'],...($r['origin']===null?[]:['origin'=>$r['origin']])],$stats);}unset($project);
  $dataset=$this->q('SELECT * FROM dataset_settings WHERE environment_id=? AND id="main"',[$env]);if($dataset){$d=$dataset[0];foreach(['upcomingDays'=>'upcoming_days','noSalesDays'=>'no_sales_days','dropPct'=>'drop_pct'] as $field=>$column)if($d[$column]!==null)$s['settings']['alerts'][$field]=(float)$d[$column];$s['version']=(int)$d['schema_version'];if($d['setup_done']!==null)$s['setupDone']=(bool)$d['setup_done'];if($d['timezone']!==null)$s['timezone']=$d['timezone'];$s['settings']['hourly']=['rate'=>$d['hourly_rate'],'currency'=>$d['hourly_currency']??''];}
  $s['rates']=array_map(static fn($r)=>['id'=>$r['id'],'currency'=>$r['currency_id'],'date'=>$r['business_date'],'rate'=>$r['rate'],'source'=>$r['source']],$this->q('SELECT * FROM exchange_rates WHERE environment_id=? AND archived_at IS NULL ORDER BY business_date,id',[$env]));
  foreach($s['plans'] as &$plan){$prices=$this->q('SELECT * FROM plan_prices WHERE environment_id=? AND plan_id=? AND archived_at IS NULL ORDER BY valid_from DESC',[$env,$plan['id']]);if(count($prices)===1){$price=$prices[0];$plan['price']=$price['price'];$plan['currency']=$price['currency_id'];$plan['period']=$price['period'];}}unset($plan);
  return $s;
 }
}
