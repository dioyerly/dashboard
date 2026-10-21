<?php
declare(strict_types=1);
// Tests of the isolated synthetic test gate in config/settings.php. Run through tools/run_isolated_tests.ps1, which
// creates the run token and marker. Each case starts a child PHP process with its own environment and checks the outcome.
// Exit code 1 on any failed case. Secrets are compared, never printed.
$root = dirname(__DIR__);
$runtime = $root . '/.runtime';
$instanceDir = $runtime . '/synthetic-test-mariadb';
$marker = (string)getenv('CENTRO_ISOLATED_TEST_MARKER');
$token = (string)getenv('CENTRO_ISOLATED_TEST_TOKEN');
$port = (int)getenv('CENTRO_DB_PORT');
$password = (string)getenv('CENTRO_DB_PASSWORD');
if ($marker === '' || $token === '' || $port === 0 || $password === '') {
    fwrite(STDERR, "run through tools/run_isolated_tests.ps1\n");
    exit(1);
}
$results = [];
$record = function (string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['case' => $name, 'passed' => $ok, 'detail' => $detail];
};
$php = PHP_BINARY;
$ext = (string)ini_get('extension_dir');
$probe = "\$c = require " . var_export($root . '/config/settings.php', true) . ";"
    . "echo json_encode(['port' => \$c['db']['port'], 'host' => \$c['db']['host'], 'name' => \$c['db']['name'], 'auth' => \$c['auth_driver'], 'environment' => \$c['environment'], 'business_storage' => \$c['business_storage'], 'financial_api_enabled' => \$c['financial_api_enabled'], 'sql_api_enabled' => \$c['sql_api_enabled']]);";
