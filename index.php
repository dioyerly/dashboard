<?php
// Centro de Operaciones - Root entry point
// Serves public/index.php (or handles dashboard directly if Hostinger deploys inside public_html)

if (is_file(__DIR__ . '/public/index.php')) {
    // Standard structure: config/, app/ in parent, public/ as subdirectory
    require __DIR__ . '/public/index.php';
} else {
    // Hostinger structure: everything in same directory
    // Find if we're actually in a public_html folder
    if (is_file(__DIR__ . '/../../config/settings.php')) {
        // We're in public_html, go up to parent
        require dirname(dirname(__DIR__)) . '/app/bootstrap.php';
        if (!$auth->current(true)) {
            header('Location: login.php', true, 303);
            exit;
        }
        require dirname(dirname(__DIR__)) . '/app/views/dashboard.php';
    } elseif (is_file(__DIR__ . '/config/settings.php')) {
        // Everything is in current directory (Hostinger case)
        require __DIR__ . '/app/bootstrap.php';
        if (!$auth->current(true)) {
            header('Location: login.php', true, 303);
            exit;
        }
        require __DIR__ . '/app/views/dashboard.php';
    } else {
        die('Configuration not found. Cannot determine directory structure.');
    }
}
