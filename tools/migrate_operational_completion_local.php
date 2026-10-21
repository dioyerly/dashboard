<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/app/Database.php';
$root = dirname(__DIR__);

// This local CLI intentionally requires a fresh synthetic target registration.
// Application/review configurations alone never authorize a migration.
function migration017PrivateFile(string $path, string $runtime): string
{
    $file = realpath($path);
    $base = realpath($runtime);
    $normalize = static fn(string $v): string => strtolower(str_replace('\\', '/', $v));
    if (!$file || !$base || !is_file($file) || !str_starts_with($normalize($file), $normalize($base).'/')) {
        throw new RuntimeException('Private synthetic target and backup required');
    }
    return $file;
}

try {
    $c = require $root.'/config/settings.php';
    if ($c['environment'] !== 'local' || $c['db']['host'] !== '127.0.0.1'
        || !preg_match('/\Acentro_auth_[a-z0-9_]+\z/D', $c['db']['name'])) {
        throw new RuntimeException('Local isolated database required');
    }
    $options = getopt('', ['synthetic-target:', 'backup:']);
    $target = migration017PrivateFile((string)($options['synthetic-target'] ?? ''), $root.'/.runtime');
    $marker = json_decode((string)file_get_contents($target), true, 16, JSON_THROW_ON_ERROR);
    if (($marker['purpose'] ?? '') !== 'synthetic-operational-migration'
        || ($marker['host'] ?? '') !== $c['db']['host']
        || ($marker['port'] ?? null) !== $c['db']['port']
        || ($marker['database'] ?? '') !== $c['db']['name']) {
        throw new RuntimeException('Registered synthetic target required');
    }
    $backup = migration017PrivateFile((string)($options['backup'] ?? ''), $root.'/.runtime');
    $manifestFile = migration017PrivateFile($backup.'.json', $root.'/.runtime');
    $manifest = json_decode((string)file_get_contents($manifestFile), true, 16, JSON_THROW_ON_ERROR);
    if (filesize($backup) < 100 || ($manifest['database'] ?? '') !== $c['db']['name']
        || !is_string($manifest['sha256'] ?? null)
        || !hash_equals($manifest['sha256'], hash_file('sha256', $backup))) {
        throw new RuntimeException('Destination backup manifest mismatch');
    }
    $p = Centro\Database::connection($c['db']);
    $q = $p->prepare('SELECT checksum FROM schema_migrations WHERE version=?');
    foreach (glob($root.'/database/migrations/*.sql') as $file) {
        if ((int)basename($file) > 16) continue;
        $q->execute([basename($file, '.sql')]);
        $checksum = $q->fetchColumn();
        if (!is_string($checksum) || !hash_equals(hash_file('sha256', $file), $checksum)) {
            throw new RuntimeException('Migrations 001-016 must be complete with matching checksums');
        }
    }
    $version = '017_operational_completion';
    $file = $root.'/database/migrations/'.$version.'.sql';
    $hash = hash_file('sha256', $file);
    $q->execute([$version]);
    $old = $q->fetchColumn();
    $tables = $p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $expected = ['fin_entity_metadata', 'fin_subscription_evidence'];
    $present = array_intersect($expected, $tables);
    if ($old !== false) {
        if (!hash_equals($hash, (string)$old) || count($present) !== count($expected)) {
            throw new RuntimeException('017 checksum or table mismatch; manual inspection required');
        }
        echo "017 already applied; checksum verified\n";
        exit;
    }
    // MariaDB DDL commits implicitly. Refuse partial schemas without changing them.
    if ($present) throw new RuntimeException('Partial or unregistered 017 schema; manual inspection required');
    $sql = preg_replace('/--[^\r\n]*/', '', (string)file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) $p->exec($statement);
    $q = $p->prepare('INSERT INTO schema_migrations(version,checksum) VALUES(?,?)');
    $q->execute([$version, $hash]);
    echo "017_operational_completion applied\n";
} catch (Throwable $error) {
    // Never include SQL, database credentials or raw PDO details in console output.
    $safe = $error instanceof PDOException ? 'DDL failed; inspect the private target and backup' : $error->getMessage();
    fwrite(STDERR, "017 migration refused: ".$safe."\n");
    exit(1);
}
