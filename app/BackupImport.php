<?php
declare(strict_types=1);
namespace Centro;
require_once __DIR__.'/Business.php';

/** CLI-only synthetic staging. No HTTP entry point, active dataset or browser adapter. */
final class BackupImport
{
    public const TABLES=['currencies','brands','channels','projects','customers','accounts','business_settings',
        'product_categories','formats','languages','payment_methods','expense_groups','expense_categories',
        'plans','plan_prices','products','product_versions','product_links','bundle_items','product_channels',
        'product_version_channels','project_user_stats','product_version_history'];
    public const ACTIVITY_TABLES=['subscriptions','subscription_events','time_logs','integrations','suppliers','original_financial_snapshots'];
    private Business $business;
    private array $tables;
    public function __construct(private \PDO $pdo,private array $config,private array $marker)
    {
        if(PHP_SAPI!=='cli' || $config['environment']!=='local' || $config['db']['host']!=='127.0.0.1'
            || !preg_match('/\Acentro_auth_[0-9a-f]{12}\z/D',$config['db']['name'])
            || ($marker['database']??null)!==$config['db']['name'] || ($marker['purpose']??null)!=='synthetic-backup-import'
            || ($marker['port']??null)!==$config['db']['port'])throw new BusinessError('isolated_import_target_required',403);
        foreach(glob(dirname(__DIR__).'/database/migrations/00[1-4]_*.sql') as $path){
            $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([basename($path,'.sql')]);
            if(!hash_equals(hash_file('sha256',$path),(string)$q->fetchColumn()))throw new BusinessError('migration_checksum_mismatch',409);
        }
        $this->business=new Business($pdo);
        if(!$this->business->extended())throw new BusinessError('migration_required',409);
        $block4=in_array(($marker['profile']??'block3'),['block4','block7'],true);
        if($block4!==$this->business->configuration())throw new BusinessError('import_profile_mismatch');
        $activity=($marker['profile']??'')==='block7';
        if($activity){
            $path=dirname(__DIR__).'/database/migrations/012_administrative_activity.sql';
            $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute(['012_administrative_activity']);
            if(!hash_equals(hash_file('sha256',$path),(string)$q->fetchColumn()))throw new BusinessError('migration_checksum_mismatch');
            $path=dirname(__DIR__).'/database/migrations/014_original_financial_snapshots.sql';
            $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute(['014_original_financial_snapshots']);
            if(!hash_equals(hash_file('sha256',$path),(string)$q->fetchColumn()))throw new BusinessError('migration_checksum_mismatch');
            $path=dirname(__DIR__).'/database/migrations/013_activity_source_metadata.sql';
            $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute(['013_activity_source_metadata']);
            if(!hash_equals(hash_file('sha256',$path),(string)$q->fetchColumn()))throw new BusinessError('migration_checksum_mismatch');
        }
        $this->tables=[...self::TABLES,...($block4?array_keys(Configuration::TYPES):[]),...($activity?self::ACTIVITY_TABLES:[])];
        if($block4)foreach(['005_configuration_rates','006_historical_absence'] as $migration){
            $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$migration]);
            if(!hash_equals(hash_file('sha256',dirname(__DIR__).'/database/migrations/'.$migration.'.sql'),(string)$q->fetchColumn()))throw new BusinessError('migration_checksum_mismatch');
        }
        $engines=$this->q('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll();
        $required=[...$this->tables,'environments','business_operations','business_audit'];
        foreach($engines as $table)if(in_array($table['TABLE_NAME'],$required,true) && $table['ENGINE']!=='InnoDB')throw new BusinessError('transactional_schema_required');
    }
    private function q(string $sql,array $args=[]): \PDOStatement {$q=$this->pdo->prepare($sql);$q->execute($args);return $q;}
    private function lock(): void
    {
        if((int)$this->q('SELECT GET_LOCK(?,10)',['backup-import:'.$this->config['db']['name']])->fetchColumn()!==1)throw new BusinessError('import_busy',409);
    }
    private function unlock(): void {$this->q('SELECT RELEASE_LOCK(?)',['backup-import:'.$this->config['db']['name']]);}

    /** Full validation (including real MariaDB collation) before the first INSERT. */
    public function preflight(array $plan): array
    {
        if(!in_array($plan['environment']??null,['produccion','pruebas'],true)
            || !preg_match('/\A[0-9a-f]{64}\z/D',$plan['source_sha256']??'')
            || array_keys($plan['rows']??[])!==$this->tables)throw new BusinessError('invalid_import_plan');
        $unique=['brands'=>[['name']],'channels'=>[['name']],'projects'=>[['name']],'customers'=>[['email']],
            'product_categories'=>[['brand_id','name']],'formats'=>[['name']],'languages'=>[['name']],
            'payment_methods'=>[['name']],'expense_groups'=>[['name']],'expense_categories'=>[['group_id','name']],
            'plans'=>[['project_id','name']],'products'=>[['brand_id','name']],
            'product_versions'=>[['product_id','format_label','language_label','version_label']],
            'bundle_items'=>[['bundle_product_id','component_product_id']],
            'product_channels'=>[['product_id','channel_id']],'product_version_channels'=>[['version_id','channel_id']],
            'project_user_stats'=>[['project_id','business_date','sequence_no']],
            'product_version_history'=>[['version_id','revision']]];
        $unique['exchange_rates']=[['currency_id','business_date']];
        $unique['subscriptions']=[['external_id']];$unique['integrations']=[['name']];$unique['suppliers']=[['name']];
        $seen=[];$weight=[];$normalized=[];
        foreach($this->tables as $table){
            $ids=[];$normalized[$table]=[];
            foreach($plan['rows'][$table] as $source){
                $id=$source['id']??'';$hasArchive=!in_array($table,['subscription_events'],true);$archived=$hasArchive?($source['archived_at']??null):null;
                if(isset($ids[$id]))throw new BusinessError('duplicate_import_id');$ids[$id]=true;
                unset($source['id'],$source['archived_at']);
                $data=$this->business->validateImportRow($table,$id,$source);
                if($archived!==null)$archived=Catalogs::timestamp($archived);
                // Never silently normalize a supplied value beyond the documented projection.
                foreach($source as $key=>$value)if(($data[$key]??null)!==$value && !($value===null && ($data[$key]??null)===null))throw new BusinessError('unreported_normalization');
                $row=['id'=>$id,...$data];if($hasArchive)$row['archived_at']=$archived;$normalized[$table][]=$row;
                foreach($unique[$table]??[] as $index=>$columns){
                    $parts=[];$skip=false;
                    foreach($columns as $column){
                        $value=$row[$column];if($value===null){$skip=true;break;}
                        // utf8mb4_bin FK/ID fields retain binary identity; names use actual SQL weights.
                        if($column==='name' || in_array($column,['email','format_label','language_label','version_label'],true)){
                            $key=(string)$value;
                            if(!isset($weight[$key]))$weight[$key]=$this->q('SELECT HEX(WEIGHT_STRING(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci))',[$key])->fetchColumn();
                            $value=$weight[$key];
                        }
                        $parts[]=$value;
                    }
                    if(!$skip){$key=$table.':'.$index.':'.json_encode($parts,JSON_THROW_ON_ERROR);if(isset($seen[$key]))throw new BusinessError('duplicate_normalized_key');$seen[$key]=true;}
                }
            }
        }
        // All FKs checked in memory before insertion, including historical snapshots.
        $refs=['projects'=>['brand_id'=>'brands','investment_currency'=>'currencies'],'accounts'=>['currency_id'=>'currencies'],
            'business_settings'=>['base_currency'=>'currencies'],'subscriptions'=>['project_id'=>'projects','plan_id'=>'plans','customer_id'=>'customers'],
            'subscription_events'=>['subscription_id'=>'subscriptions','plan_id'=>'plans','currency_id'=>'currencies'],
            'time_logs'=>['project_id'=>'projects'],'integrations'=>['project_id'=>'projects']]+Catalogs::REFS+($this->business->configuration()?Configuration::REFS:[]);
        $by=[];foreach($normalized as $t=>$rows)foreach($rows as $row)$by[$t][$row['id']]=$row;
        foreach($normalized as $t=>$rows)foreach($rows as $row)foreach($refs[$t]??[] as $field=>$parent){
            $id=$row[$field];if($id===null)continue;
            if(!isset($by[$parent][$id]))throw new BusinessError('missing_import_reference');
            if($by[$parent][$id]['archived_at']!==null && $row['archived_at']===null && !in_array($t,['product_version_history','plan_prices'],true))throw new BusinessError('archived_import_reference');
        }
        // Preflight graph/category/price rules without creating even temporary target rows.
        $graph=[];$visiting=[];$visited=[];$prices=[];
        foreach($normalized['products'] as $row)if($row['category_id']!==null && $by['product_categories'][$row['category_id']]['brand_id']!==$row['brand_id'])throw new BusinessError('category_brand_mismatch');
        foreach($normalized['bundle_items'] as $row)$graph[$row['bundle_product_id']][]=$row['component_product_id'];
        $visit=function($id)use(&$visit,&$visiting,&$visited,$graph){if(isset($visiting[$id]))throw new BusinessError('bundle_cycle');if(isset($visited[$id]))return;$visiting[$id]=true;foreach($graph[$id]??[] as $child)$visit($child);unset($visiting[$id]);$visited[$id]=true;};
        foreach(array_keys($graph) as $id)$visit($id);
        foreach($normalized['plan_prices'] as $row)$prices[$row['plan_id']][]=$row;
        foreach($prices as $rows)foreach($rows as $i=>$a)foreach(array_slice($rows,$i+1) as $b){
            if($a['valid_from']===null || $b['valid_from']===null){if($a['valid_from']===null && $b['valid_from']===null)throw new BusinessError('price_overlap');continue;}
            if(($a['valid_to']===null || $b['valid_from']<$a['valid_to']) && ($b['valid_to']===null || $a['valid_from']<$b['valid_to']))throw new BusinessError('price_overlap');
        }
        foreach($normalized['exchange_rates']??[] as $row)
            if(($by['business_settings']['main']['base_currency']??null)!==$row['base_currency_id'])throw new BusinessError('exchange_base_conflict');
        return $normalized;
    }

    private function backup(string $path): string
    {
        $file=realpath($path);$root=realpath(dirname(__DIR__).'/.runtime');
        $norm=static fn($s)=>strtolower(str_replace('\\','/',$s));
        if(!$file || !$root || !str_starts_with($norm($file),$norm($root).'/') || filesize($file)<100)throw new BusinessError('private_backup_required');
        $m=json_decode(file_get_contents($file.'.json'),true,16,JSON_THROW_ON_ERROR);$hash=hash_file('sha256',$file);
        if(($m['database']??null)!==$this->config['db']['name'] || !hash_equals($m['sha256']??'',$hash))throw new BusinessError('backup_manifest_mismatch');
        return $hash;
    }
    public function import(array $plan,string $operation,string $backup,int $failAfter=0,bool $loseAck=false): array
    {
        if(!preg_match('/\Aimport_[A-Za-z0-9_-]{16,80}\z/D',$operation))throw new BusinessError('invalid_import_operation');
        $rows=$this->preflight($plan);$backupHash=$this->backup($backup);
        $hash=hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $env=$plan['environment'];$this->lock();
        try{
            $this->pdo->beginTransaction();
            $old=$this->q('SELECT payload_hash,result_json FROM business_operations WHERE environment_id=? AND operation_id=?',[$env,$operation])->fetch();
            if($old){
                if(!hash_equals($old['payload_hash'],$hash))throw new BusinessError('idempotency_conflict',409);
                $this->pdo->commit();return ['replayed'=>true,'result'=>json_decode($old['result_json'],true,128,JSON_THROW_ON_ERROR)];
            }
            // One complete partial-import dataset per target. No merge/overwrite path.
            foreach([...$this->tables,'environments','business_operations','business_audit','business_page_cursors'] as $table)
                if($this->pdo->query("SELECT 1 FROM $table LIMIT 1")->fetchColumn()!==false)throw new BusinessError('destination_not_empty',409);
            if(!$this->q('SELECT id FROM auth_users WHERE id=1 AND active=1')->fetchColumn())throw new BusinessError('isolated_administrator_required');
            $this->q('INSERT INTO environments(id,code,label,catalog_revision) VALUES(?,?,?,1)',[$env,$env,'Importacion sintetica aislada']);
            $n=0;
            foreach($this->tables as $table)foreach($rows[$table] as $row){
                $fields=implode(',',array_keys($row));$marks=implode(',',array_fill(0,count($row)+1,'?'));
                $this->q("INSERT INTO $table(environment_id,$fields) VALUES($marks)",[$env,...array_values($row)]);
                if($failAfter>0 && ++$n===$failAfter)throw new BusinessError('injected_import_failure');
            }
            Catalogs::validateAll($this->pdo,$env);
            if($this->business->configuration())Configuration::validateAll($this->pdo,$env);
            $result=['format'=>'original-v1-partial','source_sha256'=>$plan['source_sha256'],'backup_sha256'=>$backupHash,
                'counts'=>array_map('count',$rows),'mapping'=>$plan['mapping'],'pending'=>$plan['pending'],
                'complete_migration'=>false,'environment'=>$env,'profile'=>$this->marker['profile']??'block3',
                'precision_evidence'=>$plan['precision_evidence']??[]];
            $json=json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $this->q('INSERT INTO business_operations(environment_id,operation_id,actor_user_id,payload_hash,result_json) VALUES(?,?,1,?,?)',[$env,$operation,$hash,$json]);
            $this->q('INSERT INTO business_audit(environment_id,operation_id,actor_user_id,command_json) VALUES(?,?,1,?)',[$env,$operation,json_encode(['kind'=>'partial_backup_import','source_sha256'=>$plan['source_sha256'],'counts'=>$result['counts']],JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();
            if($loseAck)throw new BusinessError('injected_acknowledgement_loss');
            return ['replayed'=>false,'result'=>$result];
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        finally{$this->unlock();}
    }
    public function export(string $environment,string $operation): array
    {
        $this->lock();
        try{
            $this->pdo->beginTransaction();
            $environmentRow=$this->q('SELECT * FROM environments WHERE id=? FOR UPDATE',[$environment])->fetch();
            $receipt=$this->q('SELECT result_json FROM business_operations WHERE environment_id=? AND operation_id=?',[$environment,$operation])->fetchColumn();
            if(!$receipt)throw new BusinessError('import_receipt_not_found',404);
            $result=json_decode($receipt,true,128,JSON_THROW_ON_ERROR);
            if(($result['format']??null)!=='original-v1-partial')throw new BusinessError('not_import_receipt');
            $rows=[];
            foreach($this->tables as $table)$rows[$table]=$this->q("SELECT * FROM $table WHERE environment_id=? ORDER BY id",[$environment])->fetchAll();
            $this->pdo->commit();return ['receipt'=>$result,'rows'=>$rows,'environment'=>$environmentRow];
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        finally{$this->unlock();}
    }
}
