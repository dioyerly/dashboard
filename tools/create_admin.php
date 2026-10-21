<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '1');
try {
    $preConfig = require dirname(__DIR__) . '/config/settings.php';
    $config = $preConfig;
    if (($preConfig['auth_driver'] ?? '') !== 'mysql' || empty($preConfig['db']['enabled'])
        || ($preConfig['sql_api_enabled'] ?? false) === true
        || ($preConfig['business_api_enabled'] ?? false) === true
        || ($preConfig['financial_api_enabled'] ?? false) === true
        || ($preConfig['business_storage'] ?? 'local') === 'sql') {
        throw new RuntimeException('Private MySQL auth configuration with business SQL disabled is required');
    }
    require dirname(__DIR__) . '/app/bootstrap.php';
    $options = getopt('', ['email:', 'password-stdin']);
    $email = strtolower(trim((string)($options['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || !array_key_exists('password-stdin', $options)) {
        throw new RuntimeException('Usage: php tools/create_admin.php --email=YOUR_EMAIL --password-stdin');
    }
    if ($store->admin() !== null) throw new RuntimeException('An administrator already exists; initialization refused');
    $password = rtrim((string)fgets(STDIN, 4097), "\r\n");
    if (strlen($password) < 12 || strlen($password) > 72) throw new RuntimeException('Password must contain 12 to 72 bytes');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $password = '';
    $store->create($email, $hash);
    fwrite(STDOUT, "Administrator initialized; business and financial SQL APIs remain disabled.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Initialization failed: ' . ($config['auth_driver'] ?? 'file') . ' (check private configuration or whether an administrator already exists).' . "\n");
    exit(1);
}
