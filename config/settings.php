<?php
declare(strict_types=1);

// Only read environment or an explicitly private override; never connect here.
$root = dirname(__DIR__);

// Detect where we are and calculate private directory accordingly
// If deployed in Hostinger structure (/public_html/), private dir must be OUTSIDE public_html
$current_dir_name = basename($root);
if ($current_dir_name === 'public_html') {
    // We're in /domains/site/public_html/, private should be in /home/user/centro_private
    // Go up 3 levels: public_html -> site -> domains -> user home
    $private_base = dirname(dirname(dirname(__DIR__)));  // /home/user
    $default_private_dir = $private_base . '/centro_operaciones_private/auth';
} else {
    // Standard deployment: private dir alongside app/, config/, etc.
    $default_private_dir = $root . '/storage/private/auth';
}

$env = static fn(string $key, string $default = ''): string => (($v = getenv($key)) === false ? $default : $v);
$int = static function (string $key, int $default) use ($env): int {
    $value = $env($key, (string)$default);
    if (!ctype_digit($value) || (int)$value < 1) throw new RuntimeException('Invalid positive configuration value');
    return (int)$value;
};
$settings = [
    'root' => $root,
    'environment' => $env('CENTRO_ENV', 'local'),
    'private_dir' => $env('CENTRO_PRIVATE_DIR', $default_private_dir),
    'auth_driver' => $env('CENTRO_AUTH_DRIVER', 'file'),
    'business_api_enabled' => $env('CENTRO_BUSINESS_API_ENABLED', '0') === '1',
    'financial_api_enabled' => $env('CENTRO_FINANCIAL_API_ENABLED', '0') === '1',
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
