<?php
declare(strict_types=1);
// Block 5.3F: real concurrency between independent PHP processes (own PDO connections, start barrier),
// controlled rollback and recovery. Requires a FRESH synthetic database (empty environments table).
// Optional: CENTRO_53F_WORKER_USER / CENTRO_53F_WORKER_PASSWORD make the workers run as a restricted synthetic user.
// Exit code 1 on any failed assertion.
require dirname(__DIR__).'/app/Database.php';require dirname(__DIR__).'/app/FinancialService.php';
$c=require dirname(__DIR__).'/config/settings.php';
if($c['environment']!=='local'||$c['db']['host']!=='127.0.0.1'||!preg_match('/\Acentro_auth_[a-z0-9_]+\z/D',$c['db']['name']))throw new RuntimeException('Local synthetic database required');
$p=Centro\Database::connection($c['db']);
if((int)$p->query('SELECT COUNT(*) FROM environments')->fetchColumn()!==0)throw new RuntimeException('Fresh synthetic database required');
$p->exec("INSERT INTO auth_users(id,email,password_hash,active) VALUES(1,'concurrency@example.invalid','x',1) ON DUPLICATE KEY UPDATE active=1");
$p->exec("INSERT INTO environments(id,code,label) VALUES('a1','pruebas','A'),('b1','produccion','B')");
$p->exec("INSERT INTO currencies(environment_id,id,symbol) VALUES('a1','USD','$'),('b1','USD','$')");
$p->exec("INSERT INTO accounts(environment_id,id,name,currency_id) VALUES('a1','cash','Cash','USD'),('a1','bank','Bank','USD'),('b1','bank_b','Bank B','USD')");
$p->exec("INSERT INTO customers(environment_id,id,name,country,consent,notes,origin) VALUES('a1','c1','Client','AR',1,'','manual')");
$s=new Centro\FinancialService($p);
$php=PHP_BINARY;$ext=(string)ini_get('extension_dir');
$worker=__DIR__.DIRECTORY_SEPARATOR.'financial_concurrency_worker_53f.php';
$baseEnv=getenv();
$wUser=getenv('CENTRO_53F_WORKER_USER')?:null;$wPwd=getenv('CENTRO_53F_WORKER_PASSWORD')?:null;
$checks=[];
$check=function(string $name,bool $passed,string $expected,$observed)use(&$checks){$checks[]=['check'=>$name,'passed'=>$passed,'expected'=>$expected,'observed'=>$observed];};
$op=fn(string $name)=>str_pad('c53f_'.$name,16,'x');
$wx=fn(array $ops)=>['kind'=>'exec','ops'=>$ops];
$val=function(string $sql,array $a=[])use($p){$x=$p->prepare($sql);$x->execute($a);return $x->fetchColumn();};
$alloc=fn(string $sale)=>(string)$val("SELECT COALESCE(SUM(amount),0) FROM fin_sale_allocations WHERE environment_id='a1' AND sale_id=?",[$sale]);
$moves=fn(string $sale)=>(int)$val("SELECT COUNT(*) FROM fin_movements WHERE environment_id='a1' AND sale_id=?",[$sale]);
$rcpt=fn(string $o)=>(int)$val("SELECT COUNT(*) FROM fin_operation_receipts WHERE environment_id='a1' AND operation_id=?",[$o]);
$aud=fn(string $o)=>(int)$val("SELECT COUNT(*) FROM fin_audit WHERE environment_id='a1' AND operation_id=?",[$o]);
$legs=fn(string $ref,string $dir)=>(int)$val("SELECT COUNT(*) FROM fin_movements WHERE environment_id='a1' AND kind='transfer' AND reference_id=? AND direction=?",[$ref,$dir]);
$expRow=function(string $id)use($p){$x=$p->prepare("SELECT status,row_version FROM fin_expenses WHERE environment_id='a1' AND id=?");$x->execute([$id]);$r=$x->fetch(PDO::FETCH_ASSOC);return ['status'=>$r['status'],'row_version'=>(int)$r['row_version']];};
$expAlloc=fn(string $id)=>(string)$val("SELECT COALESCE(SUM(amount),0) FROM fin_expense_allocations WHERE environment_id='a1' AND expense_id=?",[$id]);
$collect=fn(string $sale,string $amount,string $mv)=>['kind'=>'sale_collect','sale_id'=>$sale,'amount'=>$amount,'account_id'=>'cash','currency'=>'USD','date'=>'2026-10-16','confirmed'=>true,'movement_id'=>$mv];
$pay=fn(string $exp,string $amount,string $mv,?int $ver=null)=>['kind'=>'expense_pay','expense_id'=>$exp,'amount'=>$amount,'account_id'=>'cash','currency'=>'USD','date'=>'2026-10-16','confirmed'=>true,'movement_id'=>$mv,...($ver===null?[]:['expected_version'=>$ver])];
$transfer=fn(string $ref,string $amount)=>['kind'=>'transfer','account_id'=>'cash','currency'=>'USD','amount'=>$amount,'destination_account_id'=>'bank','destination_currency'=>'USD','destination_amount'=>$amount,'date'=>'2026-10-16','confirmed'=>true,'reference_id'=>$ref];
$mkSale=function(string $id,string $amount)use($s,$op){$s->execute('a1',$op('setup_'.$id),1,['kind'=>'sale_register','sale_id'=>$id,'customer_id'=>'c1','currency'=>'USD','amount'=>$amount,'date'=>'2026-10-16']);};
$mkExp=function(string $id,string $amount)use($s,$op){$s->execute('a1',$op('setup_'.$id),1,['kind'=>'expense_register','expense_id'=>$id,'currency'=>'USD','amount'=>$amount,'date'=>'2026-10-16']);};
// Spawns one process per worker, releases them together through a start barrier and collects their results.
$timings=[];$race=function(array $workers)use($php,$ext,$worker,$baseEnv,$wUser,$wPwd,&$timings):array{
    $dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'c53f_'.bin2hex(random_bytes(6));
    if(!mkdir($dir,0700))throw new RuntimeException('race directory');
    $env=$baseEnv;if($wUser!==null){$env['CENTRO_DB_USER']=$wUser;$env['CENTRO_DB_PASSWORD']=$wPwd;}
    $procs=[];
    foreach($workers as $i=>$w){
        $w['index']=$i;file_put_contents($dir.DIRECTORY_SEPARATOR."spec_$i.json",json_encode($w,JSON_THROW_ON_ERROR));
        $procs[$i]=proc_open([$php,'-n','-d','extension_dir='.$ext,'-d','extension=pdo_mysql',$worker,$dir.DIRECTORY_SEPARATOR."spec_$i.json"],
            [1=>['file',$dir.DIRECTORY_SEPARATOR."out_$i.txt",'w'],2=>['file',$dir.DIRECTORY_SEPARATOR."err_$i.txt",'w']],$pipes,null,$env,['bypass_shell'=>true]);
        if(!is_resource($procs[$i]))throw new RuntimeException('worker spawn failed');
    }
    $t=microtime(true);
    while(count(glob($dir.DIRECTORY_SEPARATOR.'ready_*')?:[])<count($workers)){if(microtime(true)-$t>30)throw new RuntimeException('barrier timeout');usleep(2000);}
    touch($dir.DIRECTORY_SEPARATOR.'go');
    $codes=[];foreach($procs as $i=>$pr)$codes[$i]=proc_close($pr);
    $res=[];
    foreach($workers as $i=>$w){$f=$dir.DIRECTORY_SEPARATOR."result_$i.json";
        $res[$i]=is_file($f)?json_decode((string)file_get_contents($f),true,64,JSON_THROW_ON_ERROR):['crashed'=>true,'exit'=>$codes[$i],'err'=>substr((string)@file_get_contents($dir.DIRECTORY_SEPARATOR."err_$i.txt"),0,300)];}
    foreach(glob($dir.DIRECTORY_SEPARATOR.'*')?:[] as $f)@unlink($f);@rmdir($dir);
    $timings[]=array_map(fn($w)=>array_column($w['results']??[],'finished_ms'),$res);
    return $res;
};
$rows=function(array $res){$o=[];foreach($res as $w){foreach($w['results']??[] as $r)$o[]=$r;}return $o;};
$oks=fn(array $r)=>count(array_filter($r,fn($x)=>$x['ok']));
$reasons=fn(array $r)=>array_values(array_column(array_filter($r,fn($x)=>!$x['ok']),'reason'));
$replays=fn(array $r)=>count(array_filter($r,fn($x)=>$x['ok']&&($x['replayed']??false)===true));
$anyOpen=fn(array $r)=>count(array_filter($r,fn($x)=>$x['open']??false));
$crashed=fn(array $res)=>count(array_filter($res,fn($w)=>isset($w['crashed'])));
$linked=function(array $r)use($rcpt,$aud){foreach($r as $x){$n=$rcpt($x['op']);$a=$aud($x['op']);if($x['ok']?($n!==1||$a!==1):($n!==0||$a!==0))return false;}return true;};
$countReason=fn(array $reasonList,string $reason)=>count(array_filter($reasonList,fn($x)=>$x===$reason));