$isolationKeys = ['CENTRO_ISOLATED_TEST_CONFIG', 'CENTRO_ISOLATED_TEST_MARKER', 'CENTRO_ISOLATED_TEST_TOKEN'];
// Runs a child with the current environment, optionally overriding or removing variables.
$child = function (array $override = [], array $remove = []) use ($php, $ext, $probe, $isolationKeys): array {
    $env = [];
    foreach (getenv() as $k => $v) { if (!in_array($k, $remove, true)) $env[$k] = $v; }
    foreach ($override as $k => $v) { if ($v === null) unset($env[$k]); else $env[$k] = $v; }
    $pipes = [];
    $proc = proc_open([$php, '-n', '-d', 'extension_dir=' . $ext, '-d', 'extension=pdo_mysql', '-r', $probe], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    return ['code' => $code, 'out' => (string)$out, 'err' => (string)$err];
};
$valid = ['CENTRO_ISOLATED_TEST_CONFIG' => '1', 'CENTRO_ISOLATED_TEST_MARKER' => $marker, 'CENTRO_ISOLATED_TEST_TOKEN' => $token];
$secretFree = function (array $r) use ($token, $password): bool {
    return !str_contains($r['out'] . $r['err'], $token) && ($password === '' || !str_contains($r['out'] . $r['err'], $password));
};

// 1. Without the flag the loader behaves as before: a private file, when present, is still applied.
$r = $child([], $isolationKeys);
$privatePresent = is_file($root . '/config/private.php');
$data = json_decode($r['out'], true);
$record('1 normal mode without flag loads as before', $r['code'] === 0 && is_array($data) && ($privatePresent ? $data['port'] !== $port : $data['port'] === $port), 'private_present=' . ($privatePresent ? 'yes' : 'no'));

// 2. Valid isolated mode: synthetic instance port, private file not applied, business SQL still disabled by default.
$r = $child($valid);
$data = json_decode($r['out'], true);
$record('2 valid isolated mode uses the synthetic instance only', $r['code'] === 0 && is_array($data) && $data['port'] === $port && $data['host'] === '127.0.0.1' && $data['environment'] === 'local', 'port_match=' . (is_array($data) && $data['port'] === $port ? 'yes' : 'no'));
$record('2b business SQL and financial API stay disabled by default', is_array($data) && $data['business_storage'] === 'local' && $data['financial_api_enabled'] === false && $data['sql_api_enabled'] === false);
$record('2c no secret in child output or error', $secretFree($r));

// 3. Wrong token is rejected.
$r = $child(array_merge($valid, ['CENTRO_ISOLATED_TEST_TOKEN' => str_repeat('a', 64)]));
$record('3 wrong token rejected', $r['code'] !== 0 && str_contains($r['out'] . $r['err'], 'Isolated test configuration rejected') && $secretFree($r));

// 4. Flag must be exactly "1".
$r = $child(array_merge($valid, ['CENTRO_ISOLATED_TEST_CONFIG' => '0']));
$record('4 flag value other than 1 rejected', $r['code'] !== 0);
$r = $child(array_merge($valid, ['CENTRO_ISOLATED_TEST_CONFIG' => 'true']));
$record('4b flag "true" rejected', $r['code'] !== 0);

// 5. Production environment is rejected even with a valid token.
$r = $child(array_merge($valid, ['CENTRO_ENV' => 'production']));
$record('5 production environment rejected', $r['code'] !== 0);

// 6. Missing marker is rejected.
$r = $child(array_merge($valid, ['CENTRO_ISOLATED_TEST_MARKER' => $instanceDir . '/does-not-exist.json']));
$record('6 missing marker rejected', $r['code'] !== 0);

// 7. Marker outside .runtime is rejected (copied to the system temp directory with the same valid token).
$tmpMarker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'isolated-outside-' . bin2hex(random_bytes(4)) . '.json';
$content = json_decode((string)file_get_contents($marker), true);
file_put_contents($tmpMarker, json_encode($content));
$r = $child(array_merge($valid, ['CENTRO_ISOLATED_TEST_MARKER' => $tmpMarker]));
@unlink($tmpMarker);
$record('7 marker outside .runtime rejected', $r['code'] !== 0);

// 8. Host other than 127.0.0.1 is rejected.
$r = $child(array_merge($valid, ['CENTRO_DB_HOST' => 'localhost']));
$record('8 non-loopback host rejected', $r['code'] !== 0);

// 9. Port that differs from the marker is rejected.
$r = $child(array_merge($valid, ['CENTRO_DB_PORT' => (string)($port === 59999 ? 59998 : 59999)]));
$record('9 port different from marker rejected', $r['code'] !== 0);

// 10. Database name outside the synthetic pattern is rejected.
$r = $child(array_merge($valid, ['CENTRO_DB_NAME' => 'centro_auth_operativa']));
$record('10 non-synthetic database name rejected', $r['code'] !== 0);

// 11. A marker whose data directory is the operational one is rejected even with a correct token.
$caseMarker = $instanceDir . '/case-operational-' . bin2hex(random_bytes(4)) . '.json';
$caseToken = bin2hex(random_bytes(32));
file_put_contents($caseMarker, json_encode(['purpose' => 'synthetic-isolated-test', 'port' => $port, 'host' => '127.0.0.1', 'data_dir' => $runtime . '/mariadb/data', 'token_sha256' => hash('sha256', $caseToken)]));
$r = $child(['CENTRO_ISOLATED_TEST_CONFIG' => '1', 'CENTRO_ISOLATED_TEST_MARKER' => $caseMarker, 'CENTRO_ISOLATED_TEST_TOKEN' => $caseToken]);
@unlink($caseMarker);
$record('11 operational data directory rejected', $r['code'] !== 0 && $secretFree($r));

// 12. Missing parameters fail safely.
$r = $child(['CENTRO_ISOLATED_TEST_CONFIG' => '1'], ['CENTRO_ISOLATED_TEST_MARKER', 'CENTRO_ISOLATED_TEST_TOKEN']);
$record('12 missing marker and token fail safely', $r['code'] !== 0 && str_contains($r['out'] . $r['err'], 'Isolated test configuration rejected'));

// 13. Short token is rejected.
$r = $child(array_merge($valid, ['CENTRO_ISOLATED_TEST_TOKEN' => 'short']));
$record('13 short token rejected', $r['code'] !== 0);

$failed = array_filter($results, fn($x) => !$x['passed']);
echo json_encode(['cases' => $results, 'total' => count($results), 'failed' => count($failed)], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed ? 1 : 0);
