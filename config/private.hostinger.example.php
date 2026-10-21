<?php
declare(strict_types=1);

// Private hosting example. Keep this file outside the public document root.
// SQL remains disabled until every explicit switch and credential is supplied.
return [
    'environment' => 'production',
    'sql_api_enabled' => false,
    'auth_driver' => 'mysql',
    'business_api_enabled' => false,
    'business_storage' => 'local',
    'financial_api_enabled' => false,
    'private_dir' => '/home/ACCOUNT/private/centro-operaciones',
    'db' => [
        'enabled' => false,
        'host' => '',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'password' => '',
        'tls_ca' => '',
    ],
];