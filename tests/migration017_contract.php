<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Database.php';
require dirname(__DIR__).'/app/FinancialService.php';
require dirname(__DIR__).'/app/FinancialState.php';
$c=require dirname(__DIR__).'/config/settings.php';
if($c['environment']!=='local'||$c['db']['host']!=='127.0.0.1'||!preg_match('/\Acentro_auth_[a-z0-9_]+\z/D',$c['db']['name']))throw new RuntimeException('Synthetic database required');
$p=Centro\Database::connection($c['db']);$phase=$argv[1]??'';
$q=function(string $sql,array $a=[])use($p){$q=$p->prepare($sql);$q->execute($a);return $q;};
$s=new Centro\FinancialService($p);
if($phase==='seed'){
 $p->exec("INSERT INTO customers(environment_id,id,name,country,consent,notes,origin) VALUES('pruebas','m17_customer','Synthetic migration client','AR',NULL,'',NULL)");
 $p->exec("INSERT INTO brands(environment_id,id,name,color,dark,alt,description) VALUES('pruebas','m17_brand','Synthetic migration brand','#112233','#000000','#ffffff','')");
 $p->exec("INSERT INTO projects(environment_id,id,brand_id,name,kind,status,url,description,domain,repo,hosting,database_description,tech,milestone,notes) VALUES('pruebas','m17_project','m17_brand','Migration app','suscripcion','publicada','','','','','','','','','')");
 $p->exec("INSERT INTO plans(environment_id,id,project_id,name,status,trial_days) VALUES('pruebas','m17_plan','m17_project','Synthetic monthly','activa',7)");
 $p->exec("INSERT INTO plan_prices(environment_id,id,plan_id,valid_from,price,currency_id,period,provenance) VALUES('pruebas','m17_price','m17_plan','2020-01-01',10.00,'USD','mensual','known')");
 $p->exec("INSERT INTO subscriptions(environment_id,id,project_id,plan_id,customer_id,status,start_date,next_renewal) VALUES('pruebas','m17_sub','m17_project','m17_plan','m17_customer','prueba','2026-01-01','2026-01-08')");
 $p->exec("INSERT INTO subscription_events(environment_id,id,subscription_id,event_date,event_type,plan_id,note) VALUES('pruebas','m17_old_event','m17_sub','2026-01-01','prueba','m17_plan','Original synthetic event')");
 $s->execute('pruebas','m17_original_collection',1,['kind'=>'sale_collect','sale_id'=>'legacy','expected_version'=>1,'amount'=>'10.00','currency'=>'USD','date'=>'2026-01-02','confirmed'=>true,'account_id'=>'cash']);
 $s->execute('pruebas','m17_original_expense',1,['kind'=>'expense_register','expense_id'=>'m17_expense','amount'=>'2.50','currency'=>'USD','date'=>'2026-01-02','settle_now'=>true,'confirmed'=>true,'account_id'=>'cash']);
 echo json_encode(['seeded'=>true],JSON_THROW_ON_ERROR);exit;
}
if($phase==='snapshot'){
 $hashes=[];
 foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
  if(in_array($table,['schema_migrations','fin_entity_metadata','fin_subscription_evidence'],true))continue;
  if(!preg_match('/\A[a-z_]+\z/D',$table))throw new RuntimeException('Unexpected table name');
  $rows=$p->query("SELECT * FROM `$table`")->fetchAll();
  usort($rows,fn($a,$b)=>strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR)));
  $hashes[$table]=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
 }
 echo json_encode($hashes,JSON_THROW_ON_ERROR);exit;
}
if($phase!=='verify')throw new RuntimeException('Unknown phase');
$checks=[];$check=function(string $name,bool $ok)use(&$checks){$checks[]=['check'=>$name,'passed'=>$ok];};
$reject=function(string $name,callable $probe)use($p,$check){$p->beginTransaction();try{$probe();$ok=false;}catch(PDOException){$ok=true;}finally{if($p->inTransaction())$p->rollBack();}$check($name,$ok);};
$keys=[];foreach(['fin_entity_metadata','fin_subscription_evidence'] as $t)foreach($q("SHOW INDEX FROM $t")->fetchAll() as $row){if((int)$row['Non_unique']===0)$keys[$t][$row['Key_name']][]=$row['Column_name'];}
$check('metadata primary key scopes identity by environment and entity kind',($keys['fin_entity_metadata']['PRIMARY']??[])===['environment_id','entity_kind','entity_id']);
$check('evidence primary key scopes event identity by environment',($keys['fin_subscription_evidence']['PRIMARY']??[])===['environment_id','event_id']);
$check('one subscription event per financial operation is enforced',in_array(['environment_id','operation_id'],$keys['fin_subscription_evidence'],true));
$fk=$q("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('fin_entity_metadata','fin_subscription_evidence') AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll();
$check('environment event and price foreign keys exist',count($fk)===5&&count(array_filter($fk,fn($r)=>$r['REFERENCED_TABLE_NAME']==='subscription_events'))===2&&count(array_filter($fk,fn($r)=>$r['REFERENCED_TABLE_NAME']==='plan_prices'))===2);
$decimal=$q("SELECT NUMERIC_PRECISION,NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fin_movements' AND COLUMN_NAME='amount'")->fetch();
$check('original cash amounts retain exact DECIMAL(20,2)',(int)$decimal['NUMERIC_PRECISION']===20&&(int)$decimal['NUMERIC_SCALE']===2&&$q('SELECT SUM(CASE direction WHEN "in" THEN amount ELSE -amount END) FROM fin_movements')->fetchColumn()==='7.50');
$check('migration never fabricates historical metadata or price evidence',(int)$q('SELECT COUNT(*) FROM fin_entity_metadata')->fetchColumn()===0&&(int)$q('SELECT COUNT(*) FROM fin_subscription_evidence')->fetchColumn()===0);
$reject('invalid metadata entity kind is rejected',fn()=>$q('INSERT INTO fin_entity_metadata VALUES(?,?,?,?)',['pruebas','movement','bad','{}']));
$reject('invalid JSON is rejected by MariaDB',fn()=>$q('INSERT INTO fin_entity_metadata VALUES(?,?,?,?)',['pruebas','sale','bad','not-json']));
$reject('metadata cannot reference an unknown environment',fn()=>$q('INSERT INTO fin_entity_metadata VALUES(?,?,?,?)',['missing','sale','bad','{}']));
$reject('unknown event evidence is rejected',fn()=>$q('INSERT INTO fin_subscription_evidence VALUES(?,?,?,?)',['pruebas','missing_event','m17_price','m17_bad_event_operation']));
$reject('unknown price evidence is rejected',fn()=>$q('INSERT INTO fin_subscription_evidence VALUES(?,?,?,?)',['pruebas','m17_old_event','missing_price','m17_bad_price_operation']));
$reject('subscription state constraints remain active',fn()=>$q("UPDATE subscriptions SET status='invented' WHERE id='m17_sub'"));
$before=$q('SELECT COUNT(*) FROM fin_movements')->fetchColumn();
$r=$s->execute('pruebas','m17_post_upgrade_details',1,['kind'=>'sale_edit','sale_id'=>'legacy','expected_version'=>2,'record'=>['notes'=>'Synthetic migration validation']]);
$check('017 unlocks descriptive edits without changing existing cash',$q('SELECT COUNT(*) FROM fin_movements')->fetchColumn()===$before&&$r['result']['cash_effect']===false);
$command=['kind'=>'subscription_renew','subscription_id'=>'m17_sub','plan_id'=>'m17_plan','expected_version'=>1,'date'=>'2026-01-08','price_snapshot_id'=>'m17_price','sale_id'=>'m17_renewal','amount'=>'10.00','currency'=>'USD','settle_now'=>false];
$first=$s->execute('pruebas','m17_post_upgrade_renewal',1,$command);$again=$s->execute('pruebas','m17_post_upgrade_renewal',1,$command);
$check('017 unlocks a prior subscription and idempotent renewal',!$first['replayed']&&$again['replayed']&&$first['result']===$again['result']);
$event=$first['result']['event_id'];
$check('old event remains unchanged without invented evidence',(int)$q("SELECT COUNT(*) FROM fin_subscription_evidence WHERE event_id='m17_old_event'")->fetchColumn()===0&&$q("SELECT note FROM subscription_events WHERE id='m17_old_event'")->fetchColumn()==='Original synthetic event');
$reject('duplicate event evidence is rejected',fn()=>$q('INSERT INTO fin_subscription_evidence VALUES(?,?,?,?)',['pruebas',$event,'m17_price','m17_duplicate_event']));
$reject('duplicate operation evidence is rejected',function()use($q){$q("INSERT INTO subscription_events(environment_id,id,subscription_id,event_date,event_type,plan_id,note) VALUES('pruebas','constraint_probe','m17_sub','2026-01-09','pausa','m17_plan','')");$q('INSERT INTO fin_subscription_evidence VALUES(?,?,?,?)',['pruebas','constraint_probe','m17_price','m17_post_upgrade_renewal']);});
$check('renewal evidence links the exact historical price',$q('SELECT price_snapshot_id FROM fin_subscription_evidence WHERE event_id=?',[$event])->fetchColumn()==='m17_price');
$check('cash unchanged by upgrade and unpaid renewal',$q('SELECT SUM(CASE direction WHEN "in" THEN amount ELSE -amount END) FROM fin_movements')->fetchColumn()==='7.50');
$check('each operation keeps one audit and durable receipt',$q('SELECT COUNT(*) FROM fin_audit')->fetchColumn()===$q('SELECT COUNT(*) FROM fin_operation_receipts')->fetchColumn());
$state=(new Centro\FinancialState($p))->snapshot('pruebas');$check('017 readiness is based on the actual checksum',$state['operationalReady']===true);
// The migration must not silently relax blocked accounting policies.
foreach(['discount','commission','tax'] as $field){
 $before=(new Centro\FinancialState($p))->snapshot('pruebas');$receipts=$q('SELECT COUNT(*) FROM fin_operation_receipts')->fetchColumn();
 try{$s->execute('pruebas','m17_blocked_'.$field.'_operation',1,['kind'=>'sale_register','sale_id'=>'blocked_'.$field,'amount'=>'10.00','currency'=>'USD','date'=>'2026-01-09',$field=>'1.00']);$reason='success';}catch(Centro\BusinessError $e){$reason=$e->reason;}
 $check('nonzero '.$field.' stays blocked without invented cash',$reason==='unsupported_financial_adjustments'&&$before===(new Centro\FinancialState($p))->snapshot('pruebas')&&$receipts===$q('SELECT COUNT(*) FROM fin_operation_receipts')->fetchColumn()&&!$p->inTransaction());
}
foreach(['expense_delete','treasury_delete'] as $kind){
 $before=(new Centro\FinancialState($p))->snapshot('pruebas');$receipts=$q('SELECT COUNT(*) FROM fin_operation_receipts')->fetchColumn();
 try{$s->execute('pruebas','m17_blocked_'.$kind.'_operation',1,['kind'=>$kind]);$reason='success';}catch(Centro\BusinessError $e){$reason=$e->reason;}
 $check($kind.' cannot erase cash or fabricate compensation',$reason==='unsupported_financial_operation'&&$before===(new Centro\FinancialState($p))->snapshot('pruebas')&&$receipts===$q('SELECT COUNT(*) FROM fin_operation_receipts')->fetchColumn()&&!$p->inTransaction());
}
$before=(new Centro\FinancialState($p))->snapshot('pruebas');
try{$s->execute('pruebas','m17_blocked_collected_edit',1,['kind'=>'sale_edit','sale_id'=>'legacy','expected_version'=>3,'amount'=>'12.00']);$reason='success';}catch(Centro\BusinessError $e){$reason=$e->reason;}
$check('collected sale amount remains protected after 017',$reason==='sale_has_collections'&&$before===(new Centro\FinancialState($p))->snapshot('pruebas')&&!$p->inTransaction());

echo json_encode(['checks'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
exit(array_filter($checks,fn($c)=>!$c['passed'])?1:0);
