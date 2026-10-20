<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1); require dirname(__DIR__).'/app/Database.php';
$root=dirname(__DIR__);$c=require $root.'/config/settings.php';
if($c['environment']!=='local'||$c['db']['host']!=='127.0.0.1'||!preg_match('/\Acentro_auth_[a-z0-9_]+\z/D',$c['db']['name']))throw new RuntimeException('Local isolated database required');
$p=Centro\Database::connection($c['db']);$v='011_financial_sale_line_revisions';$f=$root.'/database/migrations/'.$v.'.sql';$h=hash_file('sha256',$f);
$q=$p->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$v]);$old=$q->fetchColumn();
if($old!==false){if(!hash_equals($h,(string)$old))throw new RuntimeException('Checksum mismatch');echo "already applied\n";exit;}
try{$sql=preg_replace('/--[^\r\n]*/','',(string)file_get_contents($f));foreach(array_filter(array_map('trim',explode(';',$sql))) as $s)$p->exec($s);$q=$p->prepare('INSERT INTO schema_migrations(version,checksum) VALUES(?,?)');$q->execute([$v,$h]);echo "011_financial_sale_line_revisions applied\n";}catch(Throwable $e){throw $e;}
