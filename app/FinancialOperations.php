<?php
declare(strict_types=1);
namespace Centro;

/** Internal dashboard operations; no external app authorization or automatic billing. */
trait FinancialOperations
{
 private function requireOperationalSchema():void {
  $checksum=$this->q('SELECT checksum FROM schema_migrations WHERE version=?',['017_operational_completion'])->fetchColumn();
  if(!$checksum||!hash_equals(hash_file('sha256',dirname(__DIR__).'/database/migrations/017_operational_completion.sql'),$checksum))throw new BusinessError('operational_migration_required',409);
 }
 private function treasury(string $e,array $c):array {
  $kind=$c['kind'];$direction=$kind==='withdrawal'?'out':'in';
  if(isset($c['note'])&&(!is_string($c['note'])||strlen($c['note'])>10000))throw new BusinessError('invalid_note');
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
  $this->requireOperationalSchema();
  $kind=$c['kind'];if(array_key_exists('trial',$c)&&!is_bool($c['trial']))throw new BusinessError('invalid_trial_flag');$id=$this->id($c['subscription_id']??'');$date=$this->validDate($c['date']??'');
  $new=$kind==='subscription_register';
  $s=$new?null:$this->q('SELECT * FROM subscriptions WHERE environment_id=? AND id=? AND archived_at IS NULL FOR UPDATE',[$e,$id])->fetch();
  if(!$new&&!$s)throw new BusinessError('reference_not_found',404);
  if(!is_int($c['expected_version']??null)||$c['expected_version']!==($new?0:(int)$s['row_version']))throw new BusinessError('version_conflict',409);
  $plan=$this->id($c['plan_id']??($s['plan_id']??''));
  $requiresActive=$new||$kind==='subscription_renew'||($c['event']??'')==='cambio_plan';
  $p=$this->q('SELECT * FROM plans WHERE environment_id=? AND id=? FOR UPDATE',[$e,$plan])->fetch();
  if(!$p||($requiresActive&&($p['archived_at']!==null||$p['status']!=='activa'))||(!$new&&$p['project_id']!==$s['project_id']))throw new BusinessError('plan_reference_invalid');
  $project=$this->q('SELECT id FROM projects WHERE environment_id=? AND id=? AND archived_at IS NULL',[$e,$p['project_id']])->fetch();if($requiresActive&&!$project)throw new BusinessError('project_reference_invalid');
  if($new){$customer=$this->id($c['customer_id']??'');if(isset($c['customer']))$this->subscriptionCustomer($e,$customer,$c['customer']);$this->refCustomer($e,$customer);}
  else{$customer=$s['customer_id'];if($date<$s['start_date'])throw new BusinessError('subscription_event_out_of_order',409);
   $last=$this->q('SELECT MAX(event_date) FROM subscription_events WHERE environment_id=? AND subscription_id=?',[$e,$id])->fetchColumn();if($last&&$date<$last)throw new BusinessError('subscription_event_out_of_order',409);}
  $event=$new?($c['trial']??false?'prueba':'alta'):($kind==='subscription_renew'?($s['status']==='prueba'?'conversion':'renovacion'):($c['event']??''));
  if(!$new){try{FinancialContracts::describe(['kind'=>'subscription_status','operation_id'=>$this->op,'expected_version'=>$c['expected_version'],'event'=>$event],['version'=>(int)$s['row_version'],'status'=>$s['status']]);}catch(\InvalidArgumentException $x){throw new BusinessError($x->getMessage());}}
  if(!in_array($event,['alta','prueba','conversion','renovacion','pago_fallido','cancelacion','cambio_plan','reactivacion','pausa'],true))throw new BusinessError('invalid_subscription_event');
  if($kind==='subscription_status'&&in_array($event,['alta','prueba','conversion','renovacion'],true))throw new BusinessError('use_subscription_renew');
  if($kind==='subscription_status'&&$event!=='cambio_plan'&&$plan!==$s['plan_id'])throw new BusinessError('plan_change_requires_event');
  if($new||$kind==='subscription_renew'||$event==='cambio_plan')$price=$this->price($e,$plan,$date,$c['price_snapshot_id']??null);
  else{
   // A non-cash status event preserves existing evidence, even after a price expires.
   $price=$this->q('SELECT pp.* FROM fin_subscription_evidence x JOIN subscription_events ev ON ev.environment_id=x.environment_id AND ev.id=x.event_id JOIN plan_prices pp ON pp.environment_id=x.environment_id AND pp.id=x.price_snapshot_id WHERE ev.environment_id=? AND ev.subscription_id=? AND ev.plan_id=? ORDER BY ev.event_date DESC,ev.created_at DESC,ev.id DESC LIMIT 1 FOR UPDATE',[$e,$id,$plan])->fetch();
   if(isset($c['price_snapshot_id'])&&(!$price||$price['id']!==$c['price_snapshot_id']))throw new BusinessError('historical_price_evidence_required',422);
  }
  if($new&&$event==='prueba'&&$p['trial_days']===null)throw new BusinessError('historical_trial_evidence_required',422);
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
  if($price)$this->q('INSERT INTO fin_subscription_evidence(environment_id,event_id,price_snapshot_id,operation_id) VALUES(?,?,?,?)',[$e,$eventId,$price['id'],$this->op]);
  return ['subscription_id'=>$id,'event_id'=>$eventId,'sale_id'=>$saleId,'status'=>$status,'next_renewal'=>$next,'row_version'=>$new?1:(int)$s['row_version']+1];
 }
 private function subscriptionCustomer(string $e,string $id,mixed $customer):void {
  if(!is_array($customer)||($customer['id']??null)!==$id)throw new BusinessError('invalid_customer');
  $name=$customer['name']??null;$email=$customer['email']??null;$country=$customer['country']??'';$notes=$customer['notes']??'';$origin=$customer['origin']??null;$consent=$customer['consent']??null;
  if(!is_string($name)||trim($name)===''||strlen($name)>160||!is_string($country)||strlen($country)>120||!is_string($notes)||strlen($notes)>10000)throw new BusinessError('invalid_customer');
  if($email==='')$email=null;if($email!==null&&(!is_string($email)||strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)))throw new BusinessError('invalid_customer_email');
  if($origin!==null&&!in_array($origin,['manual','importado','integracion','estimado'],true))throw new BusinessError('invalid_origin');
  if($consent!==null&&!is_bool($consent))throw new BusinessError('invalid_consent');
  $date=isset($customer['createdAt'])?$this->validDate($customer['createdAt']):null;
  $this->q('INSERT INTO customers(environment_id,id,name,email,country,business_date,consent,notes,origin) VALUES(?,?,?,?,?,?,?,?,?)',[$e,$id,$name,$email,$country,$date,$consent===null?null:(int)$consent,$notes,$origin]);
 }
 private function subscriptionNext(string $date,int $months):string {
  // Match the original JS addMonths: clamp the day to the target month's last day.
  $d=new \DateTimeImmutable($date);$target=$d->modify('first day of this month')->modify('+'.$months.' months');return $target->setDate((int)$target->format('Y'),(int)$target->format('m'),min((int)$d->format('d'),(int)$target->format('t')))->format('Y-m-d');
 }
 private function metadata(string $e,string $kind,string $id,array $record):void {
  $this->requireOperationalSchema();
  $fields=$kind==='sale'?['type','projectId','planId','subId','brandId','channelId','country','concept','coupon','delivery','origin','source','externalId','notes']:['concept','category','dueDate','frequency','payMethod','nature','costType','resourceId','alloc','receipt','origin','notes'];
  $safe=array_intersect_key($record,array_flip($fields));
  foreach($safe as $field=>$value){
   if($field==='alloc'){
    if(!is_array($value)||!in_array($value['mode']??null,['general','single','equal','pct'],true)||!is_array($value['targets']??null)||count($value['targets'])>100)throw new BusinessError('invalid_allocation');
    $total='0';$seen=[];
    foreach($value['targets'] as $target){
     if(!is_array($target)||!in_array($target['type']??null,['brand','project'],true))throw new BusinessError('invalid_allocation');
     $ref=$this->id($target['id']??'');$table=$target['type']==='brand'?'brands':'projects';
     if(isset($seen[$table.':'.$ref])||!$this->q("SELECT id FROM $table WHERE environment_id=? AND id=? AND archived_at IS NULL",[$e,$ref])->fetch())throw new BusinessError('allocation_reference_invalid');$seen[$table.':'.$ref]=true;
     if($value['mode']==='pct'){$pct=$target['pct']??null;if(!is_int($pct)&&!is_float($pct)&&!is_string($pct))throw new BusinessError('invalid_allocation');$text=(string)$pct;if(!preg_match('/\A(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?\z/D',$text)||bccomp($text,'0',4)<=0)throw new BusinessError('invalid_allocation');$total=bcadd($total,$text,4);}
    }
    $count=count($value['targets']);if(($value['mode']==='general'&&$count!==0)||($value['mode']==='single'&&$count!==1)||(in_array($value['mode'],['equal','pct'],true)&&$count===0)||($value['mode']==='pct'&&(bccomp($total,'99.99',4)<0||bccomp($total,'100.01',4)>0)))throw new BusinessError('invalid_allocation');
    continue;
   }
   if(!is_string($value)||strlen($value)>10000)throw new BusinessError('invalid_record_field');
   if($field==='dueDate'&&$value!=='')$this->validDate($value);
   $refs=['projectId'=>'projects','planId'=>'plans','subId'=>'subscriptions','brandId'=>'brands','channelId'=>'channels'];
   if(isset($refs[$field])&&$value!==''){$ref=$this->id($value);$table=$refs[$field];if(!$this->q("SELECT id FROM $table WHERE environment_id=? AND id=?",[$e,$ref])->fetch())throw new BusinessError('metadata_reference_invalid');}
  }

  $old=$this->q('SELECT record_json FROM fin_entity_metadata WHERE environment_id=? AND entity_kind=? AND entity_id=?',[$e,$kind,$id])->fetchColumn();if($old)$safe=array_replace(json_decode($old,true,32,JSON_THROW_ON_ERROR),$safe);
  $this->q('INSERT INTO fin_entity_metadata(environment_id,entity_kind,entity_id,record_json) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE record_json=VALUES(record_json)',[$e,$kind,$id,json_encode($safe,JSON_THROW_ON_ERROR)]);
 }
}
