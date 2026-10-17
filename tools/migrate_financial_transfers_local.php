<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);require dirname(__DIR__).'/app/Database.php';
$c=require dirname(__DIR__).'/config/settings.php';
if($c['environment']!=='local'||$c['db']['host']!=='127.0.0.1'||!preg_match('/\Acentro_auth_[a-z0-9_]+\z/D',$c['db']['name']))throw new RuntimeException('Local isolated database required');
$p=Centro\Database::connection($c['db']);$v='009_financial_transfer_legs';$f=dirname(__DIR__).'/database/migrations/'.$v.'.sql';$h=hash_file('sha256',$f);
$q=$p->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$v]);$old=$q->fetchColumn();
if($old!==false){if(!hash_equals($h,(string)$old))throw new RuntimeException('Checksum mismatch');echo "already applied\n";exit;}
$sql=preg_replace('/--[^\r\n]*/','',(string)file_get_contents($f));
foreach(array_filter(array_map('trim',explode(';',$sql))) as $s)$p->exec($s);
$q=$p->prepare('INSERT INTO schema_migrations(version,checksum) VALUES(?,?)');$q->execute([$v,$h]);
echo "009_financial_transfer_legs applied\n";
