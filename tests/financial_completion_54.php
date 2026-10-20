<?php
declare(strict_types=1);
// Block 5.4: commercial completion (sale edit/void, commercial refunds, cash refunds, expense edits, cash compensation),
// verified through persisted SQL, rollback, cross-environment references and real concurrent processes.
// Requires a FRESH synthetic database (empty environments table). Expectations are not adjusted to the executor.
// Exit code 1 on any failed assertion.
require dirname(__DIR__).'/app/Database.php';require dirname(__DIR__).'/app/FinancialService.php';
$c=require dirname(__DIR__).'/config/settings.php';
if($c['environment']!=='local'||$c['db']['host']!=='127.0.0.1'||!preg_match('/\Acentro_auth_[a-z0-9_]+\z/D',$c['db']['name']))throw new RuntimeException('Local synthetic database required');
$p=Centro\Database::connection($c['db']);
if((int)$p->query('SELECT COUNT(*) FROM environments')->fetchColumn()!==0)throw new RuntimeException('Fresh synthetic database required');
$p->exec("INSERT INTO auth_users(id,email,password_hash,active) VALUES(1,'completion@example.invalid','x',1) ON DUPLICATE KEY UPDATE active=1");
$p->exec("INSERT INTO environments(id,code,label) VALUES('a1','pruebas','A'),('b1','produccion','B')");
$p->exec("INSERT INTO currencies(environment_id,id,symbol) VALUES('a1','USD','$'),('a1','ARS','$'),('b1','USD','$')");
$p->exec("INSERT INTO accounts(environment_id,id,name,currency_id) VALUES('a1','cash','Cash','USD'),('a1','bank','Bank','USD'),('a1','ars_acc','Cuenta ARS','ARS'),('b1','bank_b','Bank B','USD')");
$p->exec("INSERT INTO customers(environment_id,id,name,country,consent,notes,origin) VALUES('a1','c1','Client A','AR',1,'','manual'),('b1','c1','Client B','AR',1,'','manual')");
$s=new Centro\FinancialService($p);
$k=fn(string $n)=>str_pad('k54_'.$n,16,'x');
$val=function(string $sql,array $a=[])use($p){$x=$p->prepare($sql);$x->execute($a);return $x->fetchColumn();};
$cnt=fn(string $sql,array $a=[])=>(int)$val($sql,$a);
$tables=['fin_sales','fin_sale_lines','fin_movements','fin_sale_allocations','fin_expenses','fin_expense_allocations','fin_sale_revisions','fin_expense_revisions','fin_operation_receipts','fin_audit'];
$snapshot=function()use($p,$tables){$o=[];foreach($tables as $t)$o[$t]=(int)$p->query("SELECT COUNT(*) FROM $t")->fetchColumn();return $o;};
$checks=[];
$check=function(string $name,bool $expectOk,?string $expectReason,callable $action,?bool $expectReplay=null,?callable $verify=null)use($p,&$checks,$snapshot){
    $before=$snapshot();$ok=false;$reason=null;$msg=null;$replayed=null;$out=null;
    try{$r=$action();$ok=true;$replayed=$r['replayed']??null;$out=$r;}
    catch(Throwable $e){$reason=$e instanceof Centro\BusinessError?$e->reason:get_class($e);$msg=substr($e->getMessage(),0,140);}
    $open=$p->inTransaction();if($open)$p->rollBack();
    $after=$snapshot();
    $verified=$verify===null?true:(bool)$verify($out,$ok);
    $passed=!$open&&$verified&&($expectOk?($ok&&($expectReplay===null||$replayed===$expectReplay)):(!$ok&&$reason===$expectReason&&$after===$before));
    $checks[]=['check'=>$name,'passed'=>$passed,'expected'=>$expectOk?'success'.($expectReplay===true?' (replay)':''):'rejected:'.$expectReason,'observed'=>$ok?'success'.($replayed===true?' (replay)':''):'rejected:'.$reason,'message'=>$msg,'service_left_transaction_open'=>$open,'rows_changed_on_rejection'=>!$expectOk&&$after!==$before];
};
$sale=fn(string $id,string $amt)=>['kind'=>'sale_register','sale_id'=>$id,'customer_id'=>'c1','currency'=>'USD','amount'=>$amt,'date'=>'2026-10-16'];
$receipt=fn(string $sid,string $amt,string $mv)=>['kind'=>'sale_collect','sale_id'=>$sid,'amount'=>$amt,'account_id'=>'cash','currency'=>'USD','date'=>'2026-10-16','confirmed'=>true,'movement_id'=>$mv];
$refund=fn(string $sid,string $amt,?string $account='cash',string $cur='USD')=>['kind'=>'cash_refund','sale_id'=>$sid,'amount'=>$amt,'account_id'=>$account,'currency'=>$cur,'date'=>'2026-10-16','confirmed'=>true];
$comp=fn(string $orig,string $amt,string $mv,string $acc='bank',string $cur='USD')=>['kind'=>'cash_compensate','original_movement_id'=>$orig,'amount'=>$amt,'account_id'=>$acc,'currency'=>$cur,'date'=>'2026-10-16','confirmed'=>true,'movement_id'=>$mv];
$payCmd=fn(string $exp,string $amt,string $mv,?int $ver=null)=>['kind'=>'expense_pay','expense_id'=>$exp,'amount'=>$amt,'account_id'=>'cash','currency'=>'USD','date'=>'2026-10-16','confirmed'=>true,'movement_id'=>$mv,...($ver===null?[]:['expected_version'=>$ver])];
$exp=fn(string $id,string $amt)=>['kind'=>'expense_register','expense_id'=>$id,'currency'=>'USD','amount'=>$amt,'date'=>'2026-10-16'];

