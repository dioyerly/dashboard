<?php
declare(strict_types=1);
// Advanced sale and collection cases with automatic assertions. Exit code 1 on any failed assertion.
require dirname(__DIR__).'/app/Database.php';require dirname(__DIR__).'/app/FinancialService.php';$c=require dirname(__DIR__).'/config/settings.php';$p=Centro\Database::connection($c['db']);
$p->exec("INSERT INTO auth_users(id,email,password_hash,active) VALUES(1,'advanced@example.invalid','x',1) ON DUPLICATE KEY UPDATE active=1");
$p->exec("INSERT INTO environments(id,code,label) VALUES('adv_sales','pruebas','Advanced')");$p->exec("INSERT INTO currencies(environment_id,id,symbol) VALUES('adv_sales','USD','$')");$p->exec("INSERT INTO accounts(environment_id,id,name,currency_id) VALUES('adv_sales','cash1','Cash','USD')");$p->exec("INSERT INTO customers(environment_id,id,name,country,consent,notes,origin) VALUES('adv_sales','cust1','Customer','AR',1,'','manual')");
$s=new Centro\FinancialService($p);$out=[];$checks=[];
$run=function(string $op,array $cmd,?string $expectReason)use($s,&$out,&$checks){
    $op=str_pad($op,16,'x');
    try{$r=$s->execute('adv_sales',$op,1,$cmd);$observed='success';$out[$op]=['ok'=>true];}
    catch(Throwable $e){$observed=$e instanceof Centro\BusinessError?$e->reason:get_class($e);$out[$op]=['ok'=>false,'error'=>$observed];}
    $checks[]=['check'=>$op,'expected'=>$expectReason??'success','observed'=>$observed,'passed'=>$observed===($expectReason??'success')];};
$run('sale_register_adv_001',['kind'=>'sale_register','sale_id'=>'sale_adv_001','customer_id'=>'cust1','currency'=>'USD','amount'=>'10.00','date'=>'2026-10-16'],null);
$run('sale_register_adv_002',['kind'=>'sale_register','sale_id'=>'sale_adv_002','currency'=>'USD','amount'=>'0.01','date'=>'2026-10-16'],null);
$run('collect_adv_001',['kind'=>'sale_collect','sale_id'=>'sale_adv_001','amount'=>'6.00','account_id'=>'cash1','confirmed'=>true,'currency'=>'USD','date'=>'2026-10-16','movement_id'=>'mv_adv_001'],null);
$run('collect_adv_002',['kind'=>'sale_collect','sale_id'=>'sale_adv_001','amount'=>'4.00','account_id'=>'cash1','confirmed'=>true,'currency'=>'USD','date'=>'2026-10-16','movement_id'=>'mv_adv_002'],null);
$run('collect_adv_over',['kind'=>'sale_collect','sale_id'=>'sale_adv_001','amount'=>'1.00','account_id'=>'cash1','confirmed'=>true,'currency'=>'USD','date'=>'2026-10-16','movement_id'=>'mv_adv_003'],'already_collected');
$run('collect_adv_missing',['kind'=>'sale_collect','sale_id'=>'missing','amount'=>'1.00','account_id'=>'cash1','confirmed'=>true,'currency'=>'USD','date'=>'2026-10-16','movement_id'=>'mv_adv_004'],'reference_not_found');
$run('collect_adv_bad_account',['kind'=>'sale_collect','sale_id'=>'sale_adv_002','amount'=>'0.01','account_id'=>'missing','confirmed'=>true,'currency'=>'USD','date'=>'2026-10-16','movement_id'=>'mv_adv_005'],'account_reference_invalid');
$counts=[];foreach(['fin_sales','fin_movements','fin_sale_allocations','fin_operation_receipts','fin_audit'] as $t)$counts[$t]=(int)$p->query("SELECT COUNT(*) FROM $t WHERE environment_id='adv_sales'")->fetchColumn();
$collected=(string)$p->query("SELECT COALESCE(SUM(amount),0) FROM fin_sale_allocations WHERE environment_id='adv_sales' AND sale_id='sale_adv_001'")->fetchColumn();
foreach(['fin_sales'=>2,'fin_movements'=>2,'fin_sale_allocations'=>2,'fin_operation_receipts'=>4,'fin_audit'=>4] as $t=>$v)$checks[]=['check'=>"count $t",'expected'=>$v,'observed'=>$counts[$t],'passed'=>$counts[$t]===$v];
$checks[]=['check'=>'collected amount','expected'=>'10.00','observed'=>$collected,'passed'=>$collected==='10.00'];
echo json_encode(['results'=>$out,'checks'=>$checks],JSON_THROW_ON_ERROR).PHP_EOL;
exit(array_filter($checks,fn($x)=>!$x['passed'])?1:0);
