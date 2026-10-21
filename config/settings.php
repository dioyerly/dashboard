<?php
declare(strict_types=1);

// Only read environment or an explicitly private override; never connect here.
$root = dirname(__DIR__);
$env = static fn(string $key, string $default = ''): string => (($v = getenv($key)) === false ? $default : $v);
$int = static function (string $key, int $default) use ($env): int {
    $value = $env($key, (string)$default);
    if (!ctype_digit($value) || (int)$value < 1) throw new RuntimeException('Invalid positive configuration value');
    return (int)$value;
};
$settings = [
    'root' => $root,
    'environment' => $env('CENTRO_ENV', 'local'),
    'private_dir' => $env('CENTRO_PRIVATE_DIR', $root . '/storage/private/auth'),
    'auth_driver' => $env('CENTRO_AUTH_DRIVER', 'file'),
    'business_api_enabled' => $env('CENTRO_BUSINESS_API_ENABLED', '0') === '1',
    'business_storage' => $env('CENTRO_BUSINESS_STORAGE', 'local'),
    'financial_api_enabled' => $env('CENTRO_FINANCIAL_API_ENABLED', '0') === '1',
    'sql_api_enabled' => $env('CENTRO_SQL_API_ENABLED', '0') === '1',
    'idle_seconds' => $int('CENTRO_IDLE_SECONDS', 1800),
    'absolute_seconds' => $int('CENTRO_ABSOLUTE_SECONDS', 28800),
    'rate_window' => $int('CENTRO_RATE_WINDOW', 900),
    'rate_limit' => $int('CENTRO_RATE_LIMIT', 5),
    'rate_global_limit' => $int('CENTRO_RATE_GLOBAL_LIMIT', 30),
    'db' => [
        'enabled' => $env('CENTRO_DB_ENABLED', '0') === '1',
        'host' => $env('CENTRO_DB_HOST'),
        'port' => $int('CENTRO_DB_PORT', 3306),
        'name' => $env('CENTRO_DB_NAME'),
        'user' => $env('CENTRO_DB_USER'),
        'password' => $env('CENTRO_DB_PASSWORD'),
        'tls_ca' => $env('CENTRO_DB_TLS_CA'),
    ],
    // Temporary DDL-only account used exclusively by tools/migrate.php.
    'migration_db' => [
        'enabled' => $env('CENTRO_MIGRATION_DB_ENABLED', '0') === '1',
        'host' => $env('CENTRO_MIGRATION_DB_HOST'),
        'port' => $int('CENTRO_MIGRATION_DB_PORT', 3306),
        'name' => $env('CENTRO_MIGRATION_DB_NAME'),
        'user' => $env('CENTRO_MIGRATION_DB_USER'),
        'password' => $env('CENTRO_MIGRATION_DB_PASSWORD'),
        'tls_ca' => $env('CENTRO_MIGRATION_DB_TLS_CA'),
    ],
];
// Isolated synthetic test mode (opt-in, CLI only). Without CENTRO_ISOLATED_TEST_CONFIG the loader below is unchanged.
// When the flag is present it must satisfy every condition or the process is rejected; private.php is then never read.
// Required: CLI SAPI, flag == "1", a marker inside .runtime created for a synthetic instance, the marker token
// (its SHA-256 must match), CENTRO_ENV=local, host 127.0.0.1, the marker port, a synthetic database name and a data
// directory that is not the operational one. Messages never reveal values.
if (!function_exists('centro_isolated_test_mode')) {
    function centro_isolated_test_mode(string $root): bool
    {
        $flag = getenv('CENTRO_ISOLATED_TEST_CONFIG');
        if ($flag === false) return false;
        $reject = static function (): never { throw new RuntimeException('Isolated test configuration rejected'); };
        if ($flag !== '1' || PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) $reject();
        $marker = getenv('CENTRO_ISOLATED_TEST_MARKER');
        $token = getenv('CENTRO_ISOLATED_TEST_TOKEN');
        if (!is_string($marker) || $marker === '' || !is_string($token) || strlen($token) < 32) $reject();
        $markerReal = realpath($marker);
        if ($markerReal === false) $reject();
        $norm = static fn(string $p): string => strtolower(str_replace('\\', '/', $p));
        $markerNorm = $norm($markerReal);
        // Structure: <checkout>/.runtime/synthetic-test-mariadb/isolated-marker.json, created only by tools/synthetic_test_instance.ps1.
        if (!str_ends_with($markerNorm, '/.runtime/synthetic-test-mariadb/isolated-marker.json')) $reject();
        $instanceDir = substr($markerNorm, 0, -strlen('/isolated-marker.json'));
        $data = json_decode((string)file_get_contents($markerReal), true, 8);
        if (!is_array($data) || ($data['purpose'] ?? '') !== 'synthetic-isolated-test') $reject();
        if (!hash_equals((string)($data['token_sha256'] ?? ''), hash('sha256', $token))) $reject();
        // The data directory must be exactly the synthetic instance's own data directory, never the operational one.
        $dataDir = $norm((string)($data['data_dir'] ?? ''));
        if ($dataDir !== $instanceDir . '/data') $reject();
        if (str_contains($dataDir, '/.runtime/mariadb/')) $reject();
        if (getenv('CENTRO_ENV') !== 'local' || getenv('CENTRO_DB_HOST') !== '127.0.0.1') $reject();
        if ((int)getenv('CENTRO_DB_PORT') !== (int)($data['port'] ?? -1)) $reject();
        if (!preg_match('/\Acentro_auth_synthetic_[a-z0-9_]+\z/D', (string)getenv('CENTRO_DB_NAME'))) $reject();
        return true;
    }
}$isolatedTest = centro_isolated_test_mode(dirname(__DIR__));
// Optional private PHP configuration for hosting environments without environment management.
// Never place this file or this config directory inside the HTTP document root.
$privateConfig = __DIR__ . '/private.php';
if (!$isolatedTest && is_file($privateConfig)) {
    $overrides = require $privateConfig;
    if (!is_array($overrides)) throw new RuntimeException('Invalid private configuration');
    $settings = array_replace_recursive($settings, $overrides);
}
return $settings;