// ---------- Setup (committed through the service) ----------
$s->execute('a1',$k('setup_se'),1,$sale('S_E','50.00'));
$s->execute('a1',$k('setup_sv'),1,$sale('S_V','100.00'));$s->execute('a1',$k('setup_sv_c'),1,$receipt('S_V','30.00','v_rcpt'));
$s->execute('a1',$k('setup_sv0'),1,$sale('S_V0','40.00'));
$s->execute('a1',$k('setup_sr'),1,$sale('S_R','100.00'));$s->execute('a1',$k('setup_sr_c'),1,$receipt('S_R','100.00','r_rcpt'));
$s->execute('a1',$k('setup_sc'),1,$sale('S_C','100.00'));$s->execute('a1',$k('setup_sc_c'),1,$receipt('S_C','100.00','c_rcpt'));
$s->execute('a1',$k('setup_sv2'),1,$sale('S_V2','20.00'));
$s->execute('a1',$k('setup_srv'),1,$sale('S_RV','100.00'));
$s->execute('a1',$k('setup_srr'),1,$sale('S_RR','100.00'));$s->execute('a1',$k('setup_srr_c'),1,$receipt('S_RR','100.00','rr_rcpt'));
$s->execute('a1',$k('setup_x1'),1,$exp('X1','100.00'));
$s->execute('a1',$k('setup_xrr'),1,$exp('X_RR','100.00'));

// ---------- Sales ----------
$check('sc01 edit sale without collections succeeds',true,null,fn()=>$s->execute('a1',$k('sc01_edit'),1,['kind'=>'sale_edit','sale_id'=>'S_E','amount'=>'60.00']),null,
    fn()=>$val("SELECT total FROM fin_sales WHERE environment_id='a1' AND id='S_E'")==='60.00'&&$cnt("SELECT COUNT(*) FROM fin_sale_revisions WHERE environment_id='a1' AND sale_id='S_E' AND kind='edit'")===1&&$cnt("SELECT row_version FROM fin_sales WHERE environment_id='a1' AND id='S_E'")===2);
$check('sc02 edit sale with collections is rejected',false,'sale_has_collections',fn()=>$s->execute('a1',$k('sc02_edit'),1,['kind'=>'sale_edit','sale_id'=>'S_V','amount'=>'90.00']));
$check('sc03 void sale without collections succeeds, no cash effect',true,null,fn()=>$s->execute('a1',$k('sc03_void'),1,['kind'=>'sale_void','sale_id'=>'S_V0']),null,
    fn($o)=>$val("SELECT status FROM fin_sales WHERE environment_id='a1' AND id='S_V0'")==='anulada'&&$cnt("SELECT COUNT(*) FROM fin_movements WHERE environment_id='a1' AND sale_id='S_V0'")===0&&($o['result']['cash_regularization_warning']===false));
