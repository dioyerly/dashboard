<?php
// Minimal deployment diagnostic - no dependencies, no bootstrap
// Access: https://panel.estrategia.site/test-deploy.php

echo "<h1>Centro de Operaciones - Deployment Test</h1>";
echo "<pre>";

// System info
echo "=== SERVER INFO ===\n";
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'undefined') . "\n";
echo "Script Filename: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'undefined') . "\n";
echo "Script Name: " . ($_SERVER['SCRIPT_NAME'] ?? 'undefined') . "\n";
echo "Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'undefined') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Apache Modules: ";
print_r(apache_get_modules());
echo "\n";

// File structure check
echo "\n=== FILE STRUCTURE ===\n";
$checks = [
    'public/index.php' => __DIR__ . '/public/index.php',
    'public/setup.php' => __DIR__ . '/public/setup.php',
    'app/bootstrap.php' => __DIR__ . '/app/bootstrap.php',
    'config/settings.php' => __DIR__ . '/config/settings.php',
    'config/.htaccess' => __DIR__ . '/config/.htaccess',
];

foreach ($checks as $display => $path) {
    $exists = is_file($path);
    echo "$display: " . ($exists ? "✓ EXISTS" : "✗ MISSING") . " ($path)\n";
}

// Check if rewrites are working
echo "\n=== REWRITE TEST ===\n";
echo "If you accessed this via /test-deploy.php:\n";
echo "  - RewriteEngine is OFF or this is the real file location\n";
echo "If you accessed this via different path:\n";
echo "  - RewriteEngine is WORKING\n";
echo "Actual Request: " . ($_SERVER['REQUEST_URI'] ?? 'unknown') . "\n";

// Test directory access
echo "\n=== DIRECTORY ACCESS TEST ===\n";
if (is_dir(__DIR__ . '/app')) {
    echo "app/ directory: ✓ readable from PHP\n";
} else {
    echo "app/ directory: ✗ cannot access\n";
}

if (is_dir(__DIR__ . '/public')) {
    echo "public/ directory: ✓ readable from PHP\n";
} else {
    echo "public/ directory: ✗ cannot access\n";
}

echo "</pre>";
echo "<hr>";
echo "<p><strong>Next steps:</strong></p>";
echo "<ol>";
echo "<li>Report what you see above</li>";
echo "<li>Try accessing /public/index.php directly to see if that works</li>";
echo "<li>Check if /app/bootstrap.php returns 403 (should be blocked)</li>";
echo "</ol>";
