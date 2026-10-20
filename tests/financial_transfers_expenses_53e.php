<?php
declare(strict_types=1);
// Block 5.3E: transfer legs (E3, migration 009) and expense payment state/version (E6).
// Expectations come from the contracts and approved rules; they are not adjusted to the executor.
// Every check must leave no open transaction and, when rejected, change no rows.
require dirname(__DIR__).'/app/Database.php';require dirname(__DIR__).'/app/FinancialService.php';
$c=require dirname(__DIR__).'/config/settings.php';$p=Centro\Database::connection($c['db']);
$p->exec("INSERT INTO auth_users(id,email,password_hash,active) VALUES(1,'transfers@example.invalid','x',1) ON DUPLICATE KEY UPDATE active=1");
$p->exec("INSERT INTO environments(id,code,label) VALUES('a1','pruebas','A'),('b1','produccion','B')");
$p->exec("INSERT INTO currencies(environment_id,id,symbol) VALUES('a1','USD','$'),('a1','ARS','$'),('b1','USD','$')");
$p->exec("INSERT INTO accounts(environment_id,id,name,currency_id) VALUES('a1','cash','Cash A','USD'),('a1','bank','Bank A','USD'),('a1','ars_acc','Cuenta ARS','ARS'),('b1','bank_b','Bank B','USD')");
$s=new Centro\FinancialService($p);
$s->execute('a1','setup_exp_1_000001',1,['kind'=>'expense_register','expense_id'=>'exp_1','currency'=>'USD','amount'=>'100.00','date'=>'2026-10-16']);
$s->execute('a1','setup_exp_2_000001',1,['kind'=>'expense_register','expense_id'=>'exp_2','currency'=>'USD','amount'=>'50.00','date'=>'2026-10-16']);
$tables=['fin_sales','fin_sale_lines','fin_movements','fin_sale_allocations','fin_expenses','fin_expense_allocations','fin_operation_receipts','fin_audit'];
$snapshot=function()use($p,$tables){$o=[];foreach($tables as $t)$o[$t]=(int)$p->query("SELECT COUNT(*) FROM $t")->fetchColumn();return $o;};
$results=[];
// $verify receives the result of the action and whether it succeeded; it must return true.
$check=function(string $name,bool $expectOk,?string $expectReason,callable $action,?bool $expectReplay=null,?callable $verify=null)use($p,&$results,$snapshot){
    $before=$snapshot();$ok=false;$reason=null;$msg=null;$replayed=null;$out=null;
    try{$r=$action();$ok=true;$replayed=$r['replayed']??null;$out=$r;}
    catch(Throwable $e){$reason=$e instanceof Centro\BusinessError?$e->reason:get_class($e);$msg=substr($e->getMessage(),0,160);}
    $open=$p->inTransaction();if($open)$p->rollBack();
    $after=$snapshot();
    $verified=$verify===null?true:(bool)$verify($out,$ok);
    $passed=!$open&&$verified&&($expectOk?($ok&&($expectReplay===null||$replayed===$expectReplay)):(!$ok&&$reason===$expectReason&&$after===$before));
    $results[$name]=['status'=>$passed?'APROBADO':'FALLIDO','expected'=>$expectOk?'success'.($expectReplay===true?' (replay)':''):'rejected:'.$expectReason,
        'observed'=>$ok?'success'.($replayed===true?' (replay)':''):'rejected:'.$reason,'message'=>$msg,'service_left_transaction_open'=>$open,
        'rows_changed_on_rejection'=>!$expectOk&&$after!==$before,'sql_before'=>$before,'sql_after'=>$after];
};
$legRows=function(string $ref)use($p){$x=$p->prepare("SELECT direction,kind,account_id,amount,currency_id FROM fin_movements WHERE environment_id='a1' AND reference_id=? ORDER BY direction");$x->execute([$ref]);return $x->fetchAll(PDO::FETCH_ASSOC);};
$transfer=['kind'=>'transfer','account_id'=>'cash','currency'=>'USD','amount'=>'25.00','destination_account_id'=>'bank','destination_currency'=>'USD','destination_amount'=>'25.00','date'=>'2026-10-16','confirmed'=>true];
$tr=function(string $name,bool $ok,?string $reason,array $cmd)use($check,$s){
    $check($name,$ok,$reason,fn()=>$s->execute('a1',str_pad('t_'.$name,16,'x'),1,$cmd));};

