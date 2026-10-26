<?php
// Detailed bootstrap diagnostics
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Bootstrap Diagnostics</h1>";
echo "<pre>";

try {
    echo "=== Detecting directory structure ===\n";
    echo "Current __DIR__: " . __DIR__ . "\n";

    // Auto-detect structure
    if (is_file(__DIR__ . '/config/settings.php')) {
        // Everything in current directory (Hostinger case)
        $root = __DIR__;
        echo "Detected: Everything in current directory (public_html)\n";
    } elseif (is_file(dirname(__DIR__) . '/config/settings.php')) {
        // Standard structure
        $root = dirname(__DIR__);
        echo "Detected: Standard structure (config in parent)\n";
    } else {
        die("ERROR: Cannot find config/settings.php\n");
    }

    echo "Root: $root\n";

    echo "\n=== Loading config ===\n";
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
            $is_inside = str_starts_with($private_start, $public_start);
            echo "\nPrivate inside public? " . ($is_inside ? "YES (BAD)" : "NO (good)") . "\n";
        }

        if ($docroot) {
            $docroot_start = $docroot . '/';
            $private_start = $resolved . '/';
            $is_inside = str_starts_with($private_start, $docroot_start);
            echo "Private inside docroot? " . ($is_inside ? "YES (BAD - PROBLEM!)" : "NO (good)") . "\n";
        }
    }

    echo "\n=== Attempting bootstrap load ===\n";
    require $root . '/app/bootstrap.php';
    echo "Bootstrap loaded successfully!\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "</pre>";
