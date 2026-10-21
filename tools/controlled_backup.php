<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/ControlledBackup.php';
try{
 $root=realpath(dirname(__DIR__).'/.runtime');$o=getopt('',['source:','output:','entry:','environment:']);
 $path=realpath((string)($o['source']??''));$output=(string)($o['output']??'');$parent=realpath(dirname($output));
 $norm=static fn($v)=>strtolower(str_replace('\\','/',$v));
 if(!$root||!$path||!$parent||!str_starts_with($norm($path),$norm($root).'/')||!str_starts_with($norm($parent),$norm($root).'/')||is_file($output))throw new RuntimeException('private_new_output_required');
 $archive=Centro\ControlledBackup::validate((string)file_get_contents($path));
 $entry=Centro\ControlledBackup::entry($archive,(string)($o['entry']??''),(string)($o['environment']??''));
 $state=Centro\ControlledBackup::decode($entry['payload']);
 $report=['verified'=>true,'archive_sha256'=>hash_file('sha256',$path),'manifest_sha256'=>$archive['manifest']['sha256'],'entry'=>$entry,'inventory'=>Centro\ControlledBackup::inventory($entry['payload']),'redactions'=>$archive['redactions'],'source_copies'=>array_map(static fn($e)=>array_diff_key($e,['payload'=>true]),$archive['snapshots'])];
 $handle=fopen($output,'x');if(!$handle)throw new RuntimeException('new_output_required');fwrite($handle,json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));fclose($handle);
 echo json_encode(['ok'=>true,'private_report'=>$output],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){echo json_encode(['ok'=>false,'code'=>'controlled_backup_rejected'],JSON_THROW_ON_ERROR).PHP_EOL;exit(1);}