// ---------- Sales and collections ----------
$mkSale('sa1','100.00');
$r=$rows($race([$wx([['op'=>$op('sa1_a_1'),'cmd'=>$collect('sa1','70.00','sa1_mvA')]]),$wx([['op'=>$op('sa1_b_1'),'cmd'=>$collect('sa1','70.00','sa1_mvB')]])]));
$check('SA1 two 70.00 collections on a 100.00 sale: one succeeds, one over_collection',$oks($r)===1&&$countReason($reasons($r),'over_collection')===1,'1 success + over_collection',['ok'=>$oks($r),'reasons'=>$reasons($r)]);
$check('SA1 allocations equal the only successful collection',$alloc('sa1')==='70.00'&&$moves('sa1')===1,'70.00 and 1 movement',['alloc'=>$alloc('sa1'),'moves'=>$moves('sa1')]);
$check('SA1 receipts and audit linked to successful operation only',$linked($r),'linked',null);
$check('SA1 no open transaction in any worker',$anyOpen($r)===0,'0 open',$anyOpen($r));

$mkSale('sa2','100.00');
$r=$rows($race([$wx([['op'=>$op('sa2_a_1'),'cmd'=>$collect('sa2','40.00','sa2_mvA')]]),$wx([['op'=>$op('sa2_b_1'),'cmd'=>$collect('sa2','60.00','sa2_mvB')]])]));
$check('SA2 partial collections 40.00 + 60.00 both succeed',$oks($r)===2,'2 successes',$oks($r));
$check('SA2 sale fully collected, no pending balance, two movements',$alloc('sa2')==='100.00'&&$moves('sa2')===2&&(string)$val("SELECT 100.00-COALESCE(SUM(amount),0) FROM fin_sale_allocations WHERE environment_id='a1' AND sale_id='sa2'")==='0.00',"100.00 / 2 movements / pending 0.00",['alloc'=>$alloc('sa2'),'moves'=>$moves('sa2')]);
$check('SA2 receipts and audit linked',$linked($r),'linked',null);

