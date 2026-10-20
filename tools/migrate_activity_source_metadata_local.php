<?php
declare(strict_types=1);
require_once __DIR__.'/../app/Database.php';
$root=dirname(__DIR__);$c=require $root.'/config/settings.php';$p=Centro\Database::connection($c['db']);$v='013_activity_source_metadata';$f=$root.'/database/migrations/'.$v.'.sql';$h=hash_file('sha256',$f);$q=$p->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$v]);$old=$q->fetchColumn();if($old!==false){if(!hash_equals($h,(string)$old))throw new RuntimeException('Checksum mismatch');exit(0);}foreach(array_filter(array_map('trim',explode(';',(string)file_get_contents($f)))) as $s)$p->exec($s);$q=$p->prepare('INSERT INTO schema_migrations(version,checksum) VALUES(?,?)');$q->execute([$v,$h]);
