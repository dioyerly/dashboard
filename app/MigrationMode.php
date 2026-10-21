<?php
declare(strict_types=1);
namespace Centro;
require_once __DIR__.'/ControlledBackup.php';
require_once __DIR__.'/BackupImport.php';

/** Explicit synthetic preview authority, never permission to activate real data. */
final class MigrationMode
{
 public static function fingerprint(\PDO $p,string $environment):array {
  $p->exec("SET time_zone='+00:00'");
  $tables=[...BackupImport::TABLES,...array_keys(Configuration::TYPES),...BackupImport::ACTIVITY_TABLES,'business_state','business_operations','business_audit','fin_sales','fin_expenses','fin_movements','fin_operation_receipts','fin_audit'];
  $result=[];$p->beginTransaction();
  try{foreach($tables as $table){$q=$p->prepare("SELECT * FROM $table WHERE environment_id=?");$q->execute([$environment]);$rows=$q->fetchAll();usort($rows,static fn($a,$b)=>strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR)));$result[$table]=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));}$p->commit();return $result;}
  catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
 }
 private static function path(string $path,string $root):string {
  $resolved=realpath($path);$runtime=realpath($root.'/.runtime');$norm=static fn($v)=>strtolower(str_replace('\\','/',$v));
  if(!$resolved||!$runtime||!is_file($resolved)||!str_starts_with($norm($resolved),$norm($runtime).'/'))throw new \RuntimeException('Private migration plan required');return $resolved;
 }
 public static function configure(array $config,string $file,string $mode):array {
  if($config['environment']!=='local'||$config['db']['host']!=='127.0.0.1')throw new \RuntimeException('Synthetic local migration only');
  $plan=ControlledBackup::decode((string)file_get_contents(self::path($file,$config['root'])));
  if(($plan['purpose']??'')!=='synthetic-controlled-migration'||($plan['database']??'')!==$config['db']['name']||!preg_match('/\Acentro_auth_[0-9a-f]{12}\z/D',$config['db']['name']))throw new \RuntimeException('Synthetic target mismatch');
  $archive=self::path($plan['archive_path']??'',$config['root']);
  if(!hash_equals($plan['archive_sha256']??'',hash_file('sha256',$archive)))throw new \RuntimeException('Independent archive changed');
  ControlledBackup::validate((string)file_get_contents($archive));
  if($mode==='activate')throw new \RuntimeException('Complete activation is not authorized in preparation mode');
  if($mode==='rollback')return array_replace($config,['business_api_enabled'=>false,'financial_api_enabled'=>false,'business_storage'=>'local','migration_read_only'=>false]);
  if($mode!=='preview'||($plan['verified']??false)!==true)throw new \RuntimeException('Verified preview required');
  $p=Database::connection($config['db']);
  foreach(glob($config['root'].'/database/migrations/*.sql') as $path){$q=$p->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([basename($path,'.sql')]);$checksum=$q->fetchColumn();if(!is_string($checksum)||!hash_equals(hash_file('sha256',$path),$checksum))throw new \RuntimeException('Migration checksum mismatch');}
  $q=$p->prepare('SELECT result_json FROM business_operations WHERE environment_id=? AND operation_id=?');$q->execute([$plan['environment'],$plan['operation']]);$receipt=json_decode((string)$q->fetchColumn(),true,128,JSON_THROW_ON_ERROR);
  if(($receipt['source_sha256']??'')!==($plan['source_sha256']??null))throw new \RuntimeException('Source receipt mismatch');
  if(self::fingerprint($p,$plan['environment'])!==($plan['sql_fingerprint']??null))throw new \RuntimeException('Verified SQL target changed');
  return array_replace($config,['business_api_enabled'=>true,'financial_api_enabled'=>false,'business_storage'=>'sql','migration_read_only'=>true]);
 }
}