$mkSale('sa3','100.00');
$r=$rows($race([$wx([['op'=>$op('sa3_same_1'),'cmd'=>$collect('sa3','30.00','sa3_mv')]]),$wx([['op'=>$op('sa3_same_1'),'cmd'=>$collect('sa3','30.00','sa3_mv')]])]));
$check('SA3 identical operation twice: one executes, one replays',$oks($r)===2&&$replays($r)===1,'2 success, 1 replay',['ok'=>$oks($r),'replays'=>$replays($r)]);
$check('SA3 single movement and allocation for the identical operation',$moves('sa3')===1&&$alloc('sa3')==='30.00',"1 movement, 30.00",['moves'=>$moves('sa3'),'alloc'=>$alloc('sa3')]);
$check('SA3 one receipt and one audit row for the operation',$rcpt($op('sa3_same_1'))===1&&$aud($op('sa3_same_1'))===1,'1 and 1',null);

$mkSale('sa4','100.00');
$r=$rows($race([$wx([['op'=>$op('sa4_same_1'),'cmd'=>$collect('sa4','40.00','sa4_mvA')]]),$wx([['op'=>$op('sa4_same_1'),'cmd'=>$collect('sa4','30.00','sa4_mvB')]])]));
$check('SA4 same operation with different payloads: one success, one idempotency_conflict',$oks($r)===1&&$countReason($reasons($r),'idempotency_conflict')===1,'1 success + conflict',['ok'=>$oks($r),'reasons'=>$reasons($r)]);
$check('SA4 persisted amount equals the successful payload only',$moves('sa4')===1&&in_array($alloc('sa4'),['40.00','30.00'],true),'one movement, amount of the winner',['moves'=>$moves('sa4'),'alloc'=>$alloc('sa4')]);

