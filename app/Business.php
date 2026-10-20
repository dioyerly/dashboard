<?php
declare(strict_types=1);
namespace Centro;
require_once __DIR__.'/Catalogs.php';
require_once __DIR__.'/Configuration.php';

final class BusinessError extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 422) { parent::__construct($reason); }
}

/** Isolated foundation services. No frontend storage adapter and no financial commands yet. */
final class Business
{
    private const FIELDS = [
        'currencies'=>['symbol'],
        'brands'=>['name','color','dark','alt','description'],
        'channels'=>['name','commission_pct'],
        'projects'=>['name','brand_id','kind','status','launch_date','url','description','domain','repo','hosting','database_description','tech','milestone','initial_investment','investment_currency','notes'],
        'customers'=>['name','email','country','business_date','consent','notes','origin'],
        'accounts'=>['name','currency_id'],
        'business_settings'=>['name','owner','site','base_currency','num_format'],
        'subscriptions'=>['project_id','plan_id','customer_id','status','start_date','next_renewal','cancel_date','external_id','source_origin','source_origin_present','source_created_at','source_created_at_present'],
        'subscription_events'=>['subscription_id','event_date','event_type','plan_id','amount','currency_id','sale_id','note'],
        'time_logs'=>['project_id','logged_on','hours','kind','note'],
        'integrations'=>['name','kind','method','status','project_id','last_sync','errors_json','notes','source_stats','source_stats_present'],
        'suppliers'=>['name','kind','url','contact','tax_id','notes'],
        'original_financial_snapshots'=>['collection','ordinal','source_path','payload','content_hash','import_status','normalized_json'],
        'original_activity_snapshots'=>['collection','ordinal','source_path','payload','content_hash','import_status'],
    ];
    private const REFS = [
        'projects'=>['brand_id'=>'brands','investment_currency'=>'currencies'],
        'accounts'=>['currency_id'=>'currencies'], 'business_settings'=>['base_currency'=>'currencies'],
        'subscriptions'=>['project_id'=>'projects','plan_id'=>'plans','customer_id'=>'customers'],
        'subscription_events'=>['subscription_id'=>'subscriptions','plan_id'=>'plans','currency_id'=>'currencies'],
        'time_logs'=>['project_id'=>'projects'], 'integrations'=>['project_id'=>'projects'],
    ];
    private readonly bool $catalogs;
    private readonly bool $configuration;
    private readonly bool $activity;
    public function __construct(private readonly \PDO $pdo) {
        $pdo->exec("SET time_zone = '+00:00'");
        $q=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version IN (?,?)');$q->execute(['003_catalogs_products','004_project_stats_optional_origin']);$count=(int)$q->fetchColumn();
        if($count===1)throw new BusinessError('migration_required',409);
        $this->catalogs=$count===2;
        $q->execute(['005_configuration_rates','006_historical_absence']);$count=(int)$q->fetchColumn();
        if($count===1)throw new BusinessError('migration_required',409);
        $this->configuration=$count===2;
        $activityQuery=$this->pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');$activityQuery->execute(['012_administrative_activity']);$this->activity=(int)$activityQuery->fetchColumn()===1;
    }
    public function extended(): bool { return $this->catalogs; }
    public function configuration(): bool { return $this->configuration; }
    private function fields(): array {
        $fields=self::FIELDS;
        if($this->catalogs)$fields['projects']=[...$fields['projects'],'source_created_at','source_updated_at'];
        if(!$this->activity)foreach(['subscriptions','subscription_events','time_logs','integrations','suppliers'] as $k)unset($fields[$k]);
        return $fields+($this->catalogs?array_map('array_keys',Catalogs::TYPES):[])+($this->configuration?Configuration::TYPES:[]);
    }
    private function refs(): array { $r=self::REFS+($this->catalogs?Catalogs::REFS:[])+($this->configuration?Configuration::REFS:[]); if(!$this->activity)foreach(['subscriptions','subscription_events','time_logs','integrations'] as $k)unset($r[$k]); return $r; }

