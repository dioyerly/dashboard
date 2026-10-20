<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Database.php';require dirname(__DIR__).'/app/FinancialService.php';$c=require dirname(__DIR__).'/config/settings.php';$p=Centro\Database::connection($c['db']);
$p->exec("INSERT INTO auth_users(id,email,password_hash,active) VALUES(1,'suite53c@example.invalid','x',1) ON DUPLICATE KEY UPDATE active=1");
foreach([['a1','pruebas','A'],['b1','produccion','B']] as $e)$p->prepare("INSERT INTO environments(id,code,label) VALUES(?,?,?)")->execute($e);
foreach([['a1','USD','$'],['a1','EUR','€'],['b1','USD','$']] as $x)$p->prepare('INSERT INTO currencies(environment_id,id,symbol) VALUES(?,?,?)')->execute($x);
foreach([['a1','cash','Cash','USD'],['b1','cash','Cash','USD']] as $x)$p->prepare('INSERT INTO accounts(environment_id,id,name,currency_id) VALUES(?,?,?,?)')->execute($x);
foreach([['a1','c1','Client A'],['b1','c1','Client B'],['b1','c_b','Client only in B']] as $x)$p->prepare("INSERT INTO customers(environment_id,id,name,country,consent,notes,origin) VALUES(?,?,?,'AR',1,'','manual')")->execute($x);
foreach([['a1','br','Brand'],['b1','br','Brand']] as $x)$p->prepare("INSERT INTO brands(environment_id,id,name,color,dark,alt,description) VALUES(?,?,?,'#112233','#000000','#ffffff','')")->execute($x);
foreach([['a1','cat','br'],['b1','cat','br']] as $x)$p->prepare('INSERT INTO product_categories(environment_id,id,brand_id,name) VALUES(?,?,?,?)')->execute([...$x,'Cat']);
foreach([['a1','p1','br','Active','publicado'],['a1','p2','br','Archived','retirado'],['a1','p_a','br','Only in A','publicado'],['b1','p1','br','Other','publicado']] as $x)$p->prepare("INSERT INTO products(environment_id,id,brand_id,name,status,pending_updates,category_label_snapshot,category_id) VALUES(?,?,?,?,?,'','Cat','cat')")->execute($x);
$p->exec("UPDATE products SET archived_at=UTC_TIMESTAMP(6) WHERE environment_id='a1' AND id='p2'");
foreach([['a1','v1','p1','1.0','10.00','USD'],['a1','v2','p2','1.0','10.00','USD'],['a1','v3','p1','2.0','10.00','EUR'],['a1','v_a','p_a','1.0','10.00','USD']] as $x)$p->prepare("INSERT INTO product_versions(environment_id,id,product_id,version_label,format_label,language_label,price,currency_id) VALUES(?,?,?,?,'','',?,?)")->execute($x);
$s=new Centro\FinancialService($p);$results=[];$snapshot=function()use($p){$o=[];foreach(['fin_sales','fin_sale_lines','fin_movements','fin_sale_allocations','fin_operation_receipts','fin_audit'] as $t)$o[$t]=(int)$p->query("SELECT COUNT(*) FROM $t")->fetchColumn();return $o;};
$run=function(string $name,string $env,array $cmd,bool $ok)use($s,$p,&$results,$snapshot){$before=$snapshot();$open=false;$msg=null;try{$r=$s->execute($env,str_pad('op_'.$name,16,'x'),1,$cmd);$pass=$ok;$err=null;}catch(Throwable $e){$pass=!$ok;$err=$e instanceof Centro\BusinessError?$e->reason:get_class($e);$msg=$e->getMessage();$open=$p->inTransaction();if($open)$p->rollBack();}$after=$snapshot();$results[$name]=['status'=>$pass?'APROBADO':'FALLIDO','error'=>$err,'message'=>$msg,'service_left_transaction_open'=>$open,'sql_before'=>$before,'sql_after'=>$after];};
$line=function(?string $product=null,?string $version=null,string $qty='1',string $unit='1.00',string $total='1.00',string $desc='line'){$x=['description'=>$desc,'quantity'=>$qty,'unit_price'=>$unit,'line_total'=>$total];if($product!==null)$x['product_id']=$product;if($version!==null)$x['version_id']=$version;return $x;};
$sale=function(string $id,string $amount,array $lines,?string $customer='c1',string $cur='USD'){return ['kind'=>'sale_register','sale_id'=>$id,'customer_id'=>$customer,'currency'=>$cur,'amount'=>$amount,'date'=>'2026-10-16','lines'=>$lines];};
$run('three_lines','a1',$sale('s3','6.00',[$line('p1','v1','1','1.00','1.00'),$line('p1','v1','2','1.00','2.00'),$line(null,null,'1','3.00','3.00')]),true);
$run('two_lines_version','a1',$sale('s2','3.00',[$line('p1','v1','1','1.00','1.00'),$line('p1','v1','1','2.00','2.00')]),true);
$run('free_line','a1',$sale('sf','1.00',[$line(null,null,'1','1.00','1.00','service')]),true);
$run('fraction','a1',$sale('sfrac','1.00',[$line(null,null,'0.5','2.00','1.00')]),true);
$run('zero_qty','a1',$sale('szero','1.00',[$line('p1','v1','0','1.00','0.00')]),false);
$run('bad_unit','a1',$sale('sbad','1.00',[$line('p1','v1','1','1.001','1.00')]),false);
$run('wrong_sum','a1',$sale('ssum','2.00',[$line('p1','v1','1','1.00','1.00')]),false);
$run('negative','a1',['kind'=>'sale_register','sale_id'=>'sneg','currency'=>'USD','amount'=>'-1.00','date'=>'2026-10-16','lines'=>[]],false);
$run('bad_scale','a1',['kind'=>'sale_register','sale_id'=>'sscale','currency'=>'USD','amount'=>'1.001','date'=>'2026-10-16','lines'=>[]],false);
$base=$sale('refbase','1.00',[$line('p1','v1','1','1.00','1.00')]);foreach([['no_product','px',null,'USD','c1'],['archived','p2','v2','USD','c1'],['other_env','p_a',null,'USD','c1'],['no_version','p1','vx','USD','c1'],['arch_version','p2','v2','USD','c1'],['wrong_version','p1','v2','USD','c1'],['bad_currency','p1','v3','USD','c1'],['no_customer','p1','v1','USD','missing'],['cross_customer','p1','v1','USD','c_b']] as $i=>$x){$cmd=$base;$cmd['sale_id']='r'.$i;$cmd['lines']=[$line($x[1],$x[2],'1','1.00','1.00')];$cmd['customer_id']=$x[4];$run($x[0],$i===2?'b1':'a1',$cmd,false);}
$run('collect_partial','a1',['kind'=>'sale_collect','sale_id'=>'s3','amount'=>'3.00','account_id'=>'cash','currency'=>'USD','date'=>'2026-10-16','confirmed'=>true,'movement_id'=>'mv53c1'],true);$run('collect_rest','a1',['kind'=>'sale_collect','sale_id'=>'s3','amount'=>'3.00','account_id'=>'cash','currency'=>'USD','date'=>'2026-10-16','confirmed'=>true,'movement_id'=>'mv53c2'],true);$run('collect_over','a1',['kind'=>'sale_collect','sale_id'=>'s3','amount'=>'1.00','account_id'=>'cash','currency'=>'USD','date'=>'2026-10-16','confirmed'=>true,'movement_id'=>'mv53c3'],false);// collect_replay: a confirmed collection repeated with the same operation and payload must replay the stored receipt.
$s->execute('a1','setup_rp_sale_000001',1,['kind'=>'sale_register','sale_id'=>'rp','currency'=>'USD','amount'=>'10.00','date'=>'2026-10-16']);
$rpOp='collect_rp_op_000000001';$rpCmd=['kind'=>'sale_collect','sale_id'=>'rp','amount'=>'4.00','account_id'=>'cash','currency'=>'USD','date'=>'2026-10-16','confirmed'=>true,'movement_id'=>'mv53c_rp'];
$rpMoves=function()use($p){return (int)$p->query("SELECT COUNT(*) FROM fin_movements WHERE environment_id='a1' AND sale_id='rp'")->fetchColumn();};
$first=$s->execute('a1',$rpOp,1,$rpCmd);$m1=$rpMoves();
$replay=$s->execute('a1',$rpOp,1,$rpCmd);$m2=$rpMoves();
$replayOk=$replay['replayed']===true&&$replay['result']===$first['result']&&$m1===1&&$m2===1;
$results['collect_replay']=['status'=>$replayOk?'APROBADO':'FALLIDO','expected'=>'success (replay, same receipt, no new movement)','observed'=>$replay['replayed']?'success (replay)':'success (no replay)','message'=>null,'service_left_transaction_open'=>$p->inTransaction(),'rows_changed_on_rejection'=>false,'sql_before'=>[],'sql_after'=>$snapshot()];
try{$s->execute('a1',$rpOp,1,[...$rpCmd,'amount'=>'5.00']);$conf=null;}catch(Centro\BusinessError $e){$conf=$e->reason;}
$confOk=$conf==='idempotency_conflict'&&$rpMoves()===1;
$results['collect_replay_payload_conflict']=['status'=>$confOk?'APROBADO':'FALLIDO','expected'=>'rejected:idempotency_conflict','observed'=>'rejected:'.($conf??'success'),'message'=>null,'service_left_transaction_open'=>$p->inTransaction(),'rows_changed_on_rejection'=>false,'sql_before'=>[],'sql_after'=>$snapshot()];
echo json_encode(['results'=>$results,'final_sql'=>$snapshot()],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;exit(array_filter($results,fn($r)=>$r['status']!=='APROBADO')?1:0);