$mkSale('sa5','100.00');
$writes=[];for($k=1;$k<=5;$k++)$writes[]=['op'=>$op("sa5_w_$k"),'cmd'=>$collect('sa5','10.00',"sa5_mv$k")];
$r=$race([$wx($writes),['kind'=>'read','sale'=>'sa5','n'=>40]]);
$pairs=$r[1]['pairs']??[];$inconsistent=count(array_filter($pairs,fn($x)=>$x[0]!==$x[1]));
$check('SA5 reader snapshots always agree with writer (allocations == receipt movements)',count($pairs)===40&&$inconsistent===0,'40 snapshots, 0 inconsistent',['snapshots'=>count($pairs),'inconsistent'=>$inconsistent]);
$check('SA5 five sequential collections all succeed while reader runs',$oks($rows([$r[0]]))===5,'5 successes',$oks($rows([$r[0]])));
$check('SA5 final allocation 50.00 and pending 50.00',$alloc('sa5')==='50.00',"50.00",$alloc('sa5'));

$mkSale('sa6','50.00');
$s->execute('a1',$op('sa6_full_1'),1,$collect('sa6','50.00','sa6_full'));
$r=$rows($race([$wx([['op'=>$op('sa6_x_1'),'cmd'=>$collect('sa6','10.00','sa6_mvA')]]),$wx([['op'=>$op('sa6_y_1'),'cmd'=>$collect('sa6','10.00','sa6_mvB')]])]));
$check('SA6 concurrent collections on a fully collected sale are rejected',$oks($r)===0&&$countReason($reasons($r),'already_collected')===2,'2 already_collected',['ok'=>$oks($r),'reasons'=>$reasons($r)]);
$check('SA6 no movement or allocation added',$moves('sa6')===1&&$alloc('sa6')==='50.00',"1 movement, 50.00",['moves'=>$moves('sa6'),'alloc'=>$alloc('sa6')]);

// ---------- Expenses ----------
$mkExp('ex1','100.00');
$r=$rows($race([$wx([['op'=>$op('ex1_a_1'),'cmd'=>$pay('ex1','70.00','ex1_mvA')]]),$wx([['op'=>$op('ex1_b_1'),'cmd'=>$pay('ex1','70.00','ex1_mvB')]])]));
$check('EX1 two 70.00 payments on a 100.00 expense: one succeeds, one over_payment',$oks($r)===1&&$countReason($reasons($r),'over_payment')===1,'1 success + over_payment',['ok'=>$oks($r),'reasons'=>$reasons($r)]);
$check('EX1 status pendiente and row_version 2 after the single payment',$expRow('ex1')===['status'=>'pendiente','row_version'=>2]&&$expAlloc('ex1')==='70.00',"pendiente/2, 70.00",['row'=>$expRow('ex1'),'alloc'=>$expAlloc('ex1')]);

