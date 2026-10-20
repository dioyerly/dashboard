<?php
declare(strict_types=1);
// Run only against the disposable local schema supplied by the test harness.
require dirname(__DIR__) . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli' || $config['db']['host'] !== '127.0.0.1' || !str_starts_with($config['db']['name'], 'centro_auth_')) exit(1);
$pdo = Centro\Database::connection($config['db']);
$checks = [];
function check(string $name, bool $passed): void {
    global $checks;
    $checks[] = ['check'=>$name, 'passed'=>$passed];
    if (!$passed) throw new RuntimeException('PDO test failed: ' . $name);
}
function rejected(callable $operation): bool {
    try { $operation(); return false; } catch (PDOException $error) { return true; }
}
$password = rtrim((string)fgets(STDIN), "\r\n");
check('Real MariaDB connection', str_contains((string)$pdo->query('SELECT VERSION()')->fetchColumn(), 'MariaDB'));
check('Native prepared statements', !$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($tables);
check('Exact authentication tables', $tables === ['auth_login_limits','auth_users','schema_migrations']);
$migration = $pdo->query("SELECT checksum FROM schema_migrations WHERE version='001_auth'")->fetchColumn();
check('Migration checksum recorded', hash_equals(hash_file('sha256',dirname(__DIR__).'/database/migrations/001_auth.sql'), $migration));
$engines = $pdo->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
check('All tables InnoDB', count($engines)===3 && count(array_filter($engines,static fn($v)=>$v==='InnoDB'))===3);
check('Application cannot create tables', rejected(static fn()=>$pdo->exec('CREATE TABLE forbidden_test(id INT)')));
check('Application cannot alter migration ledger', rejected(static fn()=>$pdo->exec('UPDATE schema_migrations SET checksum=checksum')));
$indexes = $pdo->query('SHOW INDEX FROM auth_users')->fetchAll();
check('Email unique index', count(array_filter($indexes,static fn($v)=>$v['Key_name']==='auth_users_email' && (int)$v['Non_unique']===0))===1);
$indexes = $pdo->query('SHOW INDEX FROM auth_login_limits')->fetchAll();
check('Rate expiry index', count(array_filter($indexes,static fn($v)=>$v['Key_name']==='auth_limits_expiry'))===1);
check('Single admin CHECK enforced', rejected(static fn()=>$pdo->exec("INSERT INTO auth_users(id,email,password_hash) VALUES(2,'other@example.test','not-a-real-hash')")));
check('Active boolean CHECK enforced', rejected(static fn()=>$pdo->exec('UPDATE auth_users SET active=2 WHERE id=1')));
$admin = $store->admin();
check('CLI administrator persisted and password verifies', $admin !== null && password_verify($password,$admin['password_hash']) && $password!==$admin['password_hash']);
check('Second administrator rejected', rejected(static fn()=>$store->create('second@example.test',password_hash('Temporary-test-only-12',PASSWORD_DEFAULT))));
$query = $pdo->prepare('SELECT id FROM auth_users WHERE email=?');
$query->execute(["' OR 1=1 --"]);
check('Prepared input cannot alter SQL', $query->fetch()===false);
$prefix = bin2hex(random_bytes(8));
$a = hash('sha256',$prefix.'a'); $b = hash('sha256',$prefix.'b');
check('Two buckets committed atomically', $store->attempt([$a=>1,$b=>2],100,10)===0);
check('Reached limit blocks', $store->attempt([$a=>1,$b=>2],101,10)===9);
$q=$pdo->prepare('SELECT attempts FROM auth_login_limits WHERE bucket_key=?'); $q->execute([$b]);
check('Blocked attempt does not partially increment other bucket', (int)$q->fetchColumn()===1);
check('Expired rate window recovers', $store->attempt([$a=>1,$b=>2],110,10)===0);
$store->clear([$a,$b]);
$q->execute([$a]); check('Clear removes bucket', $q->fetchColumn()===false);
$good = 'a'.str_repeat('0',63); $bad = str_repeat('z',65);
check('Statement failure propagates', rejected(static fn()=>$store->attempt([$good=>1,$bad=>1],200,10)));
check('Failed transaction rolled back', !$pdo->inTransaction());
$q->execute([$good]); check('No partial row after rollback', $q->fetchColumn()===false);
$pdo->beginTransaction(); $pdo->exec('UPDATE auth_users SET active=0 WHERE id=1'); $pdo->rollBack();
check('Explicit rollback restores administrator', (int)$store->admin()['active']===1);
$password='';
echo json_encode(['checks'=>$checks,'tables'=>$tables],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
