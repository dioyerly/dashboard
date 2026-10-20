<?php
declare(strict_types=1);
// Focused financial security checks for block 5.3C. Separate from the 22 cases in financial_lines_53c.php.
// Expectations come from FinancialContracts.php and the approved accounting rules; they are not adjusted to the executor.
// Every check runs on a clean connection: a failing check's open transaction is rolled back before the next check.
require dirname(__DIR__).'/app/Database.php';require dirname(__DIR__).'/app/FinancialService.php';
$c=require dirname(__DIR__).'/config/settings.php';$p=Centro\Database::connection($c['db']);
$p->exec("INSERT INTO auth_users(id,email,password_hash,active) VALUES(1,'security@example.invalid','x',1) ON DUPLICATE KEY UPDATE active=1");
$p->exec("INSERT INTO environments(id,code,label) VALUES('a1','pruebas','A'),('b1','produccion','B')");
$p->exec("INSERT INTO currencies(environment_id,id,symbol) VALUES('a1','USD','$'),('b1','USD','$')");
$p->exec("INSERT INTO accounts(environment_id,id,name,currency_id) VALUES('a1','cash','Cash A','USD'),('a1','bank','Bank A','USD'),('b1','bank_b','Bank B','USD')");
$p->exec("INSERT INTO customers(environment_id,id,name,country,consent,notes,origin) VALUES('a1','c1','Client A','AR',1,'','manual'),('b1','c1','Client B','AR',1,'','manual')");
$p->exec("INSERT INTO brands(environment_id,id,name,color,dark,alt,description) VALUES('a1','br','Brand','#112233','#000000','#ffffff','')");
$p->exec("INSERT INTO product_categories(environment_id,id,brand_id,name) VALUES('a1','cat','br','Cat')");
$p->exec("INSERT INTO products(environment_id,id,brand_id,name,status,pending_updates,category_label_snapshot,category_id) VALUES('a1','p1','br','Active','publicado','','Cat','cat')");
$p->exec("INSERT INTO product_versions(environment_id,id,product_id,version_label,format_label,language_label,price,currency_id) VALUES('a1','v1','p1','1.0','','',10.00,'USD')");
$s=new Centro\FinancialService($p);
// Committed setup sales, used as references by the checks below.
foreach([['sec_sale_c','10.00'],['sec_sale_b','10.00']] as [$id,$amount])$s->execute('a1',str_pad('setup_'.$id,16,'x'),1,['kind'=>'sale_register','sale_id'=>$id,'customer_id'=>'c1','currency'=>'USD','amount'=>$amount,'date'=>'2026-10-16']);
$tables=['fin_sales','fin_sale_lines','fin_movements','fin_sale_allocations','fin_operation_receipts','fin_audit'];
$snapshot=function()use($p,$tables){$o=[];foreach($tables as $t)$o[$t]=(int)$p->query("SELECT COUNT(*) FROM $t")->fetchColumn();return $o;};
$results=[];
// $expectReplay: null = not applicable; true/false = the successful result must (or must not) be a replay.
$check=function(string $name,bool $expectOk,?string $expectReason,callable $action,?bool $expectReplay=null)use($p,&$results,$snapshot){
    $before=$snapshot();$open=false;$ok=false;$reason=null;$msg=null;$replayed=null;
    try{$r=$action();$ok=true;$replayed=$r['replayed']??null;}
    catch(Throwable $e){$reason=$e instanceof Centro\BusinessError?$e->reason:get_class($e);$msg=substr($e->getMessage(),0,160);$open=$p->inTransaction();if($open)$p->rollBack();}
    $passed=$expectOk?($ok&&($expectReplay===null||$replayed===$expectReplay)):(!$ok&&$reason===$expectReason);
    $results[$name]=['status'=>$passed?'APROBADO':'FALLIDO',
        'expected'=>$expectOk?'success'.($expectReplay===true?' (replay)':''):'rejected:'.$expectReason,
        'observed'=>$ok?'success'.($replayed===true?' (replay)':''):'rejected:'.$reason,
        'message'=>$msg,'service_left_transaction_open'=>$open,'sql_before'=>$before,'sql_after'=>$snapshot()];
};
$run=function(string $name,string $env,array $cmd,bool $expectOk,?string $expectReason=null)use($check,$s){
    $check($name,$expectOk,$expectReason,fn()=>$s->execute($env,str_pad('sec_'.$name,16,'x'),1,$cmd));
};
$cash=['date'=>'2026-10-16','currency'=>'USD','account_id'=>'cash'];
// 1. settle_now as the string "false" must not request cash (contract: invalid_financial_flag).
$run('settle_now_string_false','a1',['kind'=>'sale_register','sale_id'=>'sec_sn_1','customer_id'=>'c1','amount'=>'5.00','settle_now'=>'false','movement_id'=>'sec_mv_1',...$cash],false,'invalid_financial_flag');
// 2. Cash-moving registration without confirmed === true must be refused.
$run('cash_register_without_confirmation','a1',['kind'=>'sale_register','sale_id'=>'sec_sn_2','customer_id'=>'c1','amount'=>'5.00','settle_now'=>true,'movement_id'=>'sec_mv_2',...$cash],false,'financial_confirmation_required');
// 3. Cash collection without confirmed === true must be refused.
$run('cash_collect_without_confirmation','a1',['kind'=>'sale_collect','sale_id'=>'sec_sale_b','amount'=>'1.00','movement_id'=>'sec_mv_3',...$cash],false,'financial_confirmation_required');
// 4. Control: the same collection with confirmed === true must succeed.
$run('control_collect_with_confirmation','a1',['kind'=>'sale_collect','sale_id'=>'sec_sale_c','amount'=>'1.00','confirmed'=>true,'movement_id'=>'sec_mv_4',...$cash],true);
// 5. Transfer between two different accounts, balanced, same currency: must create both legs.
$transfer=['kind'=>'transfer','amount'=>'5.00','currency'=>'USD','destination_currency'=>'USD','destination_amount'=>'5.00','date'=>'2026-10-16','account_id'=>'cash','destination_account_id'=>'bank','confirmed'=>true];
$run('transfer_between_accounts_balanced','a1',$transfer,true);
// 6. Transfer to the same account must be refused.
$run('transfer_same_account','a1',[...$transfer,'destination_account_id'=>'cash'],false,'invalid_transfer_destination');
// 7. Same-currency transfer with unequal legs must be refused.
$run('transfer_unbalanced_same_currency','a1',[...$transfer,'destination_amount'=>'4.00'],false,'same_currency_transfer_unbalanced');
// 8. Fractional quantity: 0.333333 x 10.00 = 3.333330, but line_total 3.33 must not be silently accepted.
$line=fn(string $qty,string $unit,string $total,?string $product=null,?string $version=null)=>array_filter(['description'=>'line','quantity'=>$qty,'unit_price'=>$unit,'line_total'=>$total,'product_id'=>$product,'version_id'=>$version],fn($v)=>$v!==null);
$run('fractional_third_truncated','a1',['kind'=>'sale_register','sale_id'=>'sec_fr_1','customer_id'=>'c1','currency'=>'USD','amount'=>'3.33','date'=>'2026-10-16','lines'=>[$line('0.333333','10.00','3.33','p1','v1')]],false,'line_total_mismatch');
// 9. Fractional quantity: 0.5 x 2.01 = 1.005, but line_total 1.00 must not be silently accepted.
$run('fractional_half_truncated','a1',['kind'=>'sale_register','sale_id'=>'sec_fr_2','customer_id'=>'c1','currency'=>'USD','amount'=>'1.00','date'=>'2026-10-16','lines'=>[$line('0.5','2.01','1.00')]],false,'line_total_mismatch');
// 10. Control: exact fractional quantity must succeed.
$run('fractional_exact_control','a1',['kind'=>'sale_register','sale_id'=>'sec_fr_3','customer_id'=>'c1','currency'=>'USD','amount'=>'1.00','date'=>'2026-10-16','lines'=>[$line('0.5','2.00','1.00')]],true);
// 11. Real cross-environment reference: env b1 cannot collect a sale that exists only in a1.
$run('cross_env_sale_reference','b1',['kind'=>'sale_collect','sale_id'=>'sec_sale_c','amount'=>'1.00','confirmed'=>true,'movement_id'=>'sec_mv_11',...$cash,'account_id'=>'bank_b'],false,'reference_not_found');
// 12. Real cross-environment reference: env a1 cannot use an account that exists only in b1.
$run('cross_env_account_reference','a1',['kind'=>'sale_collect','sale_id'=>'sec_sale_c','amount'=>'1.00','confirmed'=>true,'movement_id'=>'sec_mv_12',...$cash,'account_id'=>'bank_b'],false,'account_reference_invalid');
// 13. Idempotency: the same operation and payload replays the stored receipt.
$idemCmd=['kind'=>'sale_register','sale_id'=>'sec_idem_1','customer_id'=>'c1','currency'=>'USD','amount'=>'2.00','date'=>'2026-10-16'];
$check('idempotent_replay_same_payload',true,null,function()use($s,$idemCmd){$s->execute('a1','sec_idem_fixed_0001',1,$idemCmd);return $s->execute('a1','sec_idem_fixed_0001',1,$idemCmd);},true);
// 14. Idempotency: the same operation with a different payload must conflict.
$check('idempotent_payload_change_conflict',false,'idempotency_conflict',function()use($s,$idemCmd){$s->execute('a1','sec_idem_fixed_0002',1,[...$idemCmd,'sale_id'=>'sec_idem_2']);return $s->execute('a1','sec_idem_fixed_0002',1,[...$idemCmd,'sale_id'=>'sec_idem_2','amount'=>'3.00']);});
// 15. Idempotency: the same payload with keys in another order is the same canonical payload and must replay.
$check('idempotent_replay_reordered_keys',true,null,function()use($s,$idemCmd){$cmd=[...$idemCmd,'sale_id'=>'sec_idem_3'];$s->execute('a1','sec_idem_fixed_0003',1,$cmd);return $s->execute('a1','sec_idem_fixed_0003',1,array_reverse($cmd,true));},true);
echo json_encode(['results'=>$results,'final_sql'=>$snapshot()],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;exit(array_filter($results,fn($r)=>$r['status']!=='APROBADO')?1:0);
