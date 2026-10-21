<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '1');

const APP_ROOT = __DIR__ . '/..';

function usage(): never
{
    fwrite(STDOUT, "Centro de Operaciones schema installer (CLI only)\n" .
        "Plan:   php tools/migrate.php --database=EXACT_DATABASE [--plan]\n" .
        "Install: php tools/migrate.php --database=EXACT_DATABASE --execute-install --confirm-install=EXACT_DATABASE\n" .
        "Update:  php tools/migrate.php --database=EXACT_DATABASE --execute-update --backup-manifest=/private/backup.json --backup-sha256=HEX --confirm-update=EXACT_DATABASE:HEX\n" .
        "Backup manifest fields: database, backup_file, sha256, restore_tested=true, restore_target (different database), restore_tested_at.\n" .
        "The backup file checksum is verified; restore_tested must attest that the backup was restored successfully to the named isolated database.\n" .
        "DDL is not automatically reversible. After an interrupted or failed migration, restore/inspect manually; the installer will refuse to continue.\n");
    exit(0);
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "FAIL " . $message . "\n");
    exit($code);
}

function arguments(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') return ['help' => true];
        if (!str_starts_with($arg, '--')) fail('unknown argument');
        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0];
        if ($key === '' || isset($options[$key])) fail('duplicate or empty argument');
        $options[$key] = count($parts) === 2 ? $parts[1] : true;
    }
    $allowed = ['database', 'plan', 'execute-install', 'confirm-install', 'execute-update', 'backup-manifest', 'backup-sha256', 'confirm-update'];
    foreach (array_keys($options) as $key) if (!in_array($key, $allowed, true)) fail('unknown argument');
    return $options;
}

function pathInside(string $path, string $base): bool
{
    $normalize = static fn(string $v): string => strtolower(rtrim(str_replace('\\', '/', $v), '/') . '/');
    return str_starts_with($normalize($path), $normalize($base));
}

function loadConfig(string $root): array
{
    if (!is_file($root . '/config/settings.php')) fail('distribution config/settings.php is missing');
    $publicDir = is_dir($root . '/public_html') ? $root . '/public_html' : $root . '/public';
    if (!is_dir($publicDir)) fail('distribution public document root is missing');
    if (!is_dir($root . '/database/migrations')) fail('distribution database/migrations directory is missing');
    try { $config = require $root . '/config/settings.php'; }
    catch (Throwable) { fail('private configuration could not be loaded'); }
    if (!is_array($config) || ($config['auth_driver'] ?? '') !== 'mysql'
        || empty($config['db']['enabled']) || empty($config['db']['host']) || empty($config['db']['name']) || empty($config['db']['user']) || empty($config['db']['password'])
        || empty($config['migration_db']['enabled']) || empty($config['migration_db']['host']) || empty($config['migration_db']['name']) || empty($config['migration_db']['user']) || empty($config['migration_db']['password'])
        || !hash_equals((string)$config['db']['name'], (string)$config['migration_db']['name'])) {
        fail('explicit private MySQL configuration is required');
    }
    if (($config['sql_api_enabled'] ?? false) === true || ($config['business_api_enabled'] ?? false) === true
        || ($config['financial_api_enabled'] ?? false) === true || ($config['business_storage'] ?? 'local') === 'sql') {
        fail('disable application SQL endpoints before schema installation');
    }
    $public = realpath($publicDir);
    $tool = realpath(__FILE__);
    $private = realpath((string)($config['private_dir'] ?? ''));
    $appDir = realpath($root . '/app');
    $configDir = realpath($root . '/config');
    $settingsFile = realpath($root . '/config/settings.php');
    $privateConfigFile = is_file($root . '/config/private.php') ? realpath($root . '/config/private.php') : false;
    $migrationDir = realpath($root . '/database/migrations');
    if (!$public || !$tool || !$private || !$appDir || !$configDir || !$settingsFile || !$migrationDir || !is_dir($private) || !is_writable($private)
        || ($privateConfigFile && pathInside($privateConfigFile, $public)) || pathInside($tool, $public) || pathInside($settingsFile, $public) || pathInside($private, $public) || pathInside($appDir, $public)
        || pathInside($configDir, $public) || pathInside($migrationDir, $public)) {
        fail('installer and writable private directory must be outside the public document root');
    }
    return [$config, $public, $private];
}

