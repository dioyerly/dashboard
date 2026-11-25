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
    // Clave compartida (32+ caracteres): la misma en cada app (ECOSYSTEM_KEY / ecosystem_key) y aca. Conecta Bookwat, Zuhause, Gaku y descargar sin mas claves.
    // 'ecosystem_key' => 'PONE_ACA_UNA_CLAVE_LARGA_DE_32_O_MAS_CARACTERES',
    // Apps conectadas. La clave es el id en minusculas y debe coincidir con el campo "source" de los eventos.
    // 'sources' => ['zuhause' => ['name' => 'Zuhause', 'url' => 'https://zuhause.estrategia.site/export.php', 'token' => 'TOKEN_32_O_MAS']],
];
