<?php
// Detailed bootstrap diagnostics
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Bootstrap Diagnostics</h1>";
echo "<pre>";

try {
    echo "=== Loading config ===\n";
    $root = dirname(__DIR__);
    echo "Root: $root\n";

    $config = require $root . '/config/settings.php';
    echo "Config loaded\n";
    echo "Private dir (default): " . $config['private_dir'] . "\n";
    echo "Auth driver: " . $config['auth_driver'] . "\n";
    echo "Environment: " . $config['environment'] . "\n";

    echo "\n=== Directory validation ===\n";
    echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'undefined') . "\n";

    $private_dir = $config['private_dir'];
    echo "Testing private_dir: $private_dir\n";

    // Test if we can create the directory
    if (!is_dir($private_dir)) {
        echo "Directory doesn't exist, attempting to create...\n";
        $created = mkdir($private_dir, 0700, true);
        echo "mkdir result: " . ($created ? "true" : "false") . "\n";
    } else {
        echo "Directory exists\n";
    }

    $resolved = realpath($private_dir);
    echo "Resolved path: " . ($resolved ? $resolved : "FALSE (couldn't resolve)") . "\n";

    if ($resolved) {
        $public = realpath($root . '/public');
        $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;

        echo "\nPublic path: " . ($public ? $public : "FALSE") . "\n";
        echo "Doc root path: " . ($docroot ? $docroot : "FALSE") . "\n";

        if ($public) {
            $public_start = $public . '/';
            $private_start = $resolved . '/';
            echo "\nPrivate starts with public? " . (str_starts_with($private_start, $public_start) ? "YES (BAD)" : "NO (good)") . "\n";
        }

        if ($docroot) {
            $docroot_start = $docroot . '/';
            $private_start = $resolved . '/';
            echo "Private starts with docroot? " . (str_starts_with($private_start, $docroot_start) ? "YES (BAD)" : "NO (good)") . "\n";
        }
    }

    echo "\n=== Bootstrap load ===\n";
    require $root . '/app/bootstrap.php';
    echo "Bootstrap loaded successfully!\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "</pre>";
