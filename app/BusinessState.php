<?php
declare(strict_types=1);
namespace Centro;
require_once __DIR__.'/Business.php';
require_once __DIR__.'/CatalogState.php';

final class BusinessState
{
    public function __construct(private readonly \PDO $pdo,private readonly bool $catalogs=true) {}

    private function query(string $sql, array $args=[]): \PDOStatement
    {
        $q=$this->pdo->prepare($sql); $q->execute($args); return $q;
    }

    private static function environment(string $environment): void
    {
        if (!in_array($environment,['produccion','pruebas'],true)) throw new BusinessError('invalid_environment');
    }

    private function ensureEnvironment(string $environment): void
    {
        self::environment($environment);
        $this->query('INSERT INTO environments(id,code,label,catalog_revision) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE id=id',
            [$environment,$environment,$environment==='pruebas'?'Ambiente de pruebas':'Ambiente de producción']);
    }

    public function load(string $environment): array
    {
        $owns=!$this->pdo->inTransaction();if($owns)$this->pdo->beginTransaction();
        try{$result=$this->readState($environment);if($owns)$this->pdo->commit();return $result;}catch(\Throwable $e){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function readState(string $environment): array
    {
        self::environment($environment);
        $row=$this->query('SELECT state_json,row_version,state_hash FROM business_state WHERE environment_id=?',[$environment])->fetch();
        if (!$row) return ['found'=>false,'state'=>null,'version'=>0,'hash'=>null];
        $state=json_decode((string)$row['state_json'],true,64,JSON_THROW_ON_ERROR);
        if($this->catalogs){$state=(new CatalogState($this->pdo))->hydrate($environment,$state);$revision=$this->query('SELECT catalog_revision FROM environments WHERE id=?',[$environment])->fetchColumn();$state['_catalogRevision']=(string)$revision;}
        return ['found'=>true,'state'=>$state,'version'=>(int)$row['row_version'],'hash'=>$row['state_hash']];
    }

    public function save(string $environment,string $operation,int $actor,array $state,int $expectedVersion): array
    {
        self::environment($environment);
        if($actor!==1||!$this->query('SELECT id FROM auth_users WHERE id=? AND active=1',[$actor])->fetch())throw new BusinessError('forbidden',403);
        if (!preg_match('/\Astate_[A-Za-z0-9_-]{16,80}\z/D',$operation) || $expectedVersion<0) throw new BusinessError('invalid_state_operation');
        if (($state['version']??null)!==1) throw new BusinessError('invalid_state');
        $json=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (strlen($json)>16*1024*1024) throw new BusinessError('state_too_large',413);
        $hash=hash('sha256',$json);
        $this->pdo->beginTransaction();
        try {
            $this->ensureEnvironment($environment);
            $existing=$this->query('SELECT payload_hash,result_json FROM business_operations WHERE environment_id=? AND operation_id=? FOR UPDATE',[$environment,$operation])->fetch();
            if ($existing) {
                if (!hash_equals((string)$existing['payload_hash'],hash('sha256',json_encode(['state'=>$state,'expected_version'=>$expectedVersion],JSON_THROW_ON_ERROR)))) throw new BusinessError('idempotency_conflict',409);
                $this->pdo->commit(); return ['replayed'=>true,...json_decode((string)$existing['result_json'],true,32,JSON_THROW_ON_ERROR)];
            }
            $this->query('SELECT id FROM environments WHERE id=? FOR UPDATE',[$environment]);
            $row=$this->query('SELECT state_json,row_version FROM business_state WHERE environment_id=? FOR UPDATE',[$environment])->fetch();
            $current=$row?(int)$row['row_version']:0;
            if ($current!==$expectedVersion) throw new BusinessError('version_conflict',409);
            $catalogChanges=[];if($this->catalogs&&(new CatalogState($this->pdo))->ready()){$revision=$this->query('SELECT catalog_revision FROM environments WHERE id=?',[$environment])->fetchColumn();if(isset($state['settings']['brands'])&&$row&&!isset($state['_catalogRevision']))throw new BusinessError('catalog_revision_required',409);if(isset($state['_catalogRevision'])&&(string)$state['_catalogRevision']!==(string)$revision)throw new BusinessError('version_conflict',409);$catalogChanges=(new CatalogState($this->pdo))->synchronize($environment,$row?json_decode($row['state_json'],true,64,JSON_THROW_ON_ERROR):[],$state);}
            $next=$current+1;
            if ($row) $this->query('UPDATE business_state SET state_json=?,state_hash=?,row_version=?,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE environment_id=?',[$json,$hash,$next,$actor,$environment]);
            else $this->query('INSERT INTO business_state(environment_id,state_json,state_hash,row_version,updated_by) VALUES(?,?,?,?,?)',[$environment,$json,$hash,$next,$actor]);
            $result=['environment'=>$environment,'version'=>$next,'hash'=>$hash,'catalog_revision'=>(string)$this->query('SELECT catalog_revision FROM environments WHERE id=?',[$environment])->fetchColumn()];
            $payloadHash=hash('sha256',json_encode(['state'=>$state,'expected_version'=>$expectedVersion],JSON_THROW_ON_ERROR));
            $this->query('INSERT INTO business_operations(environment_id,operation_id,actor_user_id,payload_hash,result_json) VALUES(?,?,?,?,?)',[$environment,$operation,$actor,$payloadHash,json_encode($result,JSON_THROW_ON_ERROR)]);
            $this->query('INSERT INTO business_audit(environment_id,operation_id,actor_user_id,command_json) VALUES(?,?,?,?)',[$environment,$operation,$actor,json_encode(['kind'=>'state_save','hash'=>$hash,'catalog_changes'=>$catalogChanges],JSON_THROW_ON_ERROR)]);
            $this->pdo->commit(); return ['replayed'=>false,...$result];
        } catch (\Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }
}
