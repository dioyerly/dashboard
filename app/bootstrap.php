<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuthStore.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Auth.php';

$config = require dirname(__DIR__) . '/config/settings.php';
if (PHP_VERSION_ID < 80300) throw new RuntimeException('PHP 8.3+ required');

function centro_private_directory(string $path): string
{
    // Create directory if it doesn't exist
    if (!is_dir($path)) {
        if (!mkdir($path, 0700, true)) {
            throw new RuntimeException('Cannot create private directory: ' . $path);
        }
    }

    // Return the resolved path
    $resolved = realpath($path);
    if (!$resolved) {
        throw new RuntimeException('Cannot resolve private directory path: ' . $path);
    }

    return $resolved;
}

$config['private_dir'] = centro_private_directory($config['private_dir']);
centro_private_directory($config['root'] . '/app');
centro_private_directory($config['root'] . '/config');
centro_private_directory($config['private_dir'] . '/sessions');
$store = match ($config['auth_driver']) {
    'file' => new Centro\FileAuthStore($config['private_dir']),
    'mysql' => new Centro\MysqlAuthStore(Centro\Database::connection($config['db'])),
    default => throw new RuntimeException('Invalid authentication driver'),
};
$auth = new Centro\Auth($store, $config);
if (PHP_SAPI !== 'cli') {
    Centro\Security::headers();
    if ($config['environment'] === 'local' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        http_response_code(403); exit('Acceso local únicamente.');
    }
    if (!Centro\Security::secureTransport() && $config['environment'] !== 'local') {
        http_response_code(426); exit('Se requiere HTTPS.');
    }
    Centro\Security::session($config);
}
