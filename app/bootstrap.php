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
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('Private directory unavailable');
    $resolved = realpath($path);
    $public = realpath(dirname(__DIR__) . '/public');
    $docroot = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '' ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    foreach (array_filter([$public, $docroot]) as $exposed) {
        $a = str_replace('\\', '/', $resolved) . '/';
        $b = str_replace('\\', '/', $exposed) . '/';
        if (PHP_OS_FAMILY === 'Windows') { $a = strtolower($a); $b = strtolower($b); }
        if (str_starts_with($a, $b)) throw new RuntimeException('Private path inside public document root');
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
