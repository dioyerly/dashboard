<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$c=require $root.'/config/settings.php';
if($c['environment']!=='local'||$c['db']['host']!=='127.0.0.1')throw new RuntimeException('Local database required');
require $root.'/app/Database.php';$p=Centro\Database::connection($c['db']);$v='016_business_state';$f=$root.'/database/migrations/'.$v.'.sql';$h=hash_file('sha256',$f);
$q=$p->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$v]);$old=$q->fetchColumn();
if($old!==false){if(!hash_equals($h,(string)$old))throw new RuntimeException('Checksum mismatch');echo "already applied\n";exit;}
foreach(array_filter(array_map('trim',explode(';',(string)file_get_contents($f)))) as $s)$p->exec($s);
$q=$p->prepare('INSERT INTO schema_migrations(version,checksum) VALUES(?,?)');$q->execute([$v,$h]);echo "016_business_state applied\n";
