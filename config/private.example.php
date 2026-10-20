<?php
declare(strict_types=1);

// Optional: copy to private.php, which Git ignores. No database is enabled here.
return [
    'environment' => 'local',
    'auth_driver' => 'file',
    'db' => [
        'enabled' => false,
        'host' => '',
        'name' => '',
        'user' => '',
        'password' => '',
    ],
];
