<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/Business.php';
if (PHP_SAPI!=='cli' || $config['db']['host']!=='127.0.0.1' || !str_starts_with($config['db']['name'],'centro_auth_')) exit(1);
$pdo=Centro\Database::connection($config['db']); $s=new Centro\Business($pdo);$checks=[];
function check(string $name,bool $ok):void {global $checks;$checks[]=['check'=>$name,'passed'=>$ok];if(!$ok)throw new RuntimeException($name);}
function command(string $resource,string $id,array $data=[],string $action='create',int $version=0):array {return ['resource'=>$resource,'id'=>$id,'action'=>$action,'expected_version'=>$version,'data'=>$data];}
function run(array $changes,?string $token=null,string $env='test'):array {global $s;return $s->command($env,$token??bin2hex(random_bytes(16)),1,['changes'=>$changes]);}
function fails(callable $fn,string $code):bool {try{$fn();return false;}catch(Centro\BusinessError $e){return $e->reason===$code;}}
$c=$pdo->prepare('INSERT INTO environments(id,code,label) VALUES (?,?,?)');$c->execute(['test','pruebas','Synthetic test']);$c->execute(['prod','produccion','Synthetic production']);
check('Ten normalized business tables created', (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('auth_users','auth_login_limits','schema_migrations','environments','currencies','brands','channels','projects','customers','accounts','business_settings','business_operations','business_audit')")->fetchColumn()===13);
$batch=[command('currencies','ARS',['symbol'=>'$']),command('brands','br_test',['name'=>'Synthetic brand','color'=>'#123456','dark'=>'#234567','alt'=>'#345678']),command('projects','pj_test',['name'=>'Synthetic project','brand_id'=>'br_test','kind'=>'interna','status'=>'idea','initial_investment'=>'123.45','investment_currency'=>'ARS']),command('accounts','ac_test',['name'=>'Synthetic account','currency_id'=>'ARS']),command('business_settings','main',['name'=>'Synthetic business','base_currency'=>'ARS','num_format'=>'es'])];
$token=bin2hex(random_bytes(16));$first=run($batch,$token);
check('Compound create commits settings, currency, brand, project and account',count($first['result'])===5);
check('Money round-trips as exact decimal string',$first['result'][2]['record']['initial_investment']==='123.45');
$again=run($batch,$token);check('Same token and payload replay exact original result',$again['replayed'] && $again['result']===$first['result']);
check('Idempotent replay creates one receipt and audit', (int)$pdo->query('SELECT COUNT(*) FROM business_operations')->fetchColumn()===1 && (int)$pdo->query('SELECT COUNT(*) FROM business_audit')->fetchColumn()===1);
check('Reusing token for another command conflicts',fails(fn()=>run([command('currencies','USD',['symbol'=>'US$'])],$token),'idempotency_conflict'));
check('Actor permission enforced',fails(fn()=>$s->command('test',bin2hex(random_bytes(16)),2,['changes'=>$batch]),'forbidden'));
check('Invalid FK rejected and earlier batch changes rolled back',fails(fn()=>run([command('currencies','EUR',['symbol'=>'E']),command('accounts','bad',['name'=>'Invalid account','currency_id'=>'XXX'])]),'invalid_reference') && count($s->list('test','currencies'))===1);
check('Cross-environment FK refused',fails(fn()=>run([command('accounts','cross',['name'=>'Cross','currency_id'=>'ARS'])],null,'prod'),'invalid_reference'));
check('Malformed money rejected',fails(fn()=>run([command('projects','bad',['name'=>'Bad money','brand_id'=>'br_test','kind'=>'interna','status'=>'idea','initial_investment'=>'1e3','investment_currency'=>'ARS'])]),'invalid_decimal'));
check('Float money rejected instead of rounded silently',fails(fn()=>run([command('projects','bad',['name'=>'Bad float','brand_id'=>'br_test','kind'=>'interna','status'=>'idea','initial_investment'=>1.23,'investment_currency'=>'ARS'])]),'invalid_field'));
check('Unknown fields rejected',fails(fn()=>run([command('customers','c_bad',['name'=>'Bad','origin'=>'manual','admin'=>1])]),'unknown_field'));
check('Invalid date rejected',fails(fn()=>run([command('customers','c_bad',['name'=>'Bad','origin'=>'manual','business_date'=>'2026-02-30'])]),'invalid_date'));
check('SQL identifier injection rejected',fails(fn()=>run([command('brands;DROP TABLE brands','x')]),'unknown_resource'));
check('Child reference blocks parent archive',fails(fn()=>run([command('brands','br_test',[],'archive',1)]),'entity_in_use'));
$snapshot=$s->list('test','projects')[0];run([command('projects','pj_test',['name'=>'Edited project'],'update',1)]);
check('Stale editor detects version conflict',fails(fn()=>run([command('projects','pj_test',['name'=>'Stale'],'update',(int)$snapshot['row_version'])]),'version_conflict'));
$pdo2=Centro\Database::connection($config['db']);$s2=new Centro\Business($pdo2);
check('Second independent connection reads committed edit',$s2->list('test','projects')[0]['name']==='Edited project');
$fault=bin2hex(random_bytes(16));
try {$s->atomic('test',$fault,1,['fault'=>'after_insert'],function()use($pdo){$pdo->exec("INSERT INTO currencies(environment_id,id,symbol) VALUES('test','GBP','G')");throw new RuntimeException('Injected interruption');});}catch(RuntimeException $e){check('Injected exception rolls back transaction',$e->getMessage()==='Injected interruption' && !$pdo->inTransaction());}
check('Fault leaves neither row, receipt nor audit',(int)$pdo->query("SELECT COUNT(*) FROM currencies WHERE id='GBP'")->fetchColumn()===0 && (int)$pdo->query("SELECT COUNT(*) FROM business_operations WHERE operation_id='$fault'")->fetchColumn()===0 && (int)$pdo->query("SELECT COUNT(*) FROM business_audit WHERE operation_id='$fault'")->fetchColumn()===0);
run([command('customers','cu_1',['name'=>'Customer','email'=>'TEST@ejemplo.test','origin'=>'manual'])]);
check('Email deduplicated on server',fails(fn()=>run([command('customers','cu_2',['name'=>'Duplicate','email'=>'test@ejemplo.test','origin'=>'manual'])]),'integrity_conflict'));
run([command('customers','cu_1',[],'archive',1)]);
check('Archive hides active row but preserves identity and version',count($s->list('test','customers'))===0 && $s->list('test','customers',true)[0]['row_version']==2);
check('Archived IDs cannot be reused',fails(fn()=>run([command('customers','cu_1',['name'=>'Reuse','origin'=>'manual'])]),'version_conflict'));
try{$pdo->exec("DELETE FROM brands WHERE environment_id='test' AND id='br_test'");check('SQL FK restricts historical deletion',false);}catch(PDOException){check('SQL FK restricts historical deletion',true);}
try{$pdo->exec("INSERT INTO accounts(environment_id,id,name,currency_id) VALUES('prod','illegal','Invalid','ARS')");check('SQL FK includes environment',false);}catch(PDOException){check('SQL FK includes environment',true);}
check('Only foundation collections available',fails(fn()=>$s->list('test','sales'),'unknown_resource'));
run([command('channels','ch_test',['name'=>'Synthetic channel','commission_pct'=>'2.123456'])]);
check('Commission preserves all six decimal places',$s->list('test','channels')[0]['commission_pct']==='2.123456');
check('Commission at 100 percent rejected',fails(fn()=>run([command('channels','ch_bad',['name'=>'Bad commission','commission_pct'=>'100'])]),'invalid_decimal'));
run([command('channels','ch_test',['commission_pct'=>'3.500000'],'update',1)]);
run([command('channels','ch_test',[],'archive',2)]);
check('Channel create/read/update/archive retains exact value and revision',count($s->list('test','channels'))===0 && $s->list('test','channels',true)[0]['commission_pct']==='3.500000' && (int)$s->list('test','channels',true)[0]['row_version']===3);
check('Oversized batch rejected before writing',fails(fn()=>run(array_fill(0,51,command('currencies','USD',['symbol'=>'US$']))),'invalid_command'));
$fault=bin2hex(random_bytes(16));
$pdo->exec("CREATE TRIGGER foundation_audit_failure BEFORE INSERT ON business_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected audit failure'");
try {
    try {$s->atomic('test',$fault,1,['fault'=>'after_receipt'],function()use($pdo){$pdo->exec("INSERT INTO currencies(environment_id,id,symbol) VALUES('test','CHF','F')");return ['synthetic'=>true];});check('Injected audit failure propagated',false);}
    catch(PDOException $e){check('Injected audit failure propagated',$e->getCode()==='45000');}
} finally {$pdo->exec('DROP TRIGGER foundation_audit_failure');}
check('Audit failure rolls back entity and already-inserted operation receipt',(int)$pdo->query("SELECT COUNT(*) FROM currencies WHERE id='CHF'")->fetchColumn()===0 && (int)$pdo->query("SELECT COUNT(*) FROM business_operations WHERE operation_id='$fault'")->fetchColumn()===0 && !$pdo->inTransaction());
echo json_encode(['checks'=>$checks],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