$mkExp('ex2','100.00');
$r=$rows($race([$wx([['op'=>$op('ex2_a_1'),'cmd'=>$pay('ex2','40.00','ex2_mvA')]]),$wx([['op'=>$op('ex2_b_1'),'cmd'=>$pay('ex2','40.00','ex2_mvB')]]),$wx([['op'=>$op('ex2_c_1'),'cmd'=>$pay('ex2','40.00','ex2_mvC')]])]));
$check('EX2 three 40.00 payments on 100.00: two succeed, one over_payment',$oks($r)===2&&$countReason($reasons($r),'over_payment')===1,'2 success + over_payment',['ok'=>$oks($r),'reasons'=>$reasons($r)]);
$check('EX2 allocations 80.00, row_version 3, never above total',$expAlloc('ex2')==='80.00'&&$expRow('ex2')['row_version']===3,'80.00 / version 3',['alloc'=>$expAlloc('ex2'),'row'=>$expRow('ex2')]);

$mkExp('ex3','100.00');
$r=$rows($race([$wx([['op'=>$op('ex3_a_1'),'cmd'=>$pay('ex3','10.00','ex3_mvA',1)]]),$wx([['op'=>$op('ex3_b_1'),'cmd'=>$pay('ex3','10.00','ex3_mvB',1)]])]));
$check('EX3 two payments with expected_version 1: one succeeds, one version_conflict',$oks($r)===1&&$countReason($reasons($r),'version_conflict')===1,'1 success + version_conflict',['ok'=>$oks($r),'reasons'=>$reasons($r)]);
$check('EX3 row_version advanced once and one allocation persisted',$expRow('ex3')['row_version']===2&&$expAlloc('ex3')==='10.00',"version 2 / 10.00",['row'=>$expRow('ex3'),'alloc'=>$expAlloc('ex3')]);

$mkExp('ex4','100.00');
$r=$rows($race([$wx([['op'=>$op('ex4_same_1'),'cmd'=>$pay('ex4','10.00','ex4_mv')]]),$wx([['op'=>$op('ex4_same_1'),'cmd'=>$pay('ex4','10.00','ex4_mv')]])]));
$check('EX4 identical retries of the same payment: one executes, one replays',$oks($r)===2&&$replays($r)===1,'2 success, 1 replay',['ok'=>$oks($r),'replays'=>$replays($r)]);
$check('EX4 single allocation and row_version 2',$expAlloc('ex4')==='10.00'&&$expRow('ex4')['row_version']===2&&(int)$val("SELECT COUNT(*) FROM fin_expense_allocations WHERE environment_id='a1' AND expense_id='ex4'")===1,'10.00 / 1 row / version 2',null);

$mkExp('ex5','100.00');
$r=$rows($race([$wx([['op'=>$op('ex5_a_1'),'cmd'=>$pay('ex5','50.00','ex5_mvA')]]),$wx([['op'=>$op('ex5_b_1'),'cmd'=>$pay('ex5','50.00','ex5_mvB')]])]));
$check('EX5 two 50.00 payments complete a 100.00 expense: both succeed',$oks($r)===2,'2 successes',$oks($r));
$check('EX5 final status pagado, row_version 3, pending 0.00',$expRow('ex5')===['status'=>'pagado','row_version'=>3]&&$expAlloc('ex5')==='100.00',"pagado/3, 100.00",['row'=>$expRow('ex5'),'alloc'=>$expAlloc('ex5')]);
$check('EX5 receipts and audit linked',$linked($r),'linked',null);
$r=$rows($race([$wx([['op'=>$op('ex5_c_1'),'cmd'=>$pay('ex5','1.00','ex5_mvC')]]),$wx([['op'=>$op('ex5_d_1'),'cmd'=>$pay('ex5','1.00','ex5_mvD')]])]));
$check('EX6 payments on a paid expense are rejected (expense_state_conflict)',$oks($r)===0&&$countReason($reasons($r),'expense_state_conflict')===2,'2 expense_state_conflict',['ok'=>$oks($r),'reasons'=>$reasons($r)]);
$check('EX6 paid expense unchanged',$expRow('ex5')===['status'=>'pagado','row_version'=>3]&&$expAlloc('ex5')==='100.00',"pagado/3, 100.00",null);