function migrations(string $root): array
{
    $files = glob($root . '/database/migrations/[0-9][0-9][0-9]_*.sql') ?: [];
    sort($files, SORT_STRING);
    if (count($files) !== 17) fail('expected exactly migrations 001-017');
    $out = [];
    foreach ($files as $i => $file) {
        $expected = sprintf('%03d_', $i + 1);
        if (is_link($file) || !str_starts_with(basename($file), $expected) || !preg_match('/\A[0-9]{3}_[a-z0-9_]+\.sql\z/D', basename($file))) {
            fail('migration sequence has a gap, duplicate, or invalid filename');
        }
        $hash = hash_file('sha256', $file);
        if (!is_string($hash)) fail('migration file could not be checksummed');
        $out[] = ['version' => basename($file, '.sql'), 'path' => $file, 'checksum' => $hash];
    }
    return $out;
}

function connect(array $config, string $requestedDatabase): PDO
{
    $db = $config;
    if (!is_string($requestedDatabase) || !preg_match('/\A[A-Za-z0-9_$-]{1,64}\z/D', $requestedDatabase)
        || !hash_equals((string)$db['name'], $requestedDatabase)) {
        fail('database argument must exactly match the private configured database');
    }
    $dsn = 'mysql:host=' . $db['host'] . ';port=' . (int)($db['port'] ?? 3306) . ';dbname=' . $requestedDatabase . ';charset=utf8mb4';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10];
    if (!empty($db['tls_ca']) && defined('PDO::MYSQL_ATTR_SSL_CA')) $options[PDO::MYSQL_ATTR_SSL_CA] = $db['tls_ca'];
    try { $pdo = new PDO($dsn, (string)$db['user'], (string)$db['password'], $options); }
    catch (Throwable) { fail('database connection failed; verify private settings and privileges'); }
    try {
        $actual = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!hash_equals($requestedDatabase, $actual)) fail('connected database does not match explicit target');
        $server = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $maria = stripos($server, 'mariadb') !== false;
        preg_match('/\A([0-9]+\.[0-9]+\.[0-9]+)/', $server, $match);
        $version = $match[1] ?? '';
        if ($version === '' || ($maria ? version_compare($version, '10.4.0', '<') : version_compare($version, '8.0.16', '<'))) {
            fail('database server version is below the supported CHECK-constraint baseline');
        }
    } catch (PDOException) { fail('database target could not be verified'); }
    return $pdo;
}

function objectCount(PDO $pdo): int
{
    $schema = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q = $pdo->prepare("SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=?) + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=?) + (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=?) + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=?)");
    $q->execute([$schema, $schema, $schema, $schema]);
    return (int)$q->fetchColumn();
}

function hasTable(PDO $pdo, string $name): bool
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\'');
    $q->execute([$name]);
    return (int)$q->fetchColumn() === 1;
}

function verifyLedgerShape(PDO $pdo): void
{
    $q = $pdo->query("SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'");
    $columns = [];
    foreach ($q->fetchAll() as $row) $columns[$row['COLUMN_NAME']] = $row;
    foreach (['version' => ['varchar', 64], 'checksum' => ['char', 64], 'applied_at' => ['timestamp', null]] as $name => [$type, $length]) {
        if (!isset($columns[$name]) || strtolower((string)$columns[$name]['DATA_TYPE']) !== $type
            || ($length !== null && (int)$columns[$name]['CHARACTER_MAXIMUM_LENGTH'] !== $length)) {
            fail('existing schema_migrations table is incompatible');
        }
    }
    $q = $pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND CONSTRAINT_NAME='PRIMARY' AND COLUMN_NAME='version' AND ORDINAL_POSITION=1");
    if ((int)$q->fetchColumn() !== 1) fail('existing migration ledger primary key is incompatible');
}

function ledger(PDO $pdo, array $migrations): array
{
    if (!hasTable($pdo, 'schema_migrations')) return [];
    verifyLedgerShape($pdo);
    $known = [];
    foreach ($migrations as $migration) $known[$migration['version']] = $migration['checksum'];
    $rows = $pdo->query('SELECT version, checksum FROM schema_migrations ORDER BY version')->fetchAll();
    $applied = [];
    foreach ($rows as $row) {
        $version = (string)$row['version'];
        if (!isset($known[$version])) fail('migration ledger contains an unknown version');
        if (!hash_equals($known[$version], (string)$row['checksum'])) fail('registered migration checksum differs from source');
        $applied[] = $version;
    }
    foreach ($applied as $i => $version) if ($version !== $migrations[$i]['version']) fail('migration ledger has a gap or out-of-order version');
    return $applied;
}

