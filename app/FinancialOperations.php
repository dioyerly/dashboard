<?php
declare(strict_types=1);
namespace Centro;

/** Internal dashboard operations; no external app authorization or automatic billing. */
trait FinancialOperations
{
 private function treasury(string $e,array $c):array {
  $kind=$c['kind'];$direction=$kind==='withdrawal'?'out':'in';
  $account=$this->id($c['account_id']??'');$currency=$this->id($c['currency']??'');$this->account($e,$account,$currency);
  $amount=$this->money($c['amount']??'');$id=$this->id($c['movement_id']??('mv_'.bin2hex(random_bytes(8))));
  $this->q('INSERT INTO fin_movements(environment_id,id,account_id,kind,direction,amount,currency_id,movement_on,reference_id) VALUES(?,?,?,?,?,?,?,?,?)',[$e,$id,$account,$kind,$direction,$amount,$currency,$c['date'],$id]);
  return ['movement_id'=>$id,'amount'=>$amount,'direction'=>$direction];
 }
 private function price(string $e,string $plan,string $date,?string $id):array {
  $sql='SELECT * FROM plan_prices WHERE environment_id=? AND plan_id=? AND archived_at IS NULL AND provenance="known" AND valid_from<=? AND (valid_to IS NULL OR valid_to>?)';
  $args=[$e,$plan,$date,$date];if($id!==null){$sql.=' AND id=?';$args[]=$this->id($id);}
  $rows=$this->q($sql.' FOR UPDATE',$args)->fetchAll();
  if(count($rows)!==1)throw new BusinessError('historical_price_evidence_required',422);
  return $rows[0];
 }
 private function subscription(string $e,array $c):array {
  $kind=$c['kind'];if(array_key_exists('trial',$c)&&!is_bool($c['trial']))throw new BusinessError('invalid_trial_flag');$id=$this->id($c['subscription_id']??'');$date=$this->validDate($c['date']??'');
  $new=$kind==='subscription_register';
  $s=$new?null:$this->q('SELECT * FROM subscriptions WHERE environment_id=? AND id=? AND archived_at IS NULL FOR UPDATE',[$e,$id])->fetch();
  if(!$new&&!$s)throw new BusinessError('reference_not_found',404);
  if(!is_int($c['expected_version']??null)||$c['expected_version']!==($new?0:(int)$s['row_version']))throw new BusinessError('version_conflict',409);
  $plan=$this->id($c['plan_id']??($s['plan_id']??''));
  $p=$this->q('SELECT * FROM plans WHERE environment_id=? AND id=? AND archived_at IS NULL AND status="activa" FOR UPDATE',[$e,$plan])->fetch();
  if(!$p||(!$new&&$p['project_id']!==$s['project_id']))throw new BusinessError('plan_reference_invalid');
  $project=$this->q('SELECT id FROM projects WHERE environment_id=? AND id=? AND archived_at IS NULL',[$e,$p['project_id']])->fetch();if(!$project)throw new BusinessError('project_reference_invalid');
  if($new){$customer=$this->id($c['customer_id']??'');$this->refCustomer($e,$customer);}
  else{$customer=$s['customer_id'];if($date<$s['start_date'])throw new BusinessError('subscription_event_out_of_order',409);
   $last=$this->q('SELECT MAX(event_date) FROM subscription_events WHERE environment_id=? AND subscription_id=?',[$e,$id])->fetchColumn();if($last&&$date<$last)throw new BusinessError('subscription_event_out_of_order',409);}
  $event=$new?($c['trial']??false?'prueba':'alta'):($kind==='subscription_renew'?($s['status']==='prueba'?'conversion':'renovacion'):($c['event']??''));
  if(!$new){try{FinancialContracts::describe(['kind'=>'subscription_status','operation_id'=>$this->op,'expected_version'=>$c['expected_version'],'event'=>$event],['version'=>(int)$s['row_version'],'status'=>$s['status']]);}catch(\InvalidArgumentException $x){throw new BusinessError($x->getMessage());}}
  if(!in_array($event,['alta','prueba','conversion','renovacion','pago_fallido','cancelacion','cambio_plan','reactivacion','pausa'],true))throw new BusinessError('invalid_subscription_event');
  if($kind==='subscription_status'&&in_array($event,['alta','prueba','conversion','renovacion'],true))throw new BusinessError('use_subscription_renew');
  if($kind==='subscription_status'&&$event!=='cambio_plan'&&$plan!==$s['plan_id'])throw new BusinessError('plan_change_requires_event');
  $price=$this->price($e,$plan,$date,$c['price_snapshot_id']??null);
  $status=match($event){'prueba'=>'prueba','pausa'=>'pausada','cancelacion'=>'cancelada','pago_fallido'=>'impaga','cambio_plan'=>$s['status'],default=>'activa'};
  $next=$s['next_renewal']??null;$cancel=$s['cancel_date']??null;
  if($new&&$event==='prueba')$next=(new \DateTimeImmutable($date))->modify('+'.(int)$p['trial_days'].' days')->format('Y-m-d');
  if(in_array($event,['alta','conversion','renovacion'],true)){
   $base=$event==='renovacion'&&$next?$next:$date;$next=$this->subscriptionNext($base,$price['period']==='anual'?12:1);
  }
  if($event==='cancelacion')$cancel=$date;if($event==='reactivacion'){$cancel=null;$next=$date;}
  if($new)$this->q('INSERT INTO subscriptions(environment_id,id,project_id,plan_id,customer_id,status,start_date,next_renewal,external_id) VALUES(?,?,?,?,?,?,?,?,?)',[$e,$id,$p['project_id'],$plan,$customer,$status,$date,$next,$c['external_id']??null]);
  else $this->q('UPDATE subscriptions SET status=?,plan_id=?,next_renewal=?,cancel_date=?,row_version=row_version+1,updated_at=CURRENT_TIMESTAMP(6) WHERE environment_id=? AND id=?',[$status,$plan,$next,$cancel,$e,$id]);
  $saleId=null;$amount=null;
  if(in_array($event,['alta','conversion','renovacion'],true)){
   $amount=$this->money($c['amount']??'');
   if(($c['currency']??'')!==$price['currency_id'])throw new BusinessError('entity_currency_mismatch');
   $saleId=$this->id($c['sale_id']??'');
   if(array_key_exists('settle_now',$c)&&!is_bool($c['settle_now']))throw new BusinessError('invalid_financial_flag');
   if(($c['settle_now']??false)===true){if(($c['confirmed']??false)!==true)throw new BusinessError('financial_confirmation_required');$this->account($e,$this->id($c['account_id']??''),$price['currency_id']);}
   $sale=[...$c,'sale_id'=>$saleId,'customer_id'=>$customer,'amount'=>$amount,'date'=>$date];$this->sale($e,$sale);
   $this->metadata($e,'sale',$saleId,['type'=>'suscripcion','projectId'=>$p['project_id'],'planId'=>$plan,'subId'=>$id]);
  }
  $eventId='ev_'.bin2hex(random_bytes(12));
  $note=$c['note']??'';if(!is_string($note)||strlen($note)>255)throw new BusinessError('invalid_note');
  $this->q('INSERT INTO subscription_events(environment_id,id,subscription_id,event_date,event_type,plan_id,amount,currency_id,sale_id,note) VALUES(?,?,?,?,?,?,?,?,?,?)',[$e,$eventId,$id,$date,$event,$plan,$amount,$amount!==null?$price['currency_id']:null,$saleId,$note]);
  $this->q('INSERT INTO fin_subscription_evidence(environment_id,event_id,price_snapshot_id,operation_id) VALUES(?,?,?,?)',[$e,$eventId,$price['id'],$this->op]);
  return ['subscription_id'=>$id,'event_id'=>$eventId,'sale_id'=>$saleId,'status'=>$status,'next_renewal'=>$next,'row_version'=>$new?1:(int)$s['row_version']+1];
 }
 private function subscriptionNext(string $date,int $months):string {
  // Match the original JS addMonths: clamp the day to the target month's last day.
  $d=new \DateTimeImmutable($date);$target=$d->modify('first day of this month')->modify('+'.$months.' months');return $target->setDate((int)$target->format('Y'),(int)$target->format('m'),min((int)$d->format('d'),(int)$target->format('t')))->format('Y-m-d');
 }
 private function metadata(string $e,string $kind,string $id,array $record):void {
  $fields=$kind==='sale'?['type','projectId','planId','subId','brandId','channelId','country','concept','coupon','delivery','origin','source','externalId','notes']:['concept','category','dueDate','frequency','payMethod','nature','costType','resourceId','alloc','receipt','origin','notes'];
  $safe=array_intersect_key($record,array_flip($fields));
  $old=$this->q('SELECT record_json FROM fin_entity_metadata WHERE environment_id=? AND entity_kind=? AND entity_id=?',[$e,$kind,$id])->fetchColumn();if($old)$safe=array_replace(json_decode($old,true,32,JSON_THROW_ON_ERROR),$safe);
  $this->q('INSERT INTO fin_entity_metadata(environment_id,entity_kind,entity_id,record_json) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE record_json=VALUES(record_json)',[$e,$kind,$id,json_encode($safe,JSON_THROW_ON_ERROR)]);
 }
}
