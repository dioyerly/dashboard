<?php
declare(strict_types=1);
// Sales and collection basics with automatic assertions. Exit code 1 on any failed assertion.
require dirname(__DIR__).'/app/Database.php'; require dirname(__DIR__).'/app/FinancialService.php';
$c=require dirname(__DIR__).'/config/settings.php'; $p=Centro\Database::connection($c['db']);
$p->exec("INSERT INTO auth_users(id,email,password_hash,active) VALUES(1,'test@example.invalid','x',1) ON DUPLICATE KEY UPDATE active=1");
$p->exec("INSERT INTO environments(id,code,label) VALUES('test_sales','pruebas','Sales test') ON DUPLICATE KEY UPDATE label=VALUES(label)");
$p->exec("INSERT INTO currencies(environment_id,id,symbol) VALUES('test_sales','USD','$') ON DUPLICATE KEY UPDATE symbol=VALUES(symbol)");
$p->exec("INSERT INTO accounts(environment_id,id,name,currency_id) VALUES('test_sales','cash1','Cash','USD') ON DUPLICATE KEY UPDATE name=VALUES(name)");
$p->exec("INSERT INTO customers(environment_id,id,name,country,consent,notes,origin) VALUES('test_sales','cust1','Test','AR',1,'','manual') ON DUPLICATE KEY UPDATE name=VALUES(name)");
$s=new Centro\FinancialService($p);$checks=[];
$check=function(string $name,bool $ok,$observed)use(&$checks){$checks[]=['check'=>$name,'passed'=>$ok,'observed'=>$observed];};
$count=fn(string $sql)=>(int)$p->query($sql)->fetchColumn();
$sale=['kind'=>'sale_register','sale_id'=>'sale_test_001','customer_id'=>'cust1','currency'=>'USD','amount'=>'10.00','date'=>'2026-10-16'];
$r=$s->execute('test_sales','op_sale_register_001',1,$sale);
$check('sale_register returns sale id',($r['result']['sale_id']??null)==='sale_test_001',$r['result']['sale_id']??null);
$collect=['kind'=>'sale_collect','sale_id'=>'sale_test_001','amount'=>'10.00','account_id'=>'cash1','confirmed'=>true,'currency'=>'USD','date'=>'2026-10-16','movement_id'=>'mv_sale_001'];
$r=$s->execute('test_sales','op_sale_collect_001',1,$collect);
$check('sale_collect returns movement id',($r['result']['movement_id']??null)==='mv_sale_001',$r['result']['movement_id']??null);
$r2=$s->execute('test_sales','op_sale_collect_001',1,$collect);
$check('identical retry replays receipt',$r2['replayed']===true,$r2['replayed']);
try{$s->execute('test_sales','op_sale_collect_001',1,[...$collect,'amount'=>'9.00','movement_id'=>'mv_other']);$conflict='success';}catch(Centro\BusinessError $e){$conflict=$e->reason;}
$check('changed payload with same operation conflicts',$conflict==='idempotency_conflict',$conflict);
$check('one sale persisted',$count("SELECT COUNT(*) FROM fin_sales WHERE environment_id='test_sales' AND id='sale_test_001'")===1,null);
$check('one movement persisted',$count("SELECT COUNT(*) FROM fin_movements WHERE environment_id='test_sales' AND sale_id='sale_test_001'")===1,null);
$check('one allocation persisted',$count("SELECT COUNT(*) FROM fin_sale_allocations WHERE environment_id='test_sales' AND sale_id='sale_test_001'")===1,null);
$check('two receipts persisted',$count("SELECT COUNT(*) FROM fin_operation_receipts WHERE environment_id='test_sales'")===2,null);
$check('two audit rows persisted',$count("SELECT COUNT(*) FROM fin_audit WHERE environment_id='test_sales'")===2,null);
echo json_encode(['checks'=>$checks],JSON_THROW_ON_ERROR).PHP_EOL;
exit(array_filter($checks,fn($x)=>!$x['passed'])?1:0);