$check('sc04 void sale with collections keeps every collection and warns',true,null,fn()=>$s->execute('a1',$k('sc04_void'),1,['kind'=>'sale_void','sale_id'=>'S_V']),null,
    fn($o)=>$val("SELECT status FROM fin_sales WHERE environment_id='a1' AND id='S_V'")==='anulada'&&$val("SELECT COALESCE(SUM(amount),0) FROM fin_sale_allocations WHERE environment_id='a1' AND sale_id='S_V'")==='30.00'&&$cnt("SELECT COUNT(*) FROM fin_movements WHERE environment_id='a1' AND sale_id='S_V'")===1&&$o['result']['cash_regularization_warning']===true);
$check('sc05 collection on voided sale is rejected',false,'sale_state_conflict',fn()=>$s->execute('a1',$k('sc05_col'),1,$receipt('S_V','10.00','sc05_mv')));
$check('sc06 edit of voided sale is rejected',false,'sale_state_conflict',fn()=>$s->execute('a1',$k('sc06_edit'),1,['kind'=>'sale_edit','sale_id'=>'S_V0','amount'=>'50.00']));
$check('sc07 commercial refund within collected amount is recorded without cash',true,null,fn()=>$s->execute('a1',$k('sc07_crefund'),1,['kind'=>'sale_refund_commercial','sale_id'=>'S_R','amount'=>'40.00']),null,
    fn($o)=>$cnt("SELECT COUNT(*) FROM fin_sale_revisions WHERE environment_id='a1' AND sale_id='S_R' AND kind='refund_commercial' AND amount=40.00")===1&&$cnt("SELECT COUNT(*) FROM fin_movements WHERE environment_id='a1' AND sale_id='S_R'")===1);
$check('sc08 commercial refund above collected amount is rejected',false,'commercial_refund_exceeds_collected',fn()=>$s->execute('a1',$k('sc08_crefund'),1,['kind'=>'sale_refund_commercial','sale_id'=>'S_R','amount'=>'70.00']));
$check('sc09 cash refund partial 40.00 succeeds',true,null,fn()=>$s->execute('a1',$k('sc09_refund'),1,$refund('S_R','40.00')),null,
    fn()=>$val("SELECT COALESCE(SUM(amount),0) FROM fin_movements WHERE environment_id='a1' AND sale_id='S_R' AND kind='refund'")==='40.00');
$check('sc10 cash refund completing the sale succeeds (total 100.00)',true,null,fn()=>$s->execute('a1',$k('sc10_refund'),1,$refund('S_R','60.00')),null,
    fn()=>$val("SELECT COALESCE(SUM(amount),0) FROM fin_movements WHERE environment_id='a1' AND sale_id='S_R' AND kind='refund'")==='100.00');
$check('sc11 duplicate refund beyond collected amount is rejected',false,'over_refund',fn()=>$s->execute('a1',$k('sc11_refund'),1,$refund('S_R','10.00')));
$s->execute('a1',$k('sc12_first'),1,$refund('S_V','10.00'));
$check('sc12 identical refund operation replays the stored receipt',true,null,fn()=>$s->execute('a1',$k('sc12_first'),1,$refund('S_V','10.00')),true,
    fn()=>$val("SELECT COALESCE(SUM(amount),0) FROM fin_movements WHERE environment_id='a1' AND sale_id='S_V' AND kind='refund'")==='10.00');
$check('sc13 refund without confirmation is rejected',false,'financial_confirmation_required',function()use($s,$k,$refund){$r=$refund('S_C','1.00');unset($r['confirmed']);return $s->execute('a1',$k('sc13_refund'),1,$r);});
$check('sc14 refund into an account of another currency is rejected',false,'account_currency_mismatch',fn()=>$s->execute('a1',$k('sc14_refund'),1,$refund('S_C','1.00','ars_acc','USD')));

