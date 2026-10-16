<?php
declare(strict_types=1);
namespace Centro;

/** Exact decimal inputs only; no binary float coercion or implicit monetary rounding. */
final class Configuration
{
    public const TYPES=[
        'dataset_settings'=>['schema_version','setup_done','source_created_at','timezone','upcoming_days','no_sales_days','drop_pct','hourly_rate','hourly_currency'],
        'exchange_rates'=>['currency_id','base_currency_id','business_date','rate','source']
    ];
    public const REFS=['dataset_settings'=>['hourly_currency'=>'currencies'],
        'exchange_rates'=>['currency_id'=>'currencies','base_currency_id'=>'currencies']];
    public static function decimal(mixed $value,bool $positive=false): string
    {
        if(!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]{0,19})(?:\.[0-9]{1,18})?\z/D',$value))throw new BusinessError('invalid_exact_decimal');
        if($positive && !preg_match('/[1-9]/',$value))throw new BusinessError('positive_decimal_required');
        [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
        return $whole.'.'.str_pad($fraction,18,'0');
    }
    public static function validate(string $resource,string $id,array $data,?array $old): array
    {
        if(array_diff(array_keys($data),self::TYPES[$resource]))throw new BusinessError('unknown_field');
        if($resource==='dataset_settings' && $id!=='main')throw new BusinessError('invalid_settings');
        $row=[];
        foreach(self::TYPES[$resource] as $field){
            $v=array_key_exists($field,$data)?$data[$field]:($old[$field]??null);
            if($v===null){
                if($resource==='exchange_rates' && $field!=='source')throw new BusinessError('required_field_missing');
                $row[$field]=null;continue;
            }
            if(in_array($field,['schema_version','setup_done'],true)){
                if(!in_array($v,$field==='schema_version'?[1]:[0,1],true))throw new BusinessError('invalid_metadata');
            }elseif(in_array($field,['rate','hourly_rate','upcoming_days','no_sales_days','drop_pct'],true)){
                $v=self::decimal($v,$field!=='hourly_rate');
                if($field==='drop_pct' && (strlen(explode('.',$v)[0])>3 || (int)$v>100 || ((int)$v===100 && trim(explode('.',$v)[1],'0')!=='')))throw new BusinessError('invalid_threshold');
            }else{
                if(!is_string($v) || $v!==trim($v) || !preg_match('//u',$v))throw new BusinessError('invalid_field');
                if($field==='source_created_at')$v=Catalogs::timestamp($v);
                elseif($field==='business_date'){
                    $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
                    if(!$d || $d->format('Y-m-d')!==$v || substr($v,0,4)<'1000')throw new BusinessError('invalid_date');
                }elseif($field==='timezone'){
                    if(!in_array($v,\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),true))throw new BusinessError('invalid_timezone');
                }elseif($field==='source'){
                    if(preg_match_all('/./us',$v)>255)throw new BusinessError('field_too_long');
                }elseif(!preg_match('/\A[A-Z]{3,5}\z/D',$v))throw new BusinessError('invalid_currency');
            }
            $row[$field]=$v;
        }
        if($resource==='exchange_rates' && $old)foreach(['currency_id','base_currency_id','business_date'] as $field)
            if($row[$field]!==$old[$field])throw new BusinessError('immutable_quote_identity',409);
        return $row;
    }
    public static function validateAll(\PDO $pdo,string $env): void
    {
        $q=$pdo->prepare("SELECT r.id FROM exchange_rates r LEFT JOIN business_settings s ON s.environment_id=r.environment_id AND s.id='main' WHERE r.environment_id=? AND (s.base_currency IS NULL OR r.base_currency_id<>s.base_currency) LIMIT 1");$q->execute([$env]);
        if($q->fetch())throw new BusinessError('exchange_base_conflict',409);
    }
}
