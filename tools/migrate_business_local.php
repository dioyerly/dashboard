<?php
declare(strict_types=1);
// Fixed migration only. MySQL DDL is not transactional: partial schemas are refused.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
try {
    require dirname(__DIR__).'/app/Database.php';
    $config=require dirname(__DIR__).'/config/settings.php';
    if ($config['environment']!=='local' || $config['db']['host']!=='127.0.0.1' || !str_starts_with($config['db']['name'],'centro_auth_')) throw new RuntimeException('Local isolated schema required');
    $pdo=Centro\Database::connection($config['db']);
    $path=dirname(__DIR__).'/database/migrations/002_business_foundation.sql';
    $hash=hash_file('sha256',$path);
    $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute(['002_business_foundation']);$old=$q->fetchColumn();
    if ($old!==false) {
        if (!hash_equals($old,$hash)) throw new RuntimeException('Checksum mismatch');
        echo "Migration already applied; checksum verified.\n";exit;
    }
    $q->execute(['001_auth']);
    if (!hash_equals(hash_file('sha256',dirname(__DIR__).'/database/migrations/001_auth.sql'),(string)$q->fetchColumn())) throw new RuntimeException('Auth baseline mismatch');
    $options=getopt('',['backup:']);$backup=realpath((string)($options['backup']??''));
    $runtime=realpath(dirname(__DIR__).'/.runtime');
    $normalized=static fn($v)=>strtolower(str_replace('\\','/',$v));
    if (!$backup || !$runtime || !str_starts_with($normalized($backup),$normalized($runtime).'/') || filesize($backup)<100) throw new RuntimeException('Private destination backup required');
    $manifest=json_decode((string)file_get_contents($backup.'.json'),true,16,JSON_THROW_ON_ERROR);
    if (($manifest['database']??null)!==$config['db']['name'] || !hash_equals((string)($manifest['sha256']??''),hash_file('sha256',$backup))) throw new RuntimeException('Destination backup manifest mismatch');
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables);
    if ($tables!==['auth_login_limits','auth_users','schema_migrations']) throw new RuntimeException('Unexpected or partial schema; manual recovery required');
    $sql=file_get_contents($path);
    foreach (explode(';',$sql) as $statement) if (trim(preg_replace('/--[^\r\n]*/','',$statement))!=='') $pdo->exec($statement);
    $q=$pdo->prepare('INSERT INTO schema_migrations(version,checksum) VALUES (?,?)');$q->execute(['002_business_foundation',$hash]);
    echo "Local migration 002 applied and checksum registered.\n";
} catch (Throwable $error) {
    fwrite(STDERR,"Local migration failed. Inspect the private destination and backup; no automatic deletion or retry of partial DDL.\n");exit(1);
}