// Cash compensation: receipt of S_C is compensated partially.
$check('sc15 partial compensation of a receipt is linked to the original',true,null,fn()=>$s->execute('a1',$k('sc15_comp'),1,$comp('c_rcpt','30.00','cmp_1')),null,
    fn()=>$cnt("SELECT COUNT(*) FROM fin_movements WHERE environment_id='a1' AND id='cmp_1' AND kind='compensation' AND direction='out' AND amount=30.00 AND reversal_of='c_rcpt' AND sale_id='S_C'")===1&&$val("SELECT COALESCE(SUM(amount),0) FROM fin_sale_allocations WHERE environment_id='a1' AND sale_id='S_C'")==='100.00');
$check('sc16 compensation above the remaining amount is rejected',false,'over_compensation',fn()=>$s->execute('a1',$k('sc16_comp'),1,$comp('c_rcpt','80.00','cmp_2')));
$check('sc17 compensation of an unknown movement is rejected',false,'reference_not_found',fn()=>$s->execute('a1',$k('sc17_comp'),1,$comp('ghost_mv','1.00','cmp_3')));
$check('sc18 compensation of a compensation is not allowed',false,'compensation_not_allowed',fn()=>$s->execute('a1',$k('sc18_comp'),1,$comp('cmp_1','1.00','cmp_4')));
$check('sc19 compensation in another currency is rejected',false,'entity_currency_mismatch',fn()=>$s->execute('a1',$k('sc19_comp'),1,$comp('c_rcpt','10.00','cmp_5','ars_acc','ARS')));
$check('sc20 refund beyond the compensated limit is rejected (70.00 refundable)',false,'over_refund',fn()=>$s->execute('a1',$k('sc20_refund'),1,$refund('S_C','80.00')));
$check('sc21 refund of exactly the remaining 70.00 succeeds',true,null,fn()=>$s->execute('a1',$k('sc21_refund'),1,$refund('S_C','70.00')));

// ---------- Expenses ----------
$check('sx01 edit pending expense succeeds and records a revision',true,null,fn()=>$s->execute('a1',$k('sx01_edit'),1,['kind'=>'expense_edit','expense_id'=>'X1','amount'=>'120.00']),null,
    fn()=>$val("SELECT status FROM fin_expenses WHERE environment_id='a1' AND id='X1'")==='pendiente'&&$cnt("SELECT COUNT(*) FROM fin_expense_revisions WHERE environment_id='a1' AND expense_id='X1'")===1);
$check('sx02 partial payment of 40.00 succeeds',true,null,fn()=>$s->execute('a1',$k('sx02_pay'),1,$payCmd('X1','40.00','x1_p1')));
$check('sx03 edit to 50.00 (above paid) succeeds keeping pending status',true,null,fn()=>$s->execute('a1',$k('sx03_edit'),1,['kind'=>'expense_edit','expense_id'=>'X1','amount'=>'50.00']),null,
    fn()=>$val("SELECT status FROM fin_expenses WHERE environment_id='a1' AND id='X1'")==='pendiente'&&$val("SELECT COALESCE(SUM(amount),0) FROM fin_expense_allocations WHERE environment_id='a1' AND expense_id='X1'")==='40.00');
$check('sx04 edit below paid amount is rejected',false,'expense_total_below_paid',fn()=>$s->execute('a1',$k('sx04_edit'),1,['kind'=>'expense_edit','expense_id'=>'X1','amount'=>'30.00']));
$check('sx05 payment completing the expense sets pagado',true,null,fn()=>$s->execute('a1',$k('sx05_pay'),1,$payCmd('X1','10.00','x1_p2')),null,
    fn()=>$val("SELECT status FROM fin_expenses WHERE environment_id='a1' AND id='X1'")==='pagado');
