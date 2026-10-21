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
];
// Optional private PHP configuration for hosting environments without environment management.
// Never place this file or this config directory inside the HTTP document root.
$privateConfig = __DIR__ . '/private.php';
if (is_file($privateConfig)) {
    $overrides = require $privateConfig;
    if (!is_array($overrides)) throw new RuntimeException('Invalid private configuration');
    $settings = array_replace_recursive($settings, $overrides);
}
return $settings;
