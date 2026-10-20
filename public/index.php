<?php
declare(strict_types=1);
// The PHP development server may route missing files to this entry. Reject them before bootstrapping.
$entryDirectory = str_replace(chr(92), '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$entryPrefix = rtrim($entryDirectory, '/.') . '/';
$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (!in_array($requestPath, [$entryPrefix, $entryPrefix . 'index.php'], true) || !empty($_SERVER['PATH_INFO'])) {
    http_response_code(404);
    exit;
}
try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    if (!$auth->current(true)) {
        header('Location: login.php', true, 303);
        exit;
    }
    require dirname(__DIR__) . '/app/views/dashboard.php';
} catch (Throwable $error) {
    error_log('Centro dashboard initialization failed [' . get_class($error) . ']');
    http_response_code(503);
    exit('El acceso privado no est? disponible. Revis? la configuraci?n del servidor.');
}
