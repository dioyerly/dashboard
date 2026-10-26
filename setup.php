<?php
// Centro de Operaciones - Setup configurator
// Serves public/setup.php (or handles setup directly if Hostinger deploys inside public_html)

if (is_file(__DIR__ . '/public/setup.php')) {
    // Standard structure: public/ as subdirectory
    require __DIR__ . '/public/setup.php';
} else {
    // Hostinger structure: everything in same directory
    // Find correct setup.php location
    if (is_file(__DIR__ . '/config/settings.php')) {
        // Everything is in current directory (Hostinger case)
        require __DIR__ . '/public/setup.php';
    } else {
        die('Setup file not found.');
    }
}
