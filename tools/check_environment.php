<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    $report = ['php_version' => PHP_VERSION, 'php_compatible' => PHP_VERSION_ID >= 80300,
        'extensions' => array_fill_keys(['PDO', 'pdo_mysql', 'session', 'json'], false),
        'database_connection_enabled' => $config['db']['enabled'], 'database_connected' => false,
        'auth_driver' => $config['auth_driver'], 'sessions' => 'PHP files outside public directory',
        'business_storage' => 'IndexedDB, not synchronized', 'migration_executed' => false];
    foreach ($report['extensions'] as $extension => $_) $report['extensions'][$extension] = extension_loaded($extension);
    // Default is strictly offline. This optional flag is for a separately authorized phase.
    if (in_array('--database', $argv, true)) {
        $pdo = Centro\Database::connection($config['db']);
        $version = $pdo->prepare('SELECT VERSION() AS version');
        $version->execute();
        $value = $version->fetch()['version'];
        preg_match('/(?:5\.5\.5-)?(\d+\.\d+\.\d+)/', $value, $match);
        $maria = stripos($value, 'MariaDB') !== false;
        $report['database'] = ['version' => $value, 'family' => $maria ? 'MariaDB' : 'MySQL',
            'recommended_minimum' => $maria ? '10.6.0' : '8.0.16',
            'version_compatible' => isset($match[1]) && version_compare($match[1], $maria ? '10.6.0' : '8.0.16', '>=')];
        $engine = $pdo->prepare('SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = ?');
        $engine->execute(['InnoDB']);
        $report['database']['innodb'] = in_array($engine->fetchColumn(), ['YES', 'DEFAULT'], true);
        $report['database_connected'] = true;
    } else {
        $report['database_pending'] = ['Actual Hostinger server version, InnoDB, sql_mode, migration DDL and permissions unverified',
            'No local MySQL server found; no remote connection or SQL execution authorized'];
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "Environment verification failed; check private configuration. No credentials printed.\n");
    exit(1);
}