// ---------- Transfers ----------
$r=$rows($race([$wx([['op'=>$op('tr1_a_1'),'cmd'=>$transfer('tr1a','10.00')]]),$wx([['op'=>$op('tr1_b_1'),'cmd'=>$transfer('tr1b','10.00')]])]));
$check('TR1 two concurrent transfers between two accounts both succeed',$oks($r)===2,'2 successes',$oks($r));
$check('TR1 each transfer has exactly one outgoing and one incoming leg',$legs('tr1a','out')===1&&$legs('tr1a','in')===1&&$legs('tr1b','out')===1&&$legs('tr1b','in')===1,'1 out + 1 in each',null);
$check('TR1 receipts and audit linked',$linked($r),'linked',null);

$r=$rows($race([$wx([['op'=>$op('tr2_same_1'),'cmd'=>$transfer('tr2','10.00')]]),$wx([['op'=>$op('tr2_same_1'),'cmd'=>$transfer('tr2','10.00')]])]));
$check('TR2 identical transfer twice: one executes, one replays',$oks($r)===2&&$replays($r)===1,'2 success, 1 replay',['ok'=>$oks($r),'replays'=>$replays($r)]);
$check('TR2 exactly one outgoing and one incoming leg',$legs('tr2','out')===1&&$legs('tr2','in')===1,'1 out + 1 in',null);

$r=$rows($race([$wx([['op'=>$op('tr3_a_1'),'cmd'=>$transfer('tr3','10.00')]]),$wx([['op'=>$op('tr3_b_1'),'cmd'=>$transfer('tr3','15.00')]])]));
$check('TR3 same reference, different operations: one succeeds, one integrity_conflict',$oks($r)===1&&$countReason($reasons($r),'integrity_conflict')===1,'1 success + integrity_conflict',['ok'=>$oks($r),'reasons'=>$reasons($r)]);
$check('TR3 reference keeps exactly one out and one in leg',$legs('tr3','out')===1&&$legs('tr3','in')===1,'1 out + 1 in',null);
$check('TR4 no transfer reference anywhere with a single leg',(int)$val("SELECT COUNT(*) FROM (SELECT reference_id FROM fin_movements WHERE environment_id='a1' AND kind='transfer' GROUP BY reference_id HAVING SUM(direction='out')<>1 OR SUM(direction='in')<>1) x")===0,'0 broken references',null);

// ---------- Rollback and recovery (controlled, sequential) ----------
// RB1: failure between the two transfer legs (second leg collides with a stray incoming leg).
$p->exec("INSERT INTO fin_movements(environment_id,id,account_id,kind,direction,amount,currency_id,movement_on,reference_id) VALUES('a1','stray_rb1','bank','transfer','in',10.00,'USD','2026-10-16','rb1')");
$r=$rows($race([$wx([['op'=>$op('rb1_op_1'),'cmd'=>$transfer('rb1','10.00')]])]));
$check('RB1 failure between legs is reported as integrity_conflict',$oks($r)===0&&$countReason($reasons($r),'integrity_conflict')===1,'integrity_conflict',$reasons($r));
$check('RB1 first (outgoing) leg rolled back',$legs('rb1','out')===0&&$rcpt($op('rb1_op_1'))===0,'0 out legs, 0 receipts',['out'=>$legs('rb1','out')]);
$p->exec("DELETE FROM fin_movements WHERE environment_id='a1' AND id='stray_rb1'");
$r=$rows($race([$wx([['op'=>$op('rb1_op_1'),'cmd'=>$transfer('rb1','10.00')]])]));
$check('RB2 retry after rollback and removal of the blocker succeeds with two legs',$oks($r)===1&&$legs('rb1','out')===1&&$legs('rb1','in')===1,'success, 1 out + 1 in',$oks($r));