$check('sx06 edit above paid amount returns status to pendiente, payments kept',true,null,fn()=>$s->execute('a1',$k('sx06_edit'),1,['kind'=>'expense_edit','expense_id'=>'X1','amount'=>'60.00']),null,
    fn()=>$val("SELECT status FROM fin_expenses WHERE environment_id='a1' AND id='X1'")==='pendiente'&&$val("SELECT COALESCE(SUM(amount),0) FROM fin_expense_allocations WHERE environment_id='a1' AND expense_id='X1'")==='50.00');
$check('sx07 edit with stale expected_version is rejected',false,'version_conflict',fn()=>$s->execute('a1',$k('sx07_edit'),1,['kind'=>'expense_edit','expense_id'=>'X1','amount'=>'61.00','expected_version'=>1]));
$s->execute('a1',$k('sx08_first'),1,['kind'=>'expense_edit','expense_id'=>'X1','amount'=>'62.00']);
$check('sx08 identical expense edit operation replays with one revision',true,null,fn()=>$s->execute('a1',$k('sx08_first'),1,['kind'=>'expense_edit','expense_id'=>'X1','amount'=>'62.00']),true,
    fn()=>$cnt("SELECT COUNT(*) FROM fin_expense_revisions WHERE environment_id='a1' AND operation_id=?",[$k('sx08_first')])===1);
$check('sx09 compensation of an expense payment keeps allocations intact',true,null,fn()=>$s->execute('a1',$k('sx09_comp'),1,$comp('x1_p1','10.00','cmp_x1')),null,
    fn()=>$val("SELECT COALESCE(SUM(amount),0) FROM fin_expense_allocations WHERE environment_id='a1' AND expense_id='X1'")==='50.00'&&$cnt("SELECT COUNT(*) FROM fin_movements WHERE environment_id='a1' AND id='cmp_x1' AND direction='in' AND expense_id='X1'")===1);

// ---------- Cross-environment references ----------
$check('sx10 void of a sale from another environment is rejected',false,'reference_not_found',fn()=>$s->execute('b1',$k('sx10_void'),1,['kind'=>'sale_void','sale_id'=>'S_RV']));
$check('sx11 edit of an expense from another environment is rejected',false,'reference_not_found',fn()=>$s->execute('b1',$k('sx11_edit'),1,['kind'=>'expense_edit','expense_id'=>'X1','amount'=>'70.00']));
$check('sx12 compensation of a movement from another environment is rejected',false,'reference_not_found',fn()=>$s->execute('b1',$k('sx12_comp'),1,$comp('c_rcpt','1.00','cmp_b1','bank_b','USD')));

// ---------- Rollback ----------
$p->exec("INSERT INTO fin_audit(environment_id,operation_id,actor_user_id,event,payload_json) VALUES('a1','k54_void_fail_000001',1,'sale_void','{}')");
$check('sr01 void failing before commit leaves the sale valid and no revision',false,'integrity_conflict',fn()=>$s->execute('a1','k54_void_fail_000001',1,['kind'=>'sale_void','sale_id'=>'S_V2']),null,null);
$checks[count($checks)-1]['passed']=$checks[count($checks)-1]['passed']&&$val("SELECT status FROM fin_sales WHERE environment_id='a1' AND id='S_V2'")==='valida'&&$cnt("SELECT COUNT(*) FROM fin_sale_revisions WHERE environment_id='a1' AND sale_id='S_V2'")===0;
$p->exec("INSERT INTO fin_audit(environment_id,operation_id,actor_user_id,event,payload_json) VALUES('a1','k54_eedit_fail_01',1,'expense_edit','{}')");
$check('sr02 expense edit failing before commit keeps total and revisions',false,'integrity_conflict',fn()=>$s->execute('a1','k54_eedit_fail_01',1,['kind'=>'expense_edit','expense_id'=>'X1','amount'=>'70.00']));
$p->exec("DELETE FROM fin_audit WHERE environment_id='a1' AND operation_id='k54_void_fail_000001'");
$check('sr03 retry of the failed void succeeds after the blocker is removed',true,null,fn()=>$s->execute('a1','k54_void_fail_000001',1,['kind'=>'sale_void','sale_id'=>'S_V2']),null,
    fn()=>$val("SELECT status FROM fin_sales WHERE environment_id='a1' AND id='S_V2'")==='anulada'&&$cnt("SELECT COUNT(*) FROM fin_sale_revisions WHERE environment_id='a1' AND sale_id='S_V2'")===1);
