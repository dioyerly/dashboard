<?php
declare(strict_types=1);
// Block 5.3D checks: transactions (E1), confirmation and contracts (E2), exact decimals (E4),
// canonical idempotency (E5) and environment references (E7). Expectations are not adjusted to the executor.
// Every check must leave no open transaction inside the service and no partial rows on rejection.
require dirname(__DIR__).'/app/Database.php';require dirname(__DIR__).'/app/FinancialService.php';
$c=require dirname(__DIR__).'/config/settings.php';$p=Centro\Database::connection($c['db']);
$p->exec("INSERT INTO auth_users(id,email,password_hash,active) VALUES(1,'execution@example.invalid','x',1) ON DUPLICATE KEY UPDATE active=1");
$p->exec("INSERT INTO environments(id,code,label) VALUES('a1','pruebas','A'),('b1','produccion','B')");
$p->exec("INSERT INTO currencies(environment_id,id,symbol) VALUES('a1','USD','$'),('b1','USD','$')");
$p->exec("INSERT INTO accounts(environment_id,id,name,currency_id) VALUES('a1','cash','Cash A','USD'),('a1','bank','Bank A','USD'),('b1','bank_b','Bank B','USD')");
$p->exec("INSERT INTO customers(environment_id,id,name,country,consent,notes,origin) VALUES('a1','c1','Client A','AR',1,'','manual'),('b1','c1','Client B','AR',1,'','manual'),('b1','c_b','Client only in B','AR',1,'','manual')");
$p->exec("INSERT INTO brands(environment_id,id,name,color,dark,alt,description) VALUES('a1','br','Brand','#112233','#000000','#ffffff','')");
$p->exec("INSERT INTO product_categories(environment_id,id,brand_id,name) VALUES('a1','cat','br','Cat')");
$p->exec("INSERT INTO products(environment_id,id,brand_id,name,status,pending_updates,category_label_snapshot,category_id) VALUES('a1','p1','br','Active','publicado','','Cat','cat')");
$p->exec("INSERT INTO product_versions(environment_id,id,product_id,version_label,format_label,language_label,price,currency_id) VALUES('a1','v1','p1','1.0','','',10.00,'USD')");
$s=new Centro\FinancialService($p);
$s->execute('a1','setup_sale_a_000001',1,['kind'=>'sale_register','sale_id'=>'sale_a','customer_id'=>'c1','currency'=>'USD','amount'=>'10.00','date'=>'2026-10-16']);
$s->execute('a1','setup_sale_b_000001',1,['kind'=>'sale_register','sale_id'=>'sale_b','customer_id'=>'c1','currency'=>'USD','amount'=>'10.00','date'=>'2026-10-16']);
$tables=['fin_sales','fin_sale_lines','fin_movements','fin_sale_allocations','fin_operation_receipts','fin_audit'];
$snapshot=function()use($p,$tables){$o=[];foreach($tables as $t)$o[$t]=(int)$p->query("SELECT COUNT(*) FROM $t")->fetchColumn();return $o;};
$results=[];
// A check passes only if the service left no open transaction and, when rejected, changed no rows.
$check=function(string $name,bool $expectOk,?string $expectReason,callable $action,?bool $expectReplay=null)use($p,&$results,$snapshot){
    $before=$snapshot();$ok=false;$reason=null;$msg=null;$replayed=null;
    try{$r=$action();$ok=true;$replayed=$r['replayed']??null;}
    catch(Throwable $e){$reason=$e instanceof Centro\BusinessError?$e->reason:get_class($e);$msg=substr($e->getMessage(),0,160);}
    $open=$p->inTransaction();if($open)$p->rollBack();
    $after=$snapshot();
    $passed=!$open&&($expectOk?($ok&&($expectReplay===null||$replayed===$expectReplay)):(!$ok&&$reason===$expectReason&&$after===$before));
    $results[$name]=['status'=>$passed?'APROBADO':'FALLIDO',
        'expected'=>$expectOk?'success'.($expectReplay===true?' (replay)':''):'rejected:'.$expectReason,
        'observed'=>$ok?'success'.($replayed===true?' (replay)':''):'rejected:'.$reason,
        'message'=>$msg,'service_left_transaction_open'=>$open,'rows_changed_on_rejection'=>!$expectOk&&$after!==$before,'sql_before'=>$before,'sql_after'=>$after];
};
$run=function(string $name,string $env,array $cmd,bool $expectOk,?string $expectReason=null)use($check,$s){
    $check($name,$expectOk,$expectReason,fn()=>$s->execute($env,str_pad('d_'.$name,16,'x'),1,$cmd));
};
$cash=['date'=>'2026-10-16','currency'=>'USD','account_id'=>'cash'];
$line=fn(string $qty,string $unit,string $total,?string $product=null,?string $version=null,string $desc='line')=>array_filter(['description'=>$desc,'quantity'=>$qty,'unit_price'=>$unit,'line_total'=>$total,'product_id'=>$product,'version_id'=>$version],fn($v)=>$v!==null);
$sale=fn(string $id,string $amount,array $extra=[])=>['kind'=>'sale_register','sale_id'=>$id,'customer_id'=>'c1','currency'=>'USD','amount'=>$amount,'date'=>'2026-10-16',...$extra];

