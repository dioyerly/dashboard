<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/FinancialContracts.php';
$checks=[];
function fcCheck(string $name,bool $ok): void {global $checks;$checks[]=['check'=>$name,'passed'=>$ok];if(!$ok)throw new RuntimeException($name);}
function fc(array $c,array $ctx=[]): array {return Centro\FinancialContracts::describe(['kind'=>'sale_collect','operation_id'=>str_repeat('x',16),'expected_version'=>in_array($c['kind']??'',['sale_register','expense_register','subscription_register'],true)?0:1,...$c],['version'=>1,'status'=>'valida','pay_status'=>'pendiente','account_currency'=>'USD','entity_currency'=>'USD',...$ctx]);}
function fcReject(array $c,string $why,array $ctx=[]): bool {try{fc($c,$ctx);return false;}catch(InvalidArgumentException $e){return $e->getMessage()===$why;}}
$cash=['confirmed'=>true,'amount'=>'20.00','currency'=>'USD','date'=>'2026-10-16','account_id'=>'bank'];
foreach(['sale_edit','sale_refund_commercial','sale_void','expense_edit','subscription_status'] as $kind){
    $r=fc(['kind'=>$kind,'event'=>'pago_fallido'],['status'=>$kind==='subscription_status'?'activa':'valida']);fcCheck($kind.' never creates or changes cash implicitly',!$r['cash'] && !$r['historical_movement_mutation']);
}
foreach(['sale_collect','expense_pay','cash_refund','cash_compensate'] as $kind){
    $r=fc(['kind'=>$kind,...$cash,'sale_id'=>'sale','original_movement_id'=>'original'],['status'=>$kind==='expense_pay'?'pendiente':'valida']);
    fcCheck($kind.' requires atomic linked effects plus receipt/audit',$r['cash'] && count($r['atomic_effects'])>=4 && in_array('audit',$r['atomic_effects'],true));
}
fcCheck('Confirmed new collected sale automatically includes linked cash effect',in_array('explicit_cash_movement',fc(['kind'=>'sale_register','settle_now'=>true,...$cash])['atomic_effects'],true));
fcCheck('Confirmed paid expense automatically includes linked payment',in_array('explicit_cash_movement',fc(['kind'=>'expense_register','settle_now'=>true,...$cash])['atomic_effects'],true));
fcCheck('Renewal captures ordered event, sale and immutable price snapshot',in_array('plan_price_snapshot',fc(['kind'=>'subscription_renew','settle_now'=>true,'price_snapshot_id'=>'price',...$cash])['atomic_effects'],true));
fcCheck('Cash needs explicit confirmation',fcReject([...$cash,'confirmed'=>false],'financial_confirmation_required'));
foreach(['amount','currency','date','account_id'] as $field){$c=$cash;unset($c[$field]);fcCheck('No invented cash '.$field,fcReject($c,'explicit_cash_details_required'));}
fcCheck('Cash overprecision rejected',fcReject([...$cash,'amount'=>'20.001'],'cash_precision_requires_review'));
fcCheck('Account currency checked',fcReject($cash,'account_currency_mismatch',['account_currency'=>'ARS']));
fcCheck('Void sale cannot be silently collected',fcReject($cash,'sale_state_conflict',['status'=>'anulada']));
fcCheck('Already collected sale cannot be collected twice under another operation',fcReject($cash,'already_collected',['pay_status'=>'cobrado']));
fcCheck('Stale financial contract version rejected',fcReject($cash,'version_conflict',['version'=>2]));
fcCheck('String settlement flags cannot accidentally request cash',fcReject(['kind'=>'sale_register','settle_now'=>'false'],'invalid_financial_flag'));
fcCheck('Canceled subscription cannot renew through a status event',fcReject(['kind'=>'subscription_status','event'=>'renovacion'],'subscription_state_conflict',['status'=>'cancelada']));
fcCheck('Canceled subscription can explicitly reactivate without creating cash',!fc(['kind'=>'subscription_status','event'=>'reactivacion'],['status'=>'cancelada'])['cash']);
fcCheck('Payment failure becomes unpaid without erasing cash',fc(['kind'=>'subscription_status','event'=>'pago_fallido'],['status'=>'activa'])['resulting_commercial_status']==='impaga');
fcCheck('Compensation requires original movement link',fcReject(['kind'=>'cash_compensate',...$cash],'original_movement_required'));
$transfer=['kind'=>'transfer',...$cash,'destination_account_id'=>'bank2','destination_currency'=>'USD','destination_amount'=>'20'];
fcCheck('Transfer has two atomic linked legs',count(fc($transfer,['destination_account_currency'=>'USD'])['atomic_effects'])===5);
fcCheck('Same-currency transfer must balance exactly',fcReject([...$transfer,'destination_amount'=>'19.99'],'same_currency_transfer_unbalanced',['destination_account_currency'=>'USD']));
fcCheck('Cross-currency transfer requires explicit received amount, not guessed quote',!fcReject([...$transfer,'destination_currency'=>'ARS','destination_amount'=>'20000'],'invalid_transfer_destination',['destination_account_currency'=>'ARS']));
fcCheck('Historical plan price evidence required',fcReject(['kind'=>'subscription_renew'],'historical_price_evidence_required'));
fcCheck('External identity is integration plus event, never global event alone',fcReject(['kind'=>'external_import'],'external_event_identity_required'));
fcCheck('Literal zero external ID is an identity, not missing',fc(['kind'=>'external_import','integration_id'=>'integration','external_event_id'=>'0'])['execution_implemented']===false);
fcCheck('Contract validator explicitly has no financial executor',fc($cash)['execution_implemented']===false);
echo json_encode(['checks'=>$checks,'scope'=>'Executable input/effect contracts only; SQL financial executor and tables are intentionally absent'],JSON_THROW_ON_ERROR);
