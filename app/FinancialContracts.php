<?php
declare(strict_types=1);
namespace Centro;

/** Executable contract validation only. No financial persistence/API is implemented here.
 * Context must eventually be loaded and version-checked under SQL locks by the executor.
 * A confirmed cash command MUST persist all listed effects and its receipt/audit atomically.
 */
final class FinancialContracts
{
    public const OPERATIONS=[
        'capital_contribution'=>['cash_inflow'],
        'withdrawal'=>['cash_outflow'],
        'treasury_adjustment'=>['cash_inflow'],
        'sale_register'=>['sale','sale_history'],
        'sale_edit'=>['sale_revision','sale_history'],
        'sale_refund_commercial'=>['commercial_refund_revision','sale_history'],
        'sale_void'=>['sale_void','sale_history','cash_regularization_warning'],
        'sale_collect'=>['cash_receipt','sale_collection_link','sale_history'],
        'cash_refund'=>['cash_outflow','refund_sale_link','sale_history'],
        'expense_register'=>['expense','expense_history'],
        'expense_edit'=>['expense_revision','expense_history'],
        'expense_pay'=>['cash_payment','expense_payment_link','expense_history'],
        'cash_compensate'=>['linked_compensating_movement','correction_history'],
        'transfer'=>['transfer_source_leg','transfer_destination_leg','transfer_link'],
        'subscription_register'=>['subscription','ordered_subscription_event','plan_price_snapshot'],
        'subscription_renew'=>['subscription_revision','ordered_subscription_event','sale','plan_price_snapshot'],
        'subscription_status'=>['subscription_revision','ordered_subscription_event'],
        'plan_price_change'=>['close_open_price_interval','new_immutable_price','plan_history'],
        'external_import'=>['persistent_external_event_key','ordered_domain_effects','external_event_receipt']
    ];
    public static function describe(array $command,array $context): array
    {
        $kind=$command['kind']??'';$op=$command['operation_id']??'';
        if(!is_string($kind) || !isset(self::OPERATIONS[$kind]) || !is_string($op) || !preg_match('/\A[A-Za-z0-9_-]{16,100}\z/D',$op)
            || !is_int($command['expected_version']??null) || $command['expected_version']<0)throw new \InvalidArgumentException('invalid_command_envelope');
        $state=$context['status']??null;
        if(array_key_exists('settle_now',$command) && !is_bool($command['settle_now']))throw new \InvalidArgumentException('invalid_financial_flag');
        $creates=in_array($kind,['sale_register','expense_register','subscription_register'],true);
        if($creates && $command['expected_version']!==0)throw new \InvalidArgumentException('new_entity_version_required');
        if(!$creates && (!is_int($context['version']??null) || $command['expected_version']!==$context['version']))throw new \InvalidArgumentException('version_conflict');
        if(in_array($kind,['sale_edit','sale_collect','sale_refund_commercial','sale_void'],true) && $state!=='valida')throw new \InvalidArgumentException('sale_state_conflict');
        if($kind==='sale_collect' && ($context['pay_status']??null)==='cobrado')throw new \InvalidArgumentException('already_collected');
        if($kind==='expense_pay' && !in_array($state,['pendiente','vencido'],true))throw new \InvalidArgumentException('expense_state_conflict');
        $effects=self::OPERATIONS[$kind];$cash=in_array($kind,['sale_collect','cash_refund','expense_pay','cash_compensate','transfer','capital_contribution','withdrawal','treasury_adjustment'],true);
        if(in_array($kind,['sale_register','expense_register','subscription_register','subscription_renew'],true) && ($command['settle_now']??false)){
            $cash=true;$effects=[...$effects,'explicit_cash_movement','cash_entity_link'];
            if($kind==='subscription_register')$effects=[...$effects,'sale'];
        }
        if($cash){
            if(($command['confirmed']??false)!==true)throw new \InvalidArgumentException('financial_confirmation_required');
            foreach(['amount','currency','date','account_id'] as $field)if(!is_string($command[$field]??null) || $command[$field]==='')throw new \InvalidArgumentException('explicit_cash_details_required');
            // Confirmed cash is exact cents; no implicit conversion from a descriptive price.
            if(!preg_match('/\A(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,2})?\z/D',$command['amount']))throw new \InvalidArgumentException('cash_precision_requires_review');
            if(!preg_match('/\A[A-Z]{3,5}\z/D',$command['currency']) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$command['account_id']))throw new \InvalidArgumentException('invalid_cash_reference');
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$command['date']);
            if(!$date || $date->format('Y-m-d')!==$command['date'] || substr($command['date'],0,4)<'1000')throw new \InvalidArgumentException('invalid_cash_date');
            if(($context['account_currency']??null)!==$command['currency'])throw new \InvalidArgumentException('account_currency_mismatch');
            if($kind!=='transfer' && isset($context['entity_currency']) && $context['entity_currency']!==$command['currency'])throw new \InvalidArgumentException('entity_currency_mismatch');
        }
        if($kind==='transfer'){
            foreach(['destination_account_id','destination_amount','destination_currency'] as $field)if(!is_string($command[$field]??null) || $command[$field]==='')throw new \InvalidArgumentException('explicit_transfer_destination_required');
            if($command['destination_account_id']===$command['account_id'] || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$command['destination_account_id'])
                || !preg_match('/\A(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,2})?\z/D',$command['destination_amount'])
                || ($context['destination_account_currency']??null)!==$command['destination_currency'])throw new \InvalidArgumentException('invalid_transfer_destination');
            if($command['currency']===$command['destination_currency'] && self::cents($command['amount'])!==self::cents($command['destination_amount']))throw new \InvalidArgumentException('same_currency_transfer_unbalanced');
        }
        if($kind==='cash_compensate' && (!is_string($command['original_movement_id']??null) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$command['original_movement_id'])))throw new \InvalidArgumentException('original_movement_required');
        if($kind==='cash_refund' && (!is_string($command['sale_id']??null) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$command['sale_id'])))throw new \InvalidArgumentException('refund_sale_required');
        if(in_array($kind,['subscription_register','subscription_renew','plan_price_change'],true) && (!is_string($command['price_snapshot_id']??null) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$command['price_snapshot_id'])))throw new \InvalidArgumentException('historical_price_evidence_required');
        if($kind==='subscription_status' && !in_array($command['event']??null,['prueba','alta','conversion','renovacion','pago_fallido','pausa','cancelacion','reactivacion','cambio_plan'],true))throw new \InvalidArgumentException('invalid_subscription_event');
        if($kind==='subscription_status'){
            $event=$command['event'];
            if(!in_array($state,['activa','prueba','impaga','pausada','cancelada'],true))throw new \InvalidArgumentException('subscription_state_conflict');
            if($event==='reactivacion' && $state!=='cancelada' || $event!=='reactivacion' && $state==='cancelada'
                || $event==='conversion' && $state!=='prueba')throw new \InvalidArgumentException('subscription_state_conflict');
        }
        if($kind==='external_import' && (!is_string($command['integration_id']??null) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$command['integration_id'])
            || !is_string($command['external_event_id']??null) || $command['external_event_id']==='' || strlen($command['external_event_id'])>255))throw new \InvalidArgumentException('external_event_identity_required');
        $references=match($kind){
            'sale_collect','cash_refund'=>['sale','account','existing_receipts_and_refunds'],
            'expense_pay'=>['expense','account','existing_payments'],
            'cash_compensate'=>['original_movement','account','prior_compensations'],
            'transfer'=>['source_account','destination_account'],
            'subscription_register','subscription_renew','subscription_status'=>['project','customer','subscription_if_existing','plan','immutable_price_snapshot'],
            'plan_price_change'=>['plan','prior_immutable_price'],
            'external_import'=>['integration','external_event_key','referenced_domain_entities'],
            default=>['affected_entity','referenced_catalogs']};
        $targetStatus=match($kind){'sale_void'=>'anulada','expense_pay'=>'pagado',default=>$state};
        if($kind==='subscription_status')$targetStatus=match($command['event']){
            'prueba'=>'prueba','pago_fallido'=>'impaga','cancelacion'=>'cancelada','pausa'=>'pausada','cambio_plan'=>$state,default=>'activa'};
        return ['kind'=>$kind,'cash'=>$cash,'resulting_commercial_status'=>$targetStatus,'atomic_effects'=>[...$effects,'immutable_operation_receipt','audit'],'locked_references'=>$references,
            'historical_movement_mutation'=>false,'expected_version'=>$command['expected_version'],
            'execution_implemented'=>false,'replay_policy'=>'same token+canonical payload returns same receipt; mismatch conflicts',
            'executor_guards'=>['load references and versions under environment/row locks','verify actor and CSRF at HTTP boundary',
                'validate original cash movement and remaining refundable/compensatable balance','retain immutable historical price snapshot',
                'validate external entity/event identity within integration scope','commit effects, immutable receipt and audit in one SQL transaction'],
            'failure_policy'=>'rollback every listed effect; uncertain acknowledgement reconciles by receipt'];
    }
    private static function cents(string $value): string
    {
        [$a,$b]=array_pad(explode('.',$value,2),2,'');return ltrim($a.str_pad($b,2,'0'),'0')?:'0';
    }
}
