<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Database.php';
require dirname(__DIR__).'/app/FinancialService.php';
require dirname(__DIR__).'/app/FinancialState.php';
$c=require dirname(__DIR__).'/config/settings.php';
if($c['environment']!=='local'||$c['db']['host']!=='127.0.0.1'||!preg_match('/\Acentro_auth_[a-z0-9_]+\z/D',$c['db']['name']))throw new RuntimeException('Synthetic schema required');
$p=Centro\Database::connection($c['db']);$checks=[];
$check=function($name,$ok)use(&$checks){$checks[]=['check'=>$name,'passed'=>$ok];};
$p->exec("INSERT INTO auth_users(id,email,password_hash,active) VALUES(1,'compatibility@example.invalid','x',1)");
$p->exec("INSERT INTO environments(id,code,label) VALUES('pruebas','pruebas','Synthetic compatibility')");
$p->exec("INSERT INTO currencies(environment_id,id,symbol) VALUES('pruebas','USD','$')");
$p->exec("INSERT INTO accounts(environment_id,id,name,currency_id) VALUES('pruebas','cash','Cash','USD')");
$state=new Centro\FinancialState($p);$s=new Centro\FinancialService($p);
$before=$state->snapshot('pruebas');
$check('schema 016 remains readable without applying 017',!$before['operationalReady']&&$before['sales']===[]&&$before['subs']===[]);
$kind=['kind'=>'sale_register','sale_id'=>'legacy','amount'=>'10.00','currency'=>'USD','date'=>'2026-10-21'];
$s->execute('pruebas','legacy_sale_00000001',1,$kind);
$before=$state->snapshot('pruebas');$receipts=(int)$p->query('SELECT COUNT(*) FROM fin_operation_receipts')->fetchColumn();
try{$s->execute('pruebas','extended_sale_000001',1,[...$kind,'sale_id'=>'must_rollback','settle_now'=>true,'confirmed'=>true,'account_id'=>'cash','record'=>['concept'=>'Synthetic extended sale']]);$reason='success';}catch(Centro\BusinessError $e){$reason=$e->reason;}
$check('missing migration rejects extended write with full rollback',$reason==='operational_migration_required'&&$before===$state->snapshot('pruebas')&&$receipts===(int)$p->query('SELECT COUNT(*) FROM fin_operation_receipts')->fetchColumn()&&!$p->inTransaction());
try{$s->execute('pruebas','extended_sub_0000001',1,['kind'=>'subscription_register','subscription_id'=>'unmigrated','expected_version'=>0,'date'=>'2026-10-21']);$reason='success';}catch(Centro\BusinessError $e){$reason=$e->reason;}
$check('subscription writes require 017 without changing the old schema',$reason==='operational_migration_required'&&(int)$p->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn()===0&&(int)$p->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===16);
echo json_encode(['checks'=>$checks],JSON_THROW_ON_ERROR).PHP_EOL;
exit(array_filter($checks,fn($x)=>!$x['passed'])?1:0);
