<?php
declare(strict_types=1);
namespace Centro;

/** Normalized internal DTOs; no browser storage adapter. */
final class Catalogs
{
    public const TYPES=[
        'product_categories'=>['brand_id'=>'ref','name'=>'name','sort_order'=>'int'],
        'formats'=>['name'=>'name','sort_order'=>'int'],
        'languages'=>['name'=>'name','sort_order'=>'int'],
        'payment_methods'=>['name'=>'name','sort_order'=>'int'],
        'expense_groups'=>['name'=>'name','sort_order'=>'int'],
        'expense_categories'=>['group_id'=>'ref','name'=>'name','sort_order'=>'int'],
        'plans'=>['project_id'=>'ref','name'=>'name','status'=>'plan_status','trial_days'=>'int','sort_order'=>'int'],
        'plan_prices'=>['plan_id'=>'ref','valid_from'=>'?date','valid_to'=>'?date','price'=>'money','currency_id'=>'ref','period'=>'period','provenance'=>'provenance'],
        'products'=>['brand_id'=>'ref','project_id'=>'?ref','category_id'=>'?ref','category_label_snapshot'=>'text','name'=>'name','status'=>'product_status','launch_date'=>'?date','pending_updates'=>'long','source_created_at'=>'?time','source_updated_at'=>'?time'],
        'product_versions'=>['product_id'=>'ref','format_id'=>'?ref','format_label'=>'name','language_id'=>'?ref','language_label'=>'text','version_label'=>'version','price'=>'money','currency_id'=>'ref','sort_order'=>'int'],
        'product_links'=>['product_id'=>'ref','link_value'=>'link','sort_order'=>'int'],
        'bundle_items'=>['bundle_product_id'=>'ref','component_product_id'=>'ref','sort_order'=>'int'],
        'product_channels'=>['product_id'=>'ref','channel_id'=>'ref','sort_order'=>'int'],
        'product_version_channels'=>['version_id'=>'ref','channel_id'=>'ref','sort_order'=>'int'],
        'project_user_stats'=>['project_id'=>'ref','business_date'=>'date','sequence_no'=>'int','registered'=>'int','active'=>'int','origin'=>'?origin'],
        'product_version_history'=>['version_id'=>'ref','revision'=>'int','product_id'=>'ref','format_id'=>'?ref','format_label'=>'name','language_id'=>'?ref','language_label'=>'text','version_label'=>'version','price'=>'money','currency_id'=>'ref','sort_order'=>'int','version_archived_at'=>'?time']
    ];
    public const REFS=[
        'product_categories'=>['brand_id'=>'brands'],
        'expense_categories'=>['group_id'=>'expense_groups'],
        'plans'=>['project_id'=>'projects'],
        'plan_prices'=>['plan_id'=>'plans','currency_id'=>'currencies'],
        'products'=>['brand_id'=>'brands','project_id'=>'projects','category_id'=>'product_categories'],
        'product_versions'=>['product_id'=>'products','format_id'=>'formats','language_id'=>'languages','currency_id'=>'currencies'],
        'product_links'=>['product_id'=>'products'],
        'bundle_items'=>['bundle_product_id'=>'products','component_product_id'=>'products'],
        'product_channels'=>['product_id'=>'products','channel_id'=>'channels'],
        'product_version_channels'=>['version_id'=>'product_versions','channel_id'=>'channels'],
        'project_user_stats'=>['project_id'=>'projects'],
        'product_version_history'=>['version_id'=>'product_versions','product_id'=>'products','format_id'=>'formats','language_id'=>'languages','currency_id'=>'currencies']
    ];
    public static function validate(string $resource,array $data,?array $old,bool $nullableHistory=false): array
    {
        $types=self::TYPES[$resource];
        if($nullableHistory && $resource==='plans')$types['trial_days']='?int';
        if(array_diff(array_keys($data),array_keys($types)))throw new BusinessError('unknown_field');
        $row=[];
        foreach($types as $field=>$type){
            $v=array_key_exists($field,$data)?$data[$field]:($old[$field]??($type==='int'?0:(str_starts_with($type,'?')?null:'')));
            if(str_starts_with($type,'?')){if($v===null || $v===''){$row[$field]=null;continue;}$type=substr($type,1);}
            if($type==='int'){
                // PDO may return previously stored integer values as strings.
                if(!array_key_exists($field,$data) && is_string($v) && ctype_digit($v))$v=(int)$v;
                if(!is_int($v) || $v<0 || $v>2147483647)throw new BusinessError('invalid_integer');
            } else {
                if(!is_string($v) || !preg_match('//u',$v))throw new BusinessError('invalid_field');
                $v=trim($v);$max=match($type){'long'=>16000,'link'=>4096,'version'=>64,default=>160};
                if(preg_match_all('/./us',$v)>$max)throw new BusinessError('field_too_long');
                if(in_array($type,['name','link'],true) && $v==='')throw new BusinessError('name_required');
                if($type==='ref' && !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$v))throw new BusinessError('invalid_reference');
                if($type==='money' && !preg_match('/\A(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,2})?\z/D',$v))throw new BusinessError('invalid_decimal');
                if($type==='date'){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d || $d->format('Y-m-d')!==$v || substr($v,0,4)<'1000')throw new BusinessError('invalid_date');}
                if($type==='time'){
                    $v=self::timestamp($v);
                }
                $enums=['plan_status'=>['activa','retirada'],'product_status'=>['borrador','publicado','actualizar','pausado','retirado'],'period'=>['mensual','anual'],'provenance'=>['known','reconstructed','unknown'],'origin'=>['manual','importado','integracion','estimado']];
                if(isset($enums[$type]) && !in_array($v,$enums[$type],true))throw new BusinessError('invalid_status');
            }
            $row[$field]=$v;
        }
        if($resource==='plan_prices'){
            if($old && array_diff(array_keys($data),['valid_to']))throw new BusinessError('immutable_price',409);
            if($old && $old['valid_to']!==null && ($row['valid_to']??null)!==$old['valid_to'])throw new BusinessError('immutable_price',409);
            if(($row['valid_from']===null && $row['provenance']!=='unknown') || ($row['valid_to']!==null && ($row['valid_from']===null || $row['valid_to']<=$row['valid_from'])))throw new BusinessError('invalid_price_interval');
        }
        $immutable=['plans'=>['project_id'],'product_versions'=>['product_id'],'product_categories'=>['brand_id'],'expense_categories'=>['group_id'],'product_links'=>['product_id'],'bundle_items'=>['bundle_product_id','component_product_id'],'product_channels'=>['product_id','channel_id'],'product_version_channels'=>['version_id','channel_id']];
        foreach($immutable[$resource]??[] as $parent)if($old && $old[$parent]!==$row[$parent])throw new BusinessError('immutable_parent',409);
        return $row;
    }
    public static function timestamp(string $v): string
    {
        try {
            if(!preg_match('/\A\d{4}-\d\d-\d\d(?:T| )\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)?\z/D',$v))throw new \RuntimeException();
            $d=new \DateTimeImmutable($v,new \DateTimeZone('UTC'));
            if(\DateTimeImmutable::getLastErrors()!==false || $d->format('Y')<'1000')throw new \RuntimeException();
            return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        }catch(\Throwable){throw new BusinessError('invalid_timestamp');}
    }
    public static function validateAll(\PDO $pdo,string $env): void
    {
        $q=$pdo->prepare('SELECT p.id FROM products p JOIN product_categories c ON c.environment_id=p.environment_id AND c.id=p.category_id WHERE p.environment_id=? AND p.brand_id<>c.brand_id LIMIT 1');$q->execute([$env]);
        if($q->fetch())throw new BusinessError('category_brand_mismatch');
        $q=$pdo->prepare('SELECT plan_id,valid_from,valid_to FROM plan_prices WHERE environment_id=? ORDER BY plan_id,valid_from,id');$q->execute([$env]);$groups=[];
        foreach($q->fetchAll() as $p)$groups[$p['plan_id']][]=$p;
        foreach($groups as $prices)foreach($prices as $i=>$a)foreach(array_slice($prices,$i+1) as $b){
            // Unknown legacy dates are retained without inventing chronology. Only one
            // unknown interval may exist; known intervals still cannot overlap.
            if($a['valid_from']===null || $b['valid_from']===null){if($a['valid_from']===null && $b['valid_from']===null)throw new BusinessError('price_overlap',409);continue;}
            if(($a['valid_to']===null || $b['valid_from']<$a['valid_to']) && ($b['valid_to']===null || $a['valid_from']<$b['valid_to']))throw new BusinessError('price_overlap',409);
        }
        $q=$pdo->prepare('SELECT bundle_product_id,component_product_id FROM bundle_items WHERE environment_id=? AND archived_at IS NULL');$q->execute([$env]);$graph=[];
        foreach($q->fetchAll() as $e)$graph[$e['bundle_product_id']][]=$e['component_product_id'];
        $seen=[];$visiting=[];
        $visit=function($id)use(&$visit,&$seen,&$visiting,$graph){if(isset($visiting[$id]))throw new BusinessError('bundle_cycle',409);if(isset($seen[$id]))return;$visiting[$id]=true;foreach($graph[$id]??[] as $next)$visit($next);unset($visiting[$id]);$seen[$id]=true;};
        foreach(array_keys($graph) as $id)$visit($id);
    }
    public static function snapshotVersion(\PDO $pdo,string $env,array $row): void
    {
        $data=['version_id'=>$row['id'],'revision'=>$row['row_version']];
        foreach(self::TYPES['product_versions'] as $field=>$_)$data[$field]=$row[$field];
        $data['version_archived_at']=$row['archived_at'];
        $keys=implode(',',array_keys($data));$marks=implode(',',array_fill(0,count($data)+2,'?'));
        $q=$pdo->prepare("INSERT INTO product_version_history(environment_id,id,$keys) VALUES($marks)");
        $q->execute([$env,hash('sha256',$row['id'].':'.$row['row_version']),...array_values($data)]);
    }
}