function splitSql(string $sql): array
{
    if (preg_match('/^\s*DELIMITER\s/im', $sql)) fail('unsupported SQL delimiter directive');
    $statements = []; $buffer = ''; $quote = ''; $line = false; $block = false; $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i]; $next = $i + 1 < $n ? $sql[$i + 1] : '';
        if ($line) { if ($c === "\n") { $line = false; $buffer .= "\n"; } continue; }
        if ($block) { if ($c === '*' && $next === '/') { $block = false; $i++; } continue; }
        if ($quote !== '') {
            $buffer .= $c;
            if ($c === '\\' && $quote !== '`' && $i + 1 < $n) { $buffer .= $sql[++$i]; continue; }
            if ($c === $quote) {
                if ($next === $quote) { $buffer .= $sql[++$i]; continue; }
                $quote = '';
            }
            continue;
        }
        if ($c === '#' || ($c === '-' && $next === '-' && ($i + 2 >= $n || ord($sql[$i + 2]) <= 32))) { $line = true; if ($c === '-') $i++; continue; }
        if ($c === '/' && $next === '*') { $block = true; $i++; continue; }
        if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $buffer .= $c; continue; }
        if ($c === ';') { if (trim($buffer) !== '') $statements[] = trim($buffer); $buffer = ''; continue; }
        $buffer .= $c;
    }
    if ($quote !== '' || $block) fail('migration SQL contains an unterminated quote or comment');
    if (trim($buffer) !== '') $statements[] = trim($buffer);
    if (!$statements) fail('migration contains no SQL statements');
    return $statements;
}

function validateBackup(array $options, string $database, string $public, string $private): string
{
    $manifestPath = realpath((string)($options['backup-manifest'] ?? ''));
    $expected = strtolower((string)($options['backup-sha256'] ?? ''));
    $confirm = (string)($options['confirm-update'] ?? '');
    if (!$manifestPath || !is_file($manifestPath) || !pathInside($manifestPath, $private) || !preg_match('/\A[a-f0-9]{64}\z/D', $expected)) {
        fail('a private backup manifest and SHA-256 are required for schema updates');
    }
    try { $manifest = json_decode((string)file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR); }
    catch (Throwable) { fail('backup manifest is invalid'); }
    $backupPath = realpath((string)($manifest['backup_file'] ?? ''));
    if (!$backupPath || !is_file($backupPath) || !pathInside($backupPath, $private)
        || ($manifest['database'] ?? null) !== $database || ($manifest['restore_tested'] ?? null) !== true
        || !is_string($manifest['restore_target'] ?? null) || $manifest['restore_target'] === $database
        || !is_string($manifest['restore_tested_at'] ?? null) || !is_string($manifest['sha256'] ?? null)
        || !hash_equals($expected, strtolower($manifest['sha256']))
        || !hash_equals($expected, hash_file('sha256', $backupPath))
        || !hash_equals($database . ':' . $expected, $confirm)) {
        fail('backup integrity, restore-test evidence, or update confirmation failed');
    }
    try { new DateTimeImmutable($manifest['restore_tested_at']); }
    catch (Throwable) { fail('backup restore-test timestamp is invalid'); }
    return $expected;
}