// T1-T7: validation and legs.
$check('t1_valid_same_currency',true,null,fn()=>$s->execute('a1','t_t1_valid_000001',1,[...$transfer,'reference_id'=>'tr_ok_1']),null,function($out,$ok)use($legRows){
    $r=$legRows('tr_ok_1');return count($r)===2&&$r[0]['direction']==='in'&&$r[0]['account_id']==='bank'&&$r[0]['amount']==='25.00'&&$r[1]['direction']==='out'&&$r[1]['account_id']==='cash'&&$r[1]['amount']==='25.00'&&$r[1]['currency_id']==='USD';});
$tr('t2_same_account',false,'invalid_transfer_destination',[...$transfer,'destination_account_id'=>'cash','reference_id'=>'tr_same']);
$tr('t3_unbalanced',false,'same_currency_transfer_unbalanced',[...$transfer,'destination_amount'=>'24.00','reference_id'=>'tr_unbal']);
$tr('t4_missing_account',false,'account_reference_invalid',[...$transfer,'destination_account_id'=>'ghost','reference_id'=>'tr_missing']);
$tr('t5_cross_env_account',false,'account_reference_invalid',[...$transfer,'destination_account_id'=>'bank_b','reference_id'=>'tr_cross']);
$check('t6_cross_currency_explicit_amounts',true,null,fn()=>$s->execute('a1','t_t6_fx_00000001',1,['kind'=>'transfer','account_id'=>'cash','currency'=>'USD','amount'=>'10.00','destination_account_id'=>'ars_acc','destination_currency'=>'ARS','destination_amount'=>'20000.00','date'=>'2026-10-16','confirmed'=>true,'reference_id'=>'tr_fx_1']),null,function($out,$ok)use($legRows){
    $r=$legRows('tr_fx_1');return count($r)===2&&$r[0]['direction']==='in'&&$r[0]['currency_id']==='ARS'&&$r[0]['amount']==='20000.00'&&$r[1]['direction']==='out'&&$r[1]['currency_id']==='USD'&&$r[1]['amount']==='10.00';});
$tr('t7_account_currency_mismatch',false,'account_currency_mismatch',[...$transfer,'currency'=>'ARS','reference_id'=>'tr_cur']);

// T8-T10: idempotency and reference uniqueness.
$check('t8_replay_same_operation_no_extra_legs',true,null,function()use($s,$transfer){$cmd=[...$transfer,'reference_id'=>'tr_rep_1'];$s->execute('a1','t_t8_rep_0000001',1,$cmd);return $s->execute('a1','t_t8_rep_0000001',1,$cmd);},true,function($out,$ok)use($legRows){return count($legRows('tr_rep_1'))===2;});
$s->execute('a1','t_t9_reuse_00000001',1,[...$transfer,'reference_id'=>'tr_reuse_1']);$check('t9_operation_reused_other_payload',false,'idempotency_conflict',fn()=>$s->execute('a1','t_t9_reuse_00000001',1,[...$transfer,'reference_id'=>'tr_reuse_1','amount'=>'26.00','destination_amount'=>'26.00']));
$check('t10_reference_reused_new_operation',false,'integrity_conflict',fn()=>$s->execute('a1','t_t10_refreuse_0001',1,[...$transfer,'amount'=>'30.00','destination_amount'=>'30.00','reference_id'=>'tr_ok_1']));

// T11: forced failure after both legs are inserted, before commit (pre-existing audit row for the same operation).
$p->exec("INSERT INTO fin_audit(environment_id,operation_id,actor_user_id,event,payload_json) VALUES('a1','t_forced_0000001',1,'transfer','{}')");
$check('t11_forced_failure_rolls_back_legs',false,'integrity_conflict',fn()=>$s->execute('a1','t_forced_0000001',1,[...$transfer,'reference_id'=>'tr_forced']));
// T12: no transfer reference has a single leg or duplicated legs.
$check('t12_no_single_leg_transfers',true,null,fn()=>['ok'=>true],null,function()use($p){return (int)$p->query("SELECT COUNT(*) FROM (SELECT reference_id FROM fin_movements WHERE kind='transfer' GROUP BY reference_id HAVING SUM(direction='out')<>1 OR SUM(direction='in')<>1) x")->fetchColumn()===0;});