$check('sr04 valid sale edit after failures on the same connection',true,null,fn()=>$s->execute('a1',$k('sr04_edit'),1,['kind'=>'sale_edit','sale_id'=>'S_E','amount'=>'70.00']));

// ---------- Concurrency (independent processes, start barrier) ----------
$worker=__DIR__.DIRECTORY_SEPARATOR.'financial_concurrency_worker_53f.php';
$race=function(array $workers)use($worker):array{
    $php=PHP_BINARY;$ext=(string)ini_get('extension_dir');
    $dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'c54_'.bin2hex(random_bytes(6));mkdir($dir,0700);
    $procs=[];
    foreach($workers as $i=>$w){$w['index']=$i;file_put_contents($dir.DIRECTORY_SEPARATOR."spec_$i.json",json_encode($w,JSON_THROW_ON_ERROR));
        $procs[$i]=proc_open([$php,'-n','-d','extension_dir='.$ext,'-d','extension=pdo_mysql',$worker,$dir.DIRECTORY_SEPARATOR."spec_$i.json"],[1=>['file',$dir.DIRECTORY_SEPARATOR."out_$i.txt",'w'],2=>['file',$dir.DIRECTORY_SEPARATOR."err_$i.txt",'w']],$pipes,null,null,['bypass_shell'=>true]);}
    $t=microtime(true);while(count(glob($dir.DIRECTORY_SEPARATOR.'ready_*')?:[])<count($workers)){if(microtime(true)-$t>30)throw new RuntimeException('barrier timeout');usleep(2000);}
    touch($dir.DIRECTORY_SEPARATOR.'go');foreach($procs as $pr)proc_close($pr);
    $rows=[];foreach($workers as $i=>$w){$f=$dir.DIRECTORY_SEPARATOR."result_$i.json";$d=is_file($f)?json_decode((string)file_get_contents($f),true,64,JSON_THROW_ON_ERROR):['results'=>[]];foreach($d['results']??[] as $r)$rows[]=$r;}
    foreach(glob($dir.DIRECTORY_SEPARATOR.'*')?:[] as $f)@unlink($f);@rmdir($dir);
    return $rows;};
$find=fn(array $rows,string $op)=>current(array_filter($rows,fn($x)=>$x['op']===$op))?:['ok'=>false,'reason'=>'missing'];
$wx=fn(array $ops)=>['kind'=>'exec','ops'=>$ops];

$rows=$race([$wx([['op'=>$k('rv_void'),'cmd'=>['kind'=>'sale_void','sale_id'=>'S_RV']]]),$wx([['op'=>$k('rv_col'),'cmd'=>$receipt('S_RV','10.00','rv_mv')]])]);
$v=$find($rows,$k('rv_void'));$col=$find($rows,$k('rv_col'));
$consistent=$v['ok']&&(($col['ok']===true&&($v['result']['cash_regularization_warning']??null)===true&&$val("SELECT COALESCE(SUM(amount),0) FROM fin_sale_allocations WHERE environment_id='a1' AND sale_id='S_RV'")==='10.00')||(($col['ok']===false)&&($col['reason']??'')==='sale_state_conflict'&&($v['result']['cash_regularization_warning']??null)===false&&$val("SELECT COALESCE(SUM(amount),0) FROM fin_sale_allocations WHERE environment_id='a1' AND sale_id='S_RV'")==='0.00'));
$checks[]=['check'=>'race1 void versus collection: both outcomes consistent with the persisted state','passed'=>$consistent,'expected'=>'consistent','observed'=>['void'=>$v['ok']??null,'collect'=>$col['ok']??null,'collect_reason'=>$col['reason']??null]];