// RB3: failure before the receipt (movement id already used by another sale).
$mkSale('rb3b','100.00');$mkSale('rb3','100.00');
$s->execute('a1',$op('rb3b_pay_1'),1,$collect('rb3b','10.00','rb3_mvdup'));
$r=$rows($race([$wx([['op'=>$op('rb3_op_1'),'cmd'=>$collect('rb3','10.00','rb3_mvdup')]])]));
$check('RB3 movement id collision is reported as integrity_conflict',$oks($r)===0&&$countReason($reasons($r),'integrity_conflict')===1,'integrity_conflict',$reasons($r));
$check('RB3 no allocation, movement or receipt left behind',in_array($alloc('rb3'),['0','0.00'],true),'0 allocation',['alloc'=>$alloc('rb3')]);
$check('RB3 no movement for the failed sale',$moves('rb3')===0&&$rcpt($op('rb3_op_1'))===0,'0 movements, 0 receipts',['moves'=>$moves('rb3')]);
$r=$rows($race([$wx([['op'=>$op('rb3_op_2'),'cmd'=>$collect('rb3','10.00','rb3_mv2')]])]));
$check('RB3 retry with a fresh movement id succeeds',$oks($r)===1&&$moves('rb3')===1,'success, 1 movement',$oks($r));

// RB4: failure after the receipt, before the audit row is written (pre-existing audit row for the same operation).
$mkSale('rb4','100.00');
$p->exec("INSERT INTO fin_audit(environment_id,operation_id,actor_user_id,event,payload_json) VALUES('a1','c53f_rb4_op_000001',1,'sale_collect','{}')");
$r=$rows($race([$wx([['op'=>'c53f_rb4_op_000001','cmd'=>$collect('rb4','10.00','rb4_mv')]])]));
$check('RB4 audit collision is reported as integrity_conflict',$oks($r)===0&&$countReason($reasons($r),'integrity_conflict')===1,'integrity_conflict',$reasons($r));
$check('RB4 receipt rolled back together with movement and allocation',$rcpt('c53f_rb4_op_000001')===0&&$moves('rb4')===0&&in_array($alloc('rb4'),['0','0.00'],true),"0 receipts, 0 movements",null);
$p->exec("DELETE FROM fin_audit WHERE environment_id='a1' AND operation_id='c53f_rb4_op_000001'");
$r=$rows($race([$wx([['op'=>'c53f_rb4_op_000001','cmd'=>$collect('rb4','10.00','rb4_mv')]])]));
$check('RB4 retry after rollback succeeds with one receipt and one audit row',$oks($r)===1&&$rcpt('c53f_rb4_op_000001')===1&&$aud('c53f_rb4_op_000001')===1,'success, 1 receipt, 1 audit',$oks($r));

// RB5: lost response after commit. A new process repeats the committed operation and must get the stored receipt.
$movesBefore=$moves('rb4');
$r=$rows($race([$wx([['op'=>'c53f_rb4_op_000001','cmd'=>$collect('rb4','10.00','rb4_mv')]])]));
$check('RB5 lost-response retry returns the stored receipt without new movements',$oks($r)===1&&$replays($r)===1&&$moves('rb4')===$movesBefore,'replay, no new movement',['replays'=>$replays($r),'moves'=>$moves('rb4')]);

$final=['fin_sales'=>(int)$val("SELECT COUNT(*) FROM fin_sales WHERE environment_id='a1'"),'fin_movements'=>(int)$val("SELECT COUNT(*) FROM fin_movements WHERE environment_id='a1'"),'fin_expenses'=>(int)$val("SELECT COUNT(*) FROM fin_expenses WHERE environment_id='a1'"),'fin_operation_receipts'=>(int)$val("SELECT COUNT(*) FROM fin_operation_receipts WHERE environment_id='a1'"),'fin_audit'=>(int)$val("SELECT COUNT(*) FROM fin_audit WHERE environment_id='a1'")];
echo json_encode(['checks'=>$checks,'final_sql'=>$final,'restricted_worker_user'=>$wUser!==null,'worker_finish_ms_per_race'=>$timings],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
exit(array_filter($checks,fn($x)=>!$x['passed'])?1:0);