// E1: controlled references, SQL error mapping, rollback and recovery on the same connection.
$run('e1_customer_missing','a1',$sale('x_cust_missing','1.00',['customer_id'=>'ghost']),false,'customer_reference_invalid');
$run('e1_customer_cross_env','a1',$sale('x_cust_cross','1.00',['customer_id'=>'c_b']),false,'customer_reference_invalid');
$run('e1_account_missing','a1',['kind'=>'sale_collect','sale_id'=>'sale_a','amount'=>'1.00','confirmed'=>true,'movement_id'=>'d_mv_am',...$cash,'account_id'=>'ghost_acc'],false,'account_reference_invalid');
$run('e1_account_cross_env','a1',['kind'=>'sale_collect','sale_id'=>'sale_a','amount'=>'1.00','confirmed'=>true,'movement_id'=>'d_mv_ac',...$cash,'account_id'=>'bank_b'],false,'account_reference_invalid');
$run('e1_sale_cross_env','b1',['kind'=>'sale_collect','sale_id'=>'sale_a','amount'=>'1.00','confirmed'=>true,'movement_id'=>'d_mv_sc',...$cash,'account_id'=>'bank_b'],false,'reference_not_found');
$run('e1_deliberate_sql_error','a1',$sale('x_sql_error','1.00',['lines'=>[$line('1','1.00','1.00',null,null,str_repeat('d',300))]]),false,'storage_error');
$run('e1_valid_after_failures_same_connection','a1',$sale('after_failures_ok','3.00'),true);
// E7: environment must exist.
$run('e7_environment_missing','zz_missing',$sale('x_env_missing','1.00'),false,'environment_not_found');

// E2: confirmation, strict settle_now and contract validation.
$run('e2_settle_string_false','a1',$sale('x_sn_str','5.00',['settle_now'=>'false','movement_id'=>'d_mv_1',...$cash]),false,'invalid_financial_flag');
$run('e2_settle_int_one','a1',$sale('x_sn_int','5.00',['settle_now'=>1,'confirmed'=>true,'movement_id'=>'d_mv_2',...$cash]),false,'invalid_financial_flag');
$run('e2_settle_without_confirmation','a1',$sale('x_sn_noconf','5.00',['settle_now'=>true,'movement_id'=>'d_mv_3',...$cash]),false,'financial_confirmation_required');
$run('e2_settle_confirmed_string','a1',$sale('x_sn_strconf','5.00',['settle_now'=>true,'confirmed'=>'true','movement_id'=>'d_mv_4',...$cash]),false,'financial_confirmation_required');
$run('e2_settle_confirmed_false','a1',$sale('x_sn_fconf','5.00',['settle_now'=>true,'confirmed'=>false,'movement_id'=>'d_mv_5',...$cash]),false,'financial_confirmation_required');
$run('e2_expense_settle_string_false','a1',['kind'=>'expense_register','expense_id'=>'x_exp_str','currency'=>'USD','amount'=>'5.00','date'=>'2026-10-16','settle_now'=>'false','movement_id'=>'d_mv_6',...$cash],false,'invalid_financial_flag');
$run('e2_collect_without_confirmation','a1',['kind'=>'sale_collect','sale_id'=>'sale_b','amount'=>'1.00','movement_id'=>'d_mv_7',...$cash],false,'financial_confirmation_required');
$run('e2_control_register_without_cash','a1',$sale('ctl_nocash','4.00'),true);
$run('e2_control_settle_false_no_cash','a1',$sale('ctl_settle_false','4.00',['settle_now'=>false]),true);
$run('e2_control_settle_confirmed','a1',$sale('ctl_settle_conf','2.00',['settle_now'=>true,'confirmed'=>true,'movement_id'=>'d_mv_8',...$cash]),true);
$run('e2_invalid_date_non_cash','a1',$sale('x_bad_date','1.00',['date'=>'2026-13-45']),false,'invalid_date');