// Expenses (E6). exp_1 starts at row_version 1, pendiente, 100.00 total.
$row=function(string $id)use($p){$x=$p->prepare("SELECT status,row_version FROM fin_expenses WHERE environment_id='a1' AND id=?");$x->execute([$id]);$r=$x->fetch(PDO::FETCH_ASSOC);return ['status'=>$r['status'],'row_version'=>(int)$r['row_version']];};
$alloc=function(string $id)use($p){$x=$p->prepare("SELECT COALESCE(SUM(amount),0) FROM fin_expense_allocations WHERE environment_id='a1' AND expense_id=?");$x->execute([$id]);return (string)$x->fetchColumn();};
$pay=fn(string $exp,string $amount,string $mv,?int $ver=null)=>['kind'=>'expense_pay','expense_id'=>$exp,'amount'=>$amount,'account_id'=>'cash','currency'=>'USD','date'=>'2026-10-16','confirmed'=>true,'movement_id'=>$mv,...($ver===null?[]:['expected_version'=>$ver])];
$ex=function(string $name,bool $ok,?string $reason,array $cmd,string $op,?callable $verify=null)use($check,$s){
    $check($name,$ok,$reason,fn()=>$s->execute('a1',$op,1,$cmd),null,$verify);};

$check('e1_initial_state',true,null,fn()=>['ok'=>true],null,fn()=>$row('exp_1')===['status'=>'pendiente','row_version'=>1]&&$alloc('exp_1')==='0.00');
$ex('e2_partial_payment_30',true,null,$pay('exp_1','30.00','m_e2'),'e2_pay_op_0000001',fn()=>$row('exp_1')===['status'=>'pendiente','row_version'=>2]&&$alloc('exp_1')==='30.00');
$ex('e3_complete_payment_70',true,null,$pay('exp_1','70.00','m_e3'),'e3_pay_op_0000001',fn()=>$row('exp_1')===['status'=>'pagado','row_version'=>3]&&$alloc('exp_1')==='100.00');
$ex('e4_pay_paid_expense_rejected',false,'expense_state_conflict',$pay('exp_1','1.00','m_e4'),'e4_pay_op_0000001');
$ex('e5_partial_exp2_20',true,null,$pay('exp_2','20.00','m_e5'),'e5_pay_op_0000001',fn()=>$row('exp_2')===['status'=>'pendiente','row_version'=>2]);
$ex('e5b_overpayment_rejected',false,'over_payment',$pay('exp_2','40.00','m_e5b'),'e5b_pay_op_0000001');
$check('e6_duplicate_operation_replay',true,null,function()use($s,$pay){$cmd=$pay('exp_2','10.00','m_e6');$s->execute('a1','e6_pay_op_0000001',1,$cmd);return $s->execute('a1','e6_pay_op_0000001',1,$cmd);},true,fn()=>$row('exp_2')===['status'=>'pendiente','row_version'=>3]&&$alloc('exp_2')==='30.00');
$ex('e7_operation_reused_other_payload',false,'idempotency_conflict',$pay('exp_2','5.00','m_e7'),'e6_pay_op_0000001');
$ex('e8_movement_id_reused_new_operation',false,'integrity_conflict',$pay('exp_2','5.00','m_e6'),'e8_pay_op_0000001');
$ex('e9_missing_expense',false,'reference_not_found',$pay('ghost_exp','1.00','m_e9'),'e9_pay_op_0000001');
$ex('e10_account_from_other_env',false,'account_reference_invalid',['kind'=>'expense_pay','expense_id'=>'exp_2','amount'=>'1.00','account_id'=>'bank_b','currency'=>'USD','date'=>'2026-10-16','confirmed'=>true,'movement_id'=>'m_e10'],'e10_pay_op_0000001');
$ex('e11_version_conflict',false,'version_conflict',$pay('exp_2','1.00','m_e11',1),'e11_pay_op_0000001');
$p->exec("INSERT INTO fin_audit(environment_id,operation_id,actor_user_id,event,payload_json) VALUES('a1','e12_forced_0000001',1,'expense_pay','{}')");
$ex('e12_forced_failure_rolls_back',false,'integrity_conflict',$pay('exp_2','5.00','m_e12'),'e12_forced_0000001',fn()=>$row('exp_2')===['status'=>'pendiente','row_version'=>3]&&$alloc('exp_2')==='30.00');
$ex('e13_valid_payment_after_failures',true,null,$pay('exp_2','20.00','m_e13'),'e13_pay_op_0000001',fn()=>$row('exp_2')===['status'=>'pagado','row_version'=>4]&&$alloc('exp_2')==='50.00');
$check('e14_original_payments_intact',true,null,fn()=>['ok'=>true],null,function()use($p){$x=$p->query("SELECT GROUP_CONCAT(CONCAT(movement_id,':',amount) ORDER BY movement_id) FROM fin_expense_allocations WHERE environment_id='a1' AND expense_id='exp_1'")->fetchColumn();return $x==='m_e2:30.00,m_e3:70.00';});

echo json_encode(['results'=>$results,'final_sql'=>$snapshot()],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;exit(array_filter($results,fn($r)=>$r['status']!=='APROBADO')?1:0);