$s->execute('a1',$k('rr_setup_pre'),1,['kind'=>'sale_edit','sale_id'=>'S_E','amount'=>'80.00']);
$rows=$race([$wx([['op'=>$k('rr_ref_a'),'cmd'=>$refund('S_RR','60.00')+['movement_id'=>'rr_m1']]]),$wx([['op'=>$k('rr_ref_b'),'cmd'=>$refund('S_RR','60.00')+['movement_id'=>'rr_m2']]])]);
$oks=count(array_filter($rows,fn($x)=>$x['ok']));$rej=array_column(array_filter($rows,fn($x)=>!$x['ok']),'reason');
$checks[]=['check'=>'race2 two refunds of 60.00 on 100.00 collected: exactly one succeeds, other over_refund','passed'=>$oks===1&&$rej===['over_refund'],'expected'=>'1 success + over_refund','observed'=>['ok'=>$oks,'reasons'=>$rej]];
$checks[]=['check'=>'race2 persisted refunds equal the single successful refund','passed'=>$val("SELECT COALESCE(SUM(amount),0) FROM fin_movements WHERE environment_id='a1' AND sale_id='S_RR' AND kind='refund'")==='60.00','expected'=>'60.00','observed'=>$val("SELECT COALESCE(SUM(amount),0) FROM fin_movements WHERE environment_id='a1' AND sale_id='S_RR' AND kind='refund'")];

$s->execute('a1',$k('xrr_setup'),1,$exp('X_RR2','100.00'));
$rows=$race([$wx([['op'=>$k('rrp_pay'),'cmd'=>$payCmd('X_RR2','60.00','rrp_m1')]]),$wx([['op'=>$k('rre_edit'),'cmd'=>['kind'=>'expense_edit','expense_id'=>'X_RR2','amount'=>'50.00']]])]);
$pay=$find($rows,$k('rrp_pay'));$ed=$find($rows,$k('rre_edit'));
$race3=($pay['ok']===true&&($ed['ok']===false)&&($ed['reason']??'')==='expense_total_below_paid')||($pay['ok']===false&&($pay['reason']??'')==='over_payment'&&$ed['ok']===true);
$checks[]=['check'=>'race3 payment of 60.00 versus edit to 50.00: one of the two is rejected with its own reason','passed'=>$race3,'expected'=>'consistent','observed'=>['pay'=>$pay['ok']??null,'pay_reason'=>$pay['reason']??null,'edit'=>$ed['ok']??null,'edit_reason'=>$ed['reason']??null]];
$checks[]=['check'=>'race3 paid amount never exceeds the final expense total','passed'=>(float)$val("SELECT COALESCE(SUM(a.amount),0) FROM fin_expense_allocations a WHERE a.environment_id='a1' AND a.expense_id='X_RR2'")<=(float)$val("SELECT total FROM fin_expenses WHERE environment_id='a1' AND id='X_RR2'"),'expected'=>'paid <= total','observed'=>null];

// ---------- Ledger integrity ----------
$checks[]=['check'=>'ledger: no movement ever carries a negative amount','passed'=>$cnt("SELECT COUNT(*) FROM fin_movements WHERE environment_id='a1' AND amount<=0")===0,'expected'=>0,'observed'=>null];
$checks[]=['check'=>'ledger: every compensation links to an existing original movement','passed'=>$cnt("SELECT COUNT(*) FROM fin_movements m WHERE m.kind='compensation' AND NOT EXISTS (SELECT 1 FROM fin_movements o WHERE o.environment_id=m.environment_id AND o.id=m.reversal_of)")===0,'expected'=>0,'observed'=>null];
$checks[]=['check'=>'ledger: every successful revision has its operation receipt','passed'=>$cnt("SELECT COUNT(*) FROM fin_sale_revisions r WHERE NOT EXISTS (SELECT 1 FROM fin_operation_receipts o WHERE o.environment_id=r.environment_id AND o.operation_id=r.operation_id)")+$cnt("SELECT COUNT(*) FROM fin_expense_revisions r WHERE NOT EXISTS (SELECT 1 FROM fin_operation_receipts o WHERE o.environment_id=r.environment_id AND o.operation_id=r.operation_id)")===0,'expected'=>0,'observed'=>null];

$failed=array_filter($checks,fn($x)=>!$x['passed']);
echo json_encode(['checks'=>$checks,'final_sql'=>$snapshot()],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
exit($failed?1:0);
