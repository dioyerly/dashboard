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
    // Apps conectadas. La clave es el id en minusculas y debe coincidir con el campo "source" de los eventos.
    // 'sources' => ['zuhause' => ['name' => 'Zuhause', 'url' => 'https://zuhause.estrategia.site/export.php', 'token' => 'TOKEN_32_O_MAS']],
];
