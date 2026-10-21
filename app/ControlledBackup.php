<?php
declare(strict_types=1);
namespace Centro;

/** Integrity validation only. It never opens a database or browser storage. */
final class ControlledBackup
{
 public const FORMAT='centro-operaciones-controlled-backup';
 public const COLLECTIONS=['rates','projects','plans','products','customers','sales','subs','expenses','resources','suppliers','events','treasury','integrations','review','timeLogs','log'];
 private const PRIVATE_KEY='/password|passwd|secret|token|api[_-]?key|credential|authorization|session|cookie|^_?csrf$|^pin$/i';
 public static function decode(string $raw):mixed {
  $value=json_decode($raw,true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
  $tokenized=preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[{}\[\]:,]|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null/su',$raw,$matches);
  if($tokenized===false)throw new \RuntimeException('json_tokenization_limit');
  $tokens=$matches[0];$i=0;
  $consume=function()use(&$consume,&$i,$tokens):void{
   $token=$tokens[$i++]??null;
   if($token==='{'){$seen=[];if(($tokens[$i]??null)!=='}'){do{$key=json_decode($tokens[$i++],true,4,JSON_THROW_ON_ERROR);if(isset($seen[$key]))throw new \RuntimeException('duplicate_json_key');$seen[$key]=true;$i++;$consume();$comma=($tokens[$i]??null)===',';if($comma)$i++;}while($comma);}$i++;}
   elseif($token==='['){if(($tokens[$i]??null)!==']'){do{$consume();$comma=($tokens[$i]??null)===',';if($comma)$i++;}while($comma);}$i++;}
  };
  $consume();return $value;
 }
 private static function secrets(mixed $value):void {
  if(is_array($value))foreach($value as $key=>$item){if(is_string($key)&&preg_match(self::PRIVATE_KEY,$key)&&$item!=='')throw new \RuntimeException('private_value_in_backup');self::secrets($item);}
  elseif(is_string($value)&&preg_match('~https?://[^/\s]+:[^/\s]+@|[?&](?:token|access_token|api_key|password|secret)=|\b(?:Bearer\s+[A-Za-z0-9._-]+|ghp_[A-Za-z0-9]+|sk-[A-Za-z0-9]{16,})~i',$value))throw new \RuntimeException('private_value_in_backup');
 }
 public static function validate(string $raw):array {
  if(strlen($raw)>64*1024*1024)throw new \RuntimeException('archive_too_large');
  $archive=self::decode($raw);
  if(!is_array($archive)||($archive['format']??null)!==self::FORMAT||($archive['version']??null)!==1||($archive['manifest']['algorithm']??null)!=='SHA-256'||!is_array($archive['snapshots']??null)||count($archive['snapshots'])<1||count($archive['snapshots'])>5)throw new \RuntimeException('invalid_backup_format');
  $payload=$archive['manifest']['payload']??null;$hash=$archive['manifest']['sha256']??null;
  if(!is_string($payload)||!is_string($hash)||!hash_equals(hash('sha256',$payload),$hash))throw new \RuntimeException('manifest_hash_mismatch');
  $bound=self::decode($payload);$entries=[];$ids=[];
  foreach($archive['snapshots'] as $entry){
   $env=$entry['environment']??null;$source=$entry['source']??null;
   if(!in_array($env,['pruebas','produccion'],true)||!in_array($source,['indexedDB','localStorage','visible'],true)||!is_string($entry['payload']??null))throw new \RuntimeException('invalid_snapshot');
   $key=$source==='visible'?'state':($source==='indexedDB'?'estado-':'co-estado-').$env;
   $id=$source==='visible'?'visible:'.$env:$source.':'.$key;
   if(($entry['key']??null)!==$key||($entry['id']??null)!==$id||isset($ids[$id]))throw new \RuntimeException('ambiguous_snapshot_origin');$ids[$id]=true;
   if(strlen($entry['payload'])!==($entry['bytes']??null)||!is_string($entry['sha256']??null)||!hash_equals(hash('sha256',$entry['payload']),$entry['sha256']))throw new \RuntimeException('snapshot_hash_mismatch');
   $state=self::decode($entry['payload']);self::secrets($state);
   if(!is_array($state)||($state['version']??null)!==1||!is_array($state['settings']??null))throw new \RuntimeException('invalid_business_state');
   foreach(self::COLLECTIONS as $collection)if(!array_key_exists($collection,$state)||!is_array($state[$collection])||!array_is_list($state[$collection]))throw new \RuntimeException('missing_or_invalid_collection');
   unset($entry['payload']);$entries[]=$entry;
  }
  $actual=['format'=>$archive['format'],'version'=>$archive['version'],'exportedAt'=>$archive['exportedAt']??null,'entries'=>$entries,'preferences'=>$archive['preferences']??null,'redactions'=>$archive['redactions']??null];
  if($bound!==$actual)throw new \RuntimeException('manifest_identity_mismatch');
  if(!is_array($archive['preferences'])||!is_array($archive['redactions']))throw new \RuntimeException('invalid_manifest_inventory');
  foreach($archive['preferences'] as $key=>$value){if(!in_array($key,['eco-env','eco-ui'],true)||!is_string($value))throw new \RuntimeException('private_preference_not_allowed');if($key==='eco-ui')self::secrets(self::decode($value));elseif(!in_array($value,['pruebas','produccion'],true))throw new \RuntimeException('invalid_environment_preference');}
  return $archive;
 }
 public static function entry(array $archive,string $id,string $environment):array {
  foreach($archive['snapshots'] as $entry)if($entry['id']===$id&&$entry['environment']===$environment)return $entry;
  throw new \RuntimeException('explicit_source_snapshot_required');
 }
 public static function inventory(string $raw):array {
  $state=json_decode($raw,false,128,JSON_THROW_ON_ERROR);
  $inventory=[];
  $walk=function(mixed $value,array $path)use(&$walk,&$inventory):void{
   $container=is_array($value)||is_object($value);$type=is_object($value)?'object':(is_array($value)?'array':((is_int($value)||is_float($value))?'number':get_debug_type($value)));
   $inventory[]=['path'=>$path,'type'=>$type,...($container?['count'=>count((array)$value)]:[])];
   if($container)foreach($value as $key=>$item)$walk($item,[...$path,$key]);
  };
  $walk($state,[]);return $inventory;
 }
}