// E4: exact decimal products. Rejected when the exact product differs from the declared total.
$prod=['product_id'=>'p1','version_id'=>'v1'];
$run('e4_third_truncation_rejected','a1',$sale('x_fr_third','3.33',['lines'=>[$line('0.333333','10.00','3.33','p1','v1')]]),false,'line_total_mismatch');
$run('e4_half_truncation_rejected','a1',$sale('x_fr_half','1.00',['lines'=>[$line('0.5','2.01','1.00')]]),false,'line_total_mismatch');
$run('e4_nonrepresentable_rejected','a1',$sale('x_fr_nonrep','3.33',['lines'=>[$line('2.5','1.33','3.33')]]),false,'line_total_mismatch');
$run('e4_small_product_rejected','a1',$sale('x_fr_small','1.00',['lines'=>[$line('0.333333','3.00','1.00')]]),false,'line_total_mismatch');
$run('e4_valid_half','a1',$sale('ok_half','1.00',['lines'=>[$line('0.5','2.00','1.00')]]),true);
$run('e4_valid_quarter','a1',$sale('ok_quarter','1.00',['lines'=>[$line('0.25','4.00','1.00')]]),true);
$run('e4_valid_integer_decimal_price','a1',$sale('ok_int','3.30',['lines'=>[$line('3','1.10','3.30')]]),true);
$run('e4_valid_qty_two_decimals','a1',$sale('ok_qty2','3.35',['lines'=>[$line('2.5','1.34','3.35')]]),true);

// E5: canonical idempotency, transition for legacy receipts and real payload conflicts.
$idem=$sale('idem_1','2.00',['lines'=>[$line('1','1.00','1.00',null,null,'first'),$line('1','1.00','1.00',null,null,'second')]]);
$check('e5_same_payload_replay',true,null,function()use($s,$idem){$s->execute('a1','e5_op_same_000001',1,$idem);return $s->execute('a1','e5_op_same_000001',1,$idem);},true);
$check('e5_top_level_keys_reordered',true,null,function()use($s,$idem){$x=[...$idem,'sale_id'=>'idem_top'];$s->execute('a1','e5_op_top_000001',1,$x);return $s->execute('a1','e5_op_top_000001',1,array_reverse($x,true));},true);
$check('e5_nested_keys_reordered',true,null,function()use($s,$idem){$x=[...$idem,'sale_id'=>'idem_nest'];$s->execute('a1','e5_op_nest_000001',1,$x);$n=$x;$n['lines'][0]=array_reverse($n['lines'][0],true);return $s->execute('a1','e5_op_nest_000001',1,$n);},true);
$x=[...$idem,'sale_id'=>'idem_arr'];$s->execute('a1','e5_op_arr_000001',1,$x);$n=$x;$n['lines']=array_reverse($n['lines']);$check('e5_array_order_changed_conflict',false,'idempotency_conflict',fn()=>$s->execute('a1','e5_op_arr_000001',1,$n));
$x=[...$idem,'sale_id'=>'idem_chg'];$s->execute('a1','e5_op_chg_000001',1,$x);$check('e5_payload_changed_conflict',false,'idempotency_conflict',fn()=>$s->execute('a1','e5_op_chg_000001',1,[...$x,'amount'=>'3.00']));
$check('e5_legacy_receipt_replays',true,null,function()use($s,$p,$sale){
    $legacy=$sale('idem_legacy','2.00');$hash=hash('sha256',json_encode($legacy,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $p->prepare('INSERT INTO fin_operation_receipts(environment_id,operation_id,actor_user_id,payload_hash,result_json) VALUES(?,?,?,?,?)')->execute(['a1','e5_op_legacy_0001',1,$hash,json_encode(['sale_id'=>'idem_legacy','status'=>'valida'],JSON_THROW_ON_ERROR)]);
    return $s->execute('a1','e5_op_legacy_0001',1,$legacy);},true);

echo json_encode(['results'=>$results,'final_sql'=>$snapshot()],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;exit(array_filter($results,fn($r)=>$r['status']!=='APROBADO')?1:0);