    private static function identifier(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$id)) throw new BusinessError('invalid_id');
        return $id;
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) ksort($value);
            foreach ($value as &$item) $item=self::canonical($item);
        }
        return $value;
    }

    private function query(string $sql, array $args = []): \PDOStatement
    {
        $q=$this->pdo->prepare($sql); $q->execute($args); return $q;
    }

    private function resource(string $resource): void
    {
        if (!isset($this->fields()[$resource])) throw new BusinessError('unknown_resource',404);
    }

    public function list(string $environment, string $resource, bool $includeArchived = false): array
    {
        self::identifier($environment); $this->resource($resource);
        if (!$this->query('SELECT id FROM environments WHERE id=?',[$environment])->fetch()) throw new BusinessError('environment_not_found',404);
        // Bounded read contract for this block. Future export must use a paginated snapshot.
        $rows=$this->query("SELECT * FROM $resource WHERE environment_id=?".($includeArchived?'':' AND archived_at IS NULL').' ORDER BY id LIMIT 501',[$environment])->fetchAll();
        if (count($rows)>500) throw new BusinessError('listing_limit',413);
        return $rows;
    }

    /** Keyset order is immutable binary ID. A changed revision rejects, never mixes pages. */
    public function page(string $environment,string $resource,int $limit=100,?string $cursor=null,bool $archived=false,array $filters=[]): array
    {
        if(!$this->catalogs)throw new BusinessError('migration_required',409);
        self::identifier($environment);$this->resource($resource);
        if($limit<1 || $limit>500)throw new BusinessError('invalid_page_size');
        if(array_diff(array_keys($filters),array_keys($this->refs()[$resource]??[])))throw new BusinessError('invalid_filter');
        foreach($filters as $id)self::identifier($id);
        $hash=hash('sha256',json_encode(self::canonical([$environment,$resource,$limit,$archived,$filters]),JSON_THROW_ON_ERROR));
        if($this->pdo->inTransaction())throw new BusinessError('nested_transaction',409);
        $this->pdo->beginTransaction();
        try {
            $env=$this->query('SELECT catalog_revision FROM environments WHERE id=? FOR UPDATE',[$environment])->fetch();
            if(!$env)throw new BusinessError('environment_not_found',404);
            $last='';
            if($cursor!==null){
                if(!preg_match('/\A[0-9a-f]{64}\z/D',$cursor))throw new BusinessError('invalid_cursor');
                $old=$this->query('SELECT * FROM business_page_cursors WHERE id=? AND environment_id=? AND expires_at>UTC_TIMESTAMP(6)',[$cursor,$environment])->fetch();
                if(!$old || !hash_equals($old['query_hash'],$hash))throw new BusinessError('invalid_cursor');
                if((string)$old['revision']!==(string)$env['catalog_revision'])throw new BusinessError('pagination_conflict',409);
                $last=$old['last_id'];
            }
            $where='environment_id=? AND id>?';$args=[$environment,$last];
            if(!$archived)$where.=' AND archived_at IS NULL';
            foreach($filters as $field=>$id){$where.=" AND $field=?";$args[]=$id;}
            $rows=$this->query("SELECT * FROM $resource WHERE $where ORDER BY id LIMIT ".($limit+1),$args)->fetchAll();$next=null;
            if(count($rows)>$limit){
                array_pop($rows);$next=bin2hex(random_bytes(32));
                $this->query('INSERT INTO business_page_cursors(id,environment_id,query_hash,revision,last_id,expires_at) VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 MINUTE))',[$next,$environment,$hash,$env['catalog_revision'],$rows[count($rows)-1]['id']]);
            }
            $this->pdo->commit();return ['records'=>$rows,'next_cursor'=>$next,'revision'=>$env['catalog_revision'],'limit'=>$limit];
        } catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** Every command, its receipt and its audit row commit or roll back together. */
    public function atomic(string $environment, string $operation, int $actor, array $command, callable $work): array
    {
        self::identifier($environment);
        if (!preg_match('/\A[A-Za-z0-9_-]{16,100}\z/D',$operation)) throw new BusinessError('invalid_operation_id');
        if ($actor!==1) throw new BusinessError('forbidden',403);
        if ($this->pdo->inTransaction()) throw new BusinessError('nested_transaction',409);
        $json=json_encode(self::canonical($command),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $hash=hash('sha256',$json);
        $this->pdo->beginTransaction();
        try {
            // Personal-use app: one lock per environment serializes commands and receipts.
            if (!$this->query('SELECT id FROM environments WHERE id=? FOR UPDATE',[$environment])->fetch()) throw new BusinessError('environment_not_found',404);
            if (!$this->query('SELECT id FROM auth_users WHERE id=? AND active=1',[$actor])->fetch()) throw new BusinessError('forbidden',403);
            $old=$this->query('SELECT payload_hash,result_json,actor_user_id FROM business_operations WHERE environment_id=? AND operation_id=?',[$environment,$operation])->fetch();
            if ($old) {
                if (!hash_equals($old['payload_hash'],$hash) || (int)$old['actor_user_id']!==$actor) throw new BusinessError('idempotency_conflict',409);
                $this->pdo->commit(); return ['result'=>json_decode($old['result_json'],true,32,JSON_THROW_ON_ERROR),'replayed'=>true];
            }
            $result=$work();
            if($this->catalogs)$this->query('UPDATE environments SET catalog_revision=catalog_revision+1 WHERE id=?',[$environment]);
            $this->query('INSERT INTO business_operations(environment_id,operation_id,actor_user_id,payload_hash,result_json) VALUES (?,?,?,?,?)',
                [$environment,$operation,$actor,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            $this->query('INSERT INTO business_audit(environment_id,operation_id,actor_user_id,command_json) VALUES (?,?,?,?)',[$environment,$operation,$actor,$json]);
            $this->pdo->commit(); return ['result'=>$result,'replayed'=>false];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($error instanceof \PDOException && (string)$error->getCode()==='23000') throw new BusinessError('integrity_conflict',409);
            throw $error;
        }
    }

    public function command(string $environment, string $operation, int $actor, array $command): array
    {
        // A batch is one coherent transaction, including all dependency checks.
        if (array_keys($command)!==['changes'] || !is_array($command['changes']) || !array_is_list($command['changes']) || count($command['changes'])<1 || count($command['changes'])>50) throw new BusinessError('invalid_command');
        return $this->atomic($environment,$operation,$actor,$command,function() use($environment,$command): array {
            $rows=[];
            foreach ($command['changes'] as $change) {
                if (!is_array($change)) throw new BusinessError('invalid_change');
                $rows[]=$this->change($environment,$change);
            }
            if($this->catalogs)Catalogs::validateAll($this->pdo,$environment);
            if($this->configuration)Configuration::validateAll($this->pdo,$environment);
            return $rows;
        });
    }

    /** CLI staging uses the same row rules without applying a command or inventing history. */
    public function validateImportRow(string $resource, string $id, array $data): array
    {
        $this->resource($resource); self::identifier($id);
        return $this->validate($resource,$id,$data,null);
    }

    private function validate(string $resource, string $id, array $data, ?array $old): array
    {
        if(isset(Configuration::TYPES[$resource]))return Configuration::validate($resource,$id,$data,$old);
        if(isset(Catalogs::TYPES[$resource]))return Catalogs::validate($resource,$data,$old,$this->configuration);
        $fields=$this->fields()[$resource];
        if (array_diff(array_keys($data),$fields)) throw new BusinessError('unknown_field');
        $nullable=['commission_pct','initial_investment','investment_currency','email','launch_date','business_date','source_created_at','source_updated_at','source_origin','source_stats','next_renewal','cancel_date','external_id','plan_id','customer_id','event_date','amount','currency_id','sale_id','project_id','last_sync','normalized_json'];
        if($this->configuration && $resource==='customers')$nullable=[...$nullable,'consent','origin'];
        $row=[];
        foreach ($fields as $field) {
            $v=$data[$field]??($old[$field]??(in_array($field,$nullable,true)?null:($field==='consent'?0:'')));
            if (array_key_exists($field,$data)) $v=$data[$field];
            if (in_array($field,$nullable,true) && ($v===null || $v==='')) { $row[$field]=null; continue; }
            if (in_array($field,['source_origin_present','source_created_at_present','source_stats_present'],true)) { if(!in_array((string)$v,['0','1'],true)) throw new BusinessError('invalid_presence'); $row[$field]=(string)$v; continue; }
            if ($field==='consent') {
                if (!in_array($v,[0,1],true)) throw new BusinessError('invalid_consent');
            } else {
                if (!is_string($v) || !preg_match('//u',$v)) throw new BusinessError('invalid_field');
                $v=trim($v);
                $length=preg_match_all('/./us',$v);
                $max=in_array($field,['notes','description'],true)?16000:(in_array($field,['url','repo','site'],true)?2048:($field==='email'?254:($field==='name'||$field==='owner'?160:($field==='country'?120:255))));
                if ($length>$max) throw new BusinessError('field_too_long');
            }
            $row[$field]=$v;
        }
        if (isset($row['name']) && $row['name']==='') throw new BusinessError('name_required');
        if ($resource==='currencies' && (!preg_match('/\A[A-Z]{3,5}\z/D',$id) || $row['symbol']==='' || strlen($row['symbol'])>16)) throw new BusinessError('invalid_currency');
        if ($resource==='brands') foreach (['color','dark','alt'] as $field) if (!preg_match('/\A#[0-9a-fA-F]{6}\z/D',$row[$field])) throw new BusinessError('invalid_color');
        foreach (['commission_pct'=>[2,6],'initial_investment'=>[18,2]] as $field=>[$whole,$scale]) {
            if (!isset($row[$field])) continue;
            if (!preg_match('/\A(?:0|[1-9][0-9]{0,'.($whole-1).'})(?:\.[0-9]{1,'.$scale.'})?\z/D',$row[$field])) throw new BusinessError('invalid_decimal');
            if ($field==='commission_pct' && (int)$row[$field]>=100) throw new BusinessError('invalid_commission');
        }
        foreach (['launch_date','business_date'] as $field) if (isset($row[$field])) {
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$row[$field]);
            if (!$date || $date->format('Y-m-d')!==$row[$field] || substr($row[$field],0,4)<'1000') throw new BusinessError('invalid_date');
        }
        if ($resource==='customers') {
            if ($row['email']!==null) {
                $row['email']=strtolower($row['email']);
                if (!filter_var($row['email'],FILTER_VALIDATE_EMAIL)) throw new BusinessError('invalid_email');
            }
            if (!($this->configuration && $row['origin']===null) && !in_array($row['origin'],['manual','importado','integracion','estimado'],true)) throw new BusinessError('invalid_origin');
        }
        if ($resource==='integrations' && $row['errors_json']==='') $row['errors_json']='[]';
        if ($resource==='integrations') { if (!is_string($row['errors_json']) || json_decode($row['errors_json'],true)===null && $row['errors_json']!=='null') throw new BusinessError('invalid_errors'); if ($row['last_sync']!==null) $row['last_sync']=Catalogs::timestamp($row['last_sync']); if (!in_array($row['status'],['activa','error','pausada','sin_conectar'],true)) throw new BusinessError('invalid_integration_status'); }
        if ($resource==='time_logs') { if (!preg_match('/\A(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?\z/D',$row['hours']) || (float)$row['hours']<=0 || (float)$row['hours']>24) throw new BusinessError('invalid_hours'); if (!in_array($row['kind'],['desarrollo','mantenimiento','contenido','soporte'],true)) throw new BusinessError('invalid_time_kind'); }
        if ($resource==='subscriptions' && !in_array($row['status'],['activa','prueba','impaga','cancelada','pausada'],true)) throw new BusinessError('invalid_subscription_status');
        if ($resource==='subscription_events' && !in_array($row['event_type'],['alta','prueba','conversion','renovacion','pago_fallido','cancelacion','cambio_plan','reactivacion','pausa'],true)) throw new BusinessError('invalid_subscription_event');
        if ($resource==='projects') {
            foreach(['source_created_at','source_updated_at'] as $field)if(isset($row[$field]))$row[$field]=Catalogs::timestamp($row[$field]);
            if (!in_array($row['kind'],['descargable','pago_unico','suscripcion','desarrollo','interna'],true) || !in_array($row['status'],['idea','desarrollo','pruebas','publicada','pausada','retirada'],true)) throw new BusinessError('invalid_project_state');
            if ($row['initial_investment']!==null && $row['investment_currency']===null) throw new BusinessError('currency_required');
        }
        if ($resource==='business_settings' && ($id!=='main' || !in_array($row['num_format'],['es','en'],true))) throw new BusinessError('invalid_settings');
        foreach (['url','repo','site'] as $field) if (!empty($row[$field]) && (!filter_var($row[$field],FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($row[$field],PHP_URL_SCHEME)),['http','https'],true))) throw new BusinessError('invalid_url');
        return $row;
    }

    private function change(string $environment, array $change): array
    {
        if (array_diff(array_keys($change),['resource','action','id','expected_version','data'])) throw new BusinessError('unknown_field');
        $resource=$change['resource']??'';
        if (!is_string($resource)) throw new BusinessError('invalid_resource');
        $this->resource($resource); $id=self::identifier($change['id']??null);
        if($resource==='product_version_history')throw new BusinessError('immutable_history',409);
        $action=$change['action']??''; $version=$change['expected_version']??null;
        if (!in_array($action,$this->catalogs?['create','update','archive','restore']:['create','update','archive'],true) || !is_int($version) || $version<0 || !is_array($change['data']??[])) throw new BusinessError('invalid_change');
        $old=$this->query("SELECT * FROM $resource WHERE environment_id=? AND id=? FOR UPDATE",[$environment,$id])->fetch()?:null;
        if($resource==='project_user_stats' && ($old || $action!=='create'))throw new BusinessError('immutable_history',409);
        if ($action==='create' ? ($old!==null || $version!==0) : ($old===null || (int)$old['row_version']!==$version || ($action==='restore'?$old['archived_at']===null:$old['archived_at']!==null))) throw new BusinessError('version_conflict',409);
        if($action==='restore' && ($resource==='plan_prices' || !empty($change['data'])))throw new BusinessError('invalid_restore');
        if ($action==='archive') {
            if($resource==='plan_prices')throw new BusinessError('immutable_price',409);
            if (!empty($change['data'])) throw new BusinessError('archive_has_data');
            // Retain identities and FKs; don't archive an active parent's reference.
            foreach ($this->refs() as $child=>$refs) foreach ($refs as $field=>$parent) if (!in_array($child,['plan_prices','product_version_history'],true) && $parent===$resource && $this->query("SELECT id FROM $child WHERE environment_id=? AND $field=? AND archived_at IS NULL LIMIT 1",[$environment,$id])->fetch()) throw new BusinessError('entity_in_use',409);
            $this->query("UPDATE $resource SET archived_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6),row_version=row_version+1 WHERE environment_id=? AND id=?",[$environment,$id]);
        } else {
            $row=$this->validate($resource,$id,$change['data']??[],$old);
            foreach ($this->refs()[$resource]??[] as $field=>$parent) if ($row[$field]!==null) {
                self::identifier($row[$field]);
                $unchanged=$action!=='restore' && $old && ($old[$field]??null)===$row[$field];
                if (!$this->query("SELECT id FROM $parent WHERE environment_id=? AND id=?".($unchanged?'':' AND archived_at IS NULL'),[$environment,$row[$field]])->fetch()) throw new BusinessError('invalid_reference');
            }
            $fields=array_keys($row);
            if ($action==='create') {
                $columns=implode(',',$fields);$marks=implode(',',array_fill(0,count($row)+2,'?'));
                $this->query("INSERT INTO $resource(environment_id,id,$columns) VALUES ($marks)",[$environment,$id,...array_values($row)]);
            } else {
                $set=implode(',',array_map(static fn($f)=>$f.'=?',$fields));
                $this->query("UPDATE $resource SET $set,".($action==='restore'?'archived_at=NULL,':'')."row_version=row_version+1,updated_at=UTC_TIMESTAMP(6) WHERE environment_id=? AND id=?",[...array_values($row),$environment,$id]);
            }
        }
        $record=$this->query("SELECT * FROM $resource WHERE environment_id=? AND id=?",[$environment,$id])->fetch();
        if($resource==='product_versions')Catalogs::snapshotVersion($this->pdo,$environment,$record);
        return ['resource'=>$resource,'record'=>$record];
    }
}