function writeMarker(string $path, string $database, array $migration): void
{
    $handle = @fopen($path, 'x');
    if (!$handle) fail('private interrupted-migration marker exists or cannot be created; inspect the database before continuing');
    @chmod($path, 0600);
    $json = json_encode(['database' => $database, 'migration' => $migration['version'], 'checksum' => $migration['checksum'], 'started_at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR);
    try {
        if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) fail('could not persist private migration safety marker');
        if (function_exists('fsync')) @fsync($handle);
    } finally { fclose($handle); }
}

function printPlan(string $database, string $mode, array $applied, array $pending): void
{
    fwrite(STDOUT, "Target database: {$database}\nMode: {$mode}\nAlready applied: " . (count($applied) ? implode(', ', $applied) : 'none') . "\nPending: " . (count($pending) ? count($pending) : 'none') . "\n");
    foreach ($pending as $migration) fwrite(STDOUT, '  ' . $migration['version'] . ' sha256=' . $migration['checksum'] . "\n");
    if ($mode === 'install') fwrite(STDOUT, "Plan is read-only. To execute, pass --execute-install --confirm-install={$database}\n");
    elseif ($pending) fwrite(STDOUT, "Plan is read-only. For execution, provide a verified restore-tested private backup and --execute-update.\n");
    else fwrite(STDOUT, "No schema changes required.\n");
}

$options = arguments($argv);
if (isset($options['help'])) usage();
$database = $options['database'] ?? '';
$install = isset($options['execute-install']); $update = isset($options['execute-update']);
if ($install && $update) fail('choose exactly one execution mode');
if (isset($options['execute-install']) && $options['execute-install'] !== true) fail('invalid install option');
if (isset($options['execute-update']) && $options['execute-update'] !== true) fail('invalid update option');
if (isset($options['plan']) && $options['plan'] !== true) fail('invalid plan option');
if (!$database) fail('--database is required even for a read-only plan');
$root = realpath(APP_ROOT);
if (!$root) fail('distribution root unavailable');
[$config, $public, $private] = loadConfig($root);
$migrations = migrations($root);
$pdo = connect($config["migration_db"], (string)$database);
$markerPath = $private . DIRECTORY_SEPARATOR . 'migration-in-progress.json';
$lockPath = $private . DIRECTORY_SEPARATOR . 'migration-executor.lock';
if (is_link($markerPath) || file_exists($markerPath)) fail('an interrupted migration marker exists; restore or inspect the database before continuing');
$ledgerExists = hasTable($pdo, 'schema_migrations');
if (!$ledgerExists) {
    if (objectCount($pdo) !== 0) fail('database is not empty and has no compatible migration ledger; no changes made');
    $mode = 'install'; $applied = [];
} else {
    $applied = ledger($pdo, $migrations);
    if (!$applied) fail('migration ledger is empty; database state is ambiguous and requires manual inspection');
    $mode = 'update';
}
$pending = array_slice($migrations, count($applied));
if (!$install && !$update) {
    printPlan((string)$database, $mode, $applied, $pending);
    exit(0);
}
if (!$pending) {
    if ($install) fail('clean installation refused: schema objects already exist; use read-only plan');
    fwrite(STDOUT, "No pending migrations; ledger and checksums verified.\n");
    exit(0);
}
if ($install) {
    if ($mode !== 'install' || ($options['confirm-install'] ?? null) !== $database) fail('clean install requires an empty schema and exact --confirm-install database name');
    if (isset($options['confirm-update']) || isset($options['backup-manifest']) || isset($options['backup-sha256'])) fail('install and update confirmation options cannot be mixed');
} else {
    if ($mode !== 'update' || ($options['confirm-install'] ?? null) !== null) fail('schema update requires an existing compatible migration ledger');
    validateBackup($options, (string)$database, $public, $private);
}

if (is_link($lockPath)) fail('private migration lock is a symbolic link');
$lock = @fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) fail('another local migration executor is running');
@chmod($lockPath, 0600);
$lockName = 'centro_migrate_' . substr(hash('sha256', (string)$database), 0, 32);
$gotSqlLock = false;
$appliedNow = [];
try {
    $lockQuery = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lockQuery->execute([$lockName]);
    $gotSqlLock = (int)$lockQuery->fetchColumn() === 1;
    if (!$gotSqlLock) fail('database migration lock is busy; no migrations executed');
    // Re-read all state after both locks. A plan is advisory; execution always revalidates.
    if (is_link($markerPath) || file_exists($markerPath)) fail('interrupted migration marker exists; no changes made');
    $ledgerExists = hasTable($pdo, 'schema_migrations');
    if ($mode === 'install') {
        if ($ledgerExists || objectCount($pdo) !== 0) fail('target changed after plan; clean installation refused');
        $applied = [];
    } else {
        if (!$ledgerExists) fail('target changed after plan; migration ledger missing');
        $applied = ledger($pdo, $migrations);
    }
    $pending = array_slice($migrations, count($applied));
    if (!$pending) { fwrite(STDOUT, "No pending migrations; nothing executed.\n"); exit(0); }
    if ($install && ($options['confirm-install'] ?? null) !== $database) fail('installation confirmation no longer matches target');
    if ($update) validateBackup($options, (string)$database, $public, $private);
    printPlan((string)$database, $mode, $applied, $pending);
    foreach ($pending as $migration) {
        $sql = @file_get_contents($migration['path']);
        if (!is_string($sql) || !hash_equals($migration['checksum'], hash('sha256', $sql))) fail('migration source changed after planning; no DDL executed');
        writeMarker($markerPath, (string)$database, $migration);
        try {
            foreach (splitSql($sql) as $statement) $pdo->exec($statement);
            if (!hasTable($pdo, 'schema_migrations')) throw new RuntimeException('migration ledger missing after DDL');
            verifyLedgerShape($pdo);
            $insert = $pdo->prepare('INSERT INTO schema_migrations(version,checksum) VALUES(?,?)');
            $insert->execute([$migration['version'], $migration['checksum']]);
        } catch (Throwable) {
            fwrite(STDERR, 'FAIL ' . $migration['version'] . " failed; execution stopped. DDL may be partial; no automatic rollback or retry was attempted. Restore the verified backup or inspect this database manually.\n");
            exit(2);
        }
        $appliedNow[] = $migration['version'];
        if (!@unlink($markerPath)) {
            fwrite(STDERR, 'FAIL ' . $migration['version'] . " is recorded, but the private safety marker could not be removed. Inspect before any further execution.\n");
            exit(2);
        }
        fwrite(STDOUT, 'APPLIED ' . $migration['version'] . "\n");
    }
    fwrite(STDOUT, 'COMPLETE applied=' . implode(',', $appliedNow) . "\n");
} finally {
    if ($gotSqlLock) {
        try { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lockName]); } catch (Throwable) {}
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}
