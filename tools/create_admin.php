<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
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
    fwrite(STDOUT, "Administrator initialized in private authentication storage. Business data remains local; MySQL sync is disabled.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Initialization failed: ' . ($config['auth_driver'] ?? 'file') . ' (check private configuration or whether an administrator already exists).' . "\n");
    exit(1);
}
