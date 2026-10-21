<?php
declare(strict_types=1);
namespace Centro;
require_once __DIR__.'/FinancialService.php';
require_once __DIR__.'/ControlledBackup.php';
require_once __DIR__.'/FinancialState.php';

/** Explicit conversion of immutable legacy evidence, restricted to registered synthetic targets. */
final class HistoricalConversion
{
 public function __construct(private readonly \PDO $pdo,array $config,array $marker){
  $db=$config['db'];$root=realpath($config['root'].'/.runtime/mariadb/import-targets');$path=$root?realpath($root.'/'.$db['name'].'.json'):false;
  if(PHP_SAPI!=='cli'||$config['environment']!=='local'||$db['host']!=='127.0.0.1'||!preg_match('/\Acentro_auth_[0-9a-f]{12}\z/D',$db['name'])||!$path||json_decode((string)file_get_contents($path),true)!==$marker||($marker['purpose']??null)!=='synthetic-backup-import'||($marker['database']??null)!==$db['name']||$pdo->query('SELECT DATABASE()')->fetchColumn()!==$db['name'])throw new BusinessError('synthetic_conversion_target_required',403);
 }
 private function q(string $sql,array $args=[]):array{$q=$this->pdo->prepare($sql);$q->execute($args);return $q->fetchAll(\PDO::FETCH_ASSOC);}
 private static function exact(string $raw):array{
  // Preserve JSON decimal lexemes before PHP can coerce them to binary floats.
  $raw=preg_replace_callback('/"(?:\\\\.|[^"\\\\])*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/s',static fn($m)=>$m[0][0]==='"'?$m[0]:json_encode($m[0],JSON_THROW_ON_ERROR),$raw);
  $v=ControlledBackup::decode($raw);if(!is_array($v)||array_is_list($v))throw new BusinessError('historical_record_required');return $v;
 }
 private static function id(mixed $v):string{if(!is_string($v)||!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$v))throw new BusinessError('historical_identity_missing');return $v;}
 private static function amount(mixed $v,bool $zero=false):string{if(!is_string($v)||!preg_match('/\A(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,2})?\z/D',$v)||bccomp($v,'0',2)<($zero?0:1))throw new BusinessError('historical_precision_or_amount_ambiguous');return bcadd($v,'0',2);}
 private static function date(mixed $v):string{if(!is_string($v)||!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$v)||!($d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v))||$d->format('Y-m-d')!==$v||substr($v,0,4)<'1000')throw new BusinessError('historical_date_missing');return $v;}
 private function cash(array $r):array{return ['movement_id'=>self::id($r['id']??null),'amount'=>self::amount($r['amount']??null),'currency'=>self::id($r['currency']??null),'date'=>self::date($r['date']??null),'confirmed'=>true,'note'=>$r['note']??''];}
 private function build(string $env):array{
  if(!in_array($env,['produccion','pruebas'],true))throw new BusinessError('invalid_environment');
  $rows=$this->q('SELECT * FROM original_financial_snapshots WHERE environment_id=? ORDER BY collection,ordinal,id',[$env]);$all=[];$ids=[];$duplicates=[];$pending=[];$groups=[];
  foreach($rows as $row){$key=$row['collection'].':'.$row['id'];
   try{if(!hash_equals(hash('sha256',$row['payload']),$row['content_hash'])||$row['import_status']!=='historical')throw new BusinessError('historical_evidence_conflict');$record=self::exact($row['payload']);$id=self::id($record['id']??null);$ref=$row['collection'].':'.$id;if(isset($ids[$ref]))$duplicates[$ref]=true;$ids[$ref]=$key;$all[$key]=['row'=>$row,'record'=>$record];}
   catch(\Throwable $x){$pending[]=['key'=>$key,'reason'=>$x instanceof BusinessError?$x->reason:'invalid_historical_json'];}
  }
  $used=[];
  foreach($all as $key=>$entry){$r=$entry['record'];$collection=$entry['row']['collection'];if($collection==='treasury')continue;$links=[];
   foreach($all as $tk=>$t)if($t['row']['collection']==='treasury'&&($t['record'][$collection==='sales'?'saleId':'expenseId']??'')===$r['id'])$links[$tk]=$t;
   try{
    if(isset($duplicates[$collection.':'.$r['id']]))throw new BusinessError('duplicate_historical_identity');
    foreach(['refunds','payments','receipts','transfers','lines'] as $nested)if(!empty($r[$nested]))throw new BusinessError('historical_nested_evidence_requires_mapping');
    $date=self::date($r['date']??null);$currency=self::id($r['currency']??null);$commands=[];
    if($collection==='sales'){
     if(($r['status']??null)!=='valida'||!in_array($r['payStatus']??null,['pendiente','cobrado'],true))throw new BusinessError('historical_sale_status_ambiguous');
     foreach(['discount','commission','tax','refunded'] as $f)if(!array_key_exists($f,$r)||self::amount($r[$f],true)!=='0.00')throw new BusinessError('historical_adjustments_or_refunds_require_policy');
     if(($r['subId']??'')!==''||($r['type']??'unica')!=='unica')throw new BusinessError('historical_subscription_price_evidence_required');
     $qty=$r['qty']??null;if(!is_string($qty)||!preg_match('/\A[1-9][0-9]{0,11}(?:\.[0-9]{1,6})?\z/D',$qty))throw new BusinessError('historical_quantity_ambiguous');
     $unit=self::amount($r['unitPrice']??null,true);$precise=bcmul($qty,$unit,8);$total=bcmul($qty,$unit,2);if(bccomp($precise,$total,8)!==0||bccomp($total,'0',2)<=0)throw new BusinessError('historical_total_requires_rounding');
     if(isset($r['total'])&&self::amount($r['total'])!==$total)throw new BusinessError('historical_line_total_mismatch');
     $commands[]=['kind'=>'sale_register','sale_id'=>$r['id'],'customer_id'=>($r['customerId']??'')?:null,'amount'=>$total,'currency'=>$currency,'date'=>$date,'settle_now'=>false,'lines'=>[['product_id'=>($r['productId']??'')?:null,'version_id'=>($r['versionId']??'')?:null,'description'=>$r['concept']??'','quantity'=>$qty,'unit_price'=>$unit,'line_total'=>$total]],'record'=>$r];
     $sum='0.00';foreach($links as $t){$m=$t['record'];if(($m['type']??null)!=='cobro'||($m['accountFrom']??'')!==''||($m['expenseId']??'')!==''||($m['currency']??null)!==$currency||self::date($m['date']??null)<$date)throw new BusinessError('historical_collection_link_ambiguous');$cash=$this->cash($m);$sum=bcadd($sum,$cash['amount'],2);$commands[]=['kind'=>'sale_collect','sale_id'=>$r['id'],'account_id'=>self::id($m['accountTo']??null),'expected_version'=>count($commands),...$cash];}
     if(bccomp($sum,$total,2)>0||(($r['payStatus']==='cobrado')!==(bccomp($sum,$total,2)===0)))throw new BusinessError('historical_collection_evidence_incomplete');
    }else{
     if(!in_array($r['status']??null,['pendiente','pagado'],true))throw new BusinessError('historical_expense_status_ambiguous');
     $total=self::amount($r['amount']??null);$commands[]=['kind'=>'expense_register','expense_id'=>$r['id'],'amount'=>$total,'currency'=>$currency,'date'=>$date,'supplier_id'=>($r['supplierId']??'')?:null,'record'=>$r];$sum='0.00';
     foreach($links as $t){$m=$t['record'];if(($m['type']??null)!=='pago'||($m['accountTo']??'')!==''||($m['saleId']??'')!==''||($m['currency']??null)!==$currency||self::date($m['date']??null)<$date)throw new BusinessError('historical_payment_link_ambiguous');$cash=$this->cash($m);$sum=bcadd($sum,$cash['amount'],2);$commands[]=['kind'=>'expense_pay','expense_id'=>$r['id'],'account_id'=>self::id($m['accountFrom']??null),'expected_version'=>count($commands),...$cash];}
     if(bccomp($sum,$total,2)>0||(($r['status']==='pagado')!==(bccomp($sum,$total,2)===0)))throw new BusinessError('historical_payment_evidence_incomplete');
     if($r['status']==='pagado'&&(!isset($r['payDate'],$r['accountId'])||count($links)!==1||$r['payDate']!==reset($links)['record']['date']||$r['accountId']!==reset($links)['record']['accountFrom']))throw new BusinessError('historical_payment_details_disagree');
    }
    foreach($links as $tk=>$t)if(isset($duplicates['treasury:'.$t['record']['id']]))throw new BusinessError('duplicate_historical_movement');
    $groups[]=$this->group($env,$key,$commands,[$key=>$entry,...$links]);foreach($links as $tk=>$t)$used[$tk]=true;
   }catch(\Throwable $x){$pending[]=['key'=>$key,'reason'=>$x instanceof BusinessError?$x->reason:'historical_contract_ambiguous'];}
  }
  foreach($all as $key=>$entry){$r=$entry['record'];if($entry['row']['collection']!=='treasury'||isset($used[$key]))continue;
   try{if(isset($duplicates['treasury:'.$r['id']]))throw new BusinessError('duplicate_historical_movement');if(($r['saleId']??'')!==''||($r['expenseId']??'')!=='')throw new BusinessError('historical_linked_entity_not_convertible');
    $cash=$this->cash($r);$kind=['aporte'=>'capital_contribution','retiro'=>'withdrawal','ajuste'=>'treasury_adjustment','transferencia'=>'transfer'][$r['type']??'']??null;if(!$kind)throw new BusinessError('historical_movement_kind_ambiguous');
    $out=$kind==='withdrawal'||$kind==='transfer';$account=self::id($r[$out?'accountFrom':'accountTo']??null);
    if($kind!=='transfer'&&($r[$out?'accountTo':'accountFrom']??'')!=='')throw new BusinessError('historical_movement_direction_ambiguous');
    $cmd=['kind'=>$kind,'account_id'=>$account,...$cash];
    if($kind==='transfer'){$cmd+=['destination_account_id'=>self::id($r['accountTo']??null),'destination_currency'=>self::id($r['currencyTo']??null),'destination_amount'=>self::amount($r['amountTo']??null),'reference_id'=>$r['id']];unset($cmd['movement_id']);$cmd['source_movement_id']=$r['id'];$cmd['destination_movement_id']='hist_'.substr(hash('sha256',$r['id']),0,40);}
    $groups[]=$this->group($env,$key,[$cmd],[$key=>$entry]);
   }catch(\Throwable $x){$pending[]=['key'=>$key,'reason'=>$x instanceof BusinessError?$x->reason:'historical_contract_ambiguous'];}
  }
  return ['environment'=>$env,'candidates'=>$groups,'pending'=>$pending];
 }
 private function group(string $env,string $key,array $commands,array $evidence):array{
  $refs=[];foreach($evidence as $k=>$v)$refs[]=['key'=>$k,'sha256'=>$v['row']['content_hash'],'source_path'=>$v['row']['source_path']];
  $result=[];foreach($commands as $i=>$command){$command['_historical']=['group'=>$key,'evidence'=>$refs];$result[]=['operation_id'=>'hist_'.substr(hash('sha256',$env.':'.$key.':'.$i),0,60),'command'=>$command];}
  return ['key'=>$key,'evidence'=>$refs,'commands'=>$result];
 }
 public function preview(string $env,int $actor):array{
  $plan=$this->build($env);$accepted=[];$service=new FinancialService($this->pdo);
  foreach($plan['candidates'] as $group){try{$service->executeBatch($env,$actor,$group['commands'],true);$accepted[]=$group;}catch(BusinessError $x){$plan['pending'][]=['key'=>$group['key'],'reason'=>$x->reason];}}
  $plan['candidates']=$accepted;$plan['confirmation_sha256']=hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR));$plan['synthetic_only']=true;return $plan;
 }
 private function compare(string $env,array $commands,array $results):array{
  $checks=[];foreach($commands as $i=>$entry){$c=$entry['command'];$result=$results[$i]['result'];$kind=$c['kind'];
   $check=function(string $table,string $id,array $expected)use($env,&$checks){$rows=$this->q("SELECT * FROM $table WHERE environment_id=? AND id=?",[$env,$id]);if(count($rows)!==1)throw new BusinessError('historical_conversion_comparison_failed',409);foreach($expected as $field=>$value){$actual=$rows[0][$field];$equal=in_array($field,['total','amount'],true)?bccomp((string)$actual,(string)$value,2)===0:$actual===$value;if(!$equal)throw new BusinessError('historical_conversion_comparison_failed',409);}$checks[]=['table'=>$table,'id'=>$id,'fields'=>array_keys($expected),'matched'=>true];};
   if($kind==='sale_register')$check('fin_sales',$c['sale_id'],['total'=>$c['amount'],'currency_id'=>$c['currency'],'issued_on'=>$c['date'],'customer_id'=>$c['customer_id']]);
   elseif($kind==='expense_register')$check('fin_expenses',$c['expense_id'],['total'=>$c['amount'],'currency_id'=>$c['currency'],'incurred_on'=>$c['date'],'supplier_id'=>$c['supplier_id']]);
   elseif($kind==='transfer'){foreach([['source_movement_id','account_id','amount','currency','out'],['destination_movement_id','destination_account_id','destination_amount','destination_currency','in']] as [$id,$account,$amount,$currency,$direction])$check('fin_movements',$result[$id],['account_id'=>$c[$account],'amount'=>$c[$amount],'currency_id'=>$c[$currency],'movement_on'=>$c['date'],'direction'=>$direction,'reference_id'=>$c['reference_id']]);}
   else $check('fin_movements',$result['movement_id'],['account_id'=>$c['account_id'],'amount'=>$c['amount'],'currency_id'=>$c['currency'],'movement_on'=>$c['date'],'direction'=>in_array($kind,['expense_pay','withdrawal'],true)?'out':'in']);
  }return ['matched'=>true,'records'=>$checks];
 }
 public function confirm(string $env,int $actor,string $hash,array $selected):array{
  $plan=$this->preview($env,$actor);if(!hash_equals($plan['confirmation_sha256'],$hash)||!$selected||count(array_unique($selected))!==count($selected))throw new BusinessError('historical_preview_conflict',409);
  $evidence=[];$commands=[];foreach($selected as $key){$found=false;foreach($plan['candidates'] as $group)if($group['key']===$key){$found=true;array_push($evidence,...$group['evidence']);array_push($commands,...$group['commands']);}if(!$found)throw new BusinessError('historical_selection_not_convertible',409);}
  $comparison=[];$results=(new FinancialService($this->pdo))->executeBatch($env,$actor,$commands,false,function()use($env,$evidence){foreach($evidence as $ref){[$collection,$id]=explode(':',$ref['key'],2);$rows=$this->q('SELECT payload,content_hash,import_status FROM original_financial_snapshots WHERE environment_id=? AND collection=? AND id=? FOR UPDATE',[$env,$collection,$id]);if(count($rows)!==1||$rows[0]['import_status']!=='historical'||!hash_equals($ref['sha256'],$rows[0]['content_hash'])||!hash_equals($ref['sha256'],hash('sha256',$rows[0]['payload'])))throw new BusinessError('historical_preview_conflict',409);}},function($results)use($env,$commands,&$comparison){$comparison=$this->compare($env,$commands,$results);});return ['results'=>$results,'comparison'=>$comparison,'state'=>(new FinancialState($this->pdo))->snapshot($env),'pending'=>$plan['pending']];
 }
}
