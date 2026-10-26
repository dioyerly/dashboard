<?php
declare(strict_types=1);

/**
 * Centro de Operaciones - Automatic Setup
 *
 * Configure database credentials without editing files.
 * This file is safe to delete after setup is complete.
 * Access it at: https://your-domain.com/setup.php
 */

$configFile = dirname(__DIR__) . '/config/private.php';
$alreadyConfigured = is_file($configFile);

// If already configured and not explicitly accessing setup, redirect
if ($alreadyConfigured && !isset($_POST['force_reconfigure'])) {
    header('Location: login.php', true, 303);
    exit;
}

$errors = [];
$success = false;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_host'])) {
    try {
        // Validate input
        $db_host = trim($_POST['db_host'] ?? '');
        $db_port = trim($_POST['db_port'] ?? '3306');
        $db_name = trim($_POST['db_name'] ?? '');
        $db_user = trim($_POST['db_user'] ?? '');
        $db_password = $_POST['db_password'] ?? '';

        if (!$db_host) $errors[] = 'Host de base de datos es requerido';
        if (!$db_name) $errors[] = 'Nombre de base de datos es requerido';
        if (!$db_user) $errors[] = 'Usuario es requerido';
        if (!$db_password) $errors[] = 'Contraseña es requerida';
        if (!ctype_digit($db_port) || (int)$db_port < 1 || (int)$db_port > 65535) {
            $errors[] = 'Puerto debe ser un número entre 1 y 65535';
        }

        if (empty($errors)) {
            // Test connection
            try {
                $dsn = 'mysql:host=' . $db_host . ';port=' . $db_port . ';dbname=' . $db_name . ';charset=utf8mb4';
                $pdo = new PDO($dsn, $db_user, $db_password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5
                ]);
                $pdo = null; // Close connection

                // Connection successful - write config
                $configContent = "<?php\ndeclare(strict_types=1);\n\nreturn [\n" .
                    "    'environment' => 'production',\n" .
                    "    'auth_driver' => 'mysql',\n" .
                    "    'private_dir' => dirname(__DIR__) . '/storage/private/auth',\n" .
                    "    'db' => [\n" .
                    "        'enabled' => true,\n" .
                    "        'host' => '" . addslashes($db_host) . "',\n" .
                    "        'port' => " . (int)$db_port . ",\n" .
                    "        'name' => '" . addslashes($db_name) . "',\n" .
                    "        'user' => '" . addslashes($db_user) . "',\n" .
                    "        'password' => '" . addslashes($db_password) . "',\n" .
                    "        'tls_ca' => '',\n" .
                    "    ],\n" .
                    "    'migration_db' => [\n" .
                    "        'enabled' => false,\n" .
                    "        'host' => '" . addslashes($db_host) . "',\n" .
                    "        'port' => " . (int)$db_port . ",\n" .
                    "        'name' => '" . addslashes($db_name) . "',\n" .
                    "        'user' => '" . addslashes($db_user) . "',\n" .
                    "        'password' => '" . addslashes($db_password) . "',\n" .
                    "        'tls_ca' => '',\n" .
                    "    ],\n" .
                    "];\n";

                if (!is_dir(dirname($configFile))) {
                    mkdir(dirname($configFile), 0755, true);
                }

                if (file_put_contents($configFile, $configContent) === false) {
                    $errors[] = 'No se puede escribir el archivo de configuración. Verifica los permisos.';
                } else {
                    $success = true;
                }
            } catch (PDOException $e) {
                $errors[] = 'No se puede conectar a la base de datos: ' . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Error: ' . $e->getMessage();
    }
}

// Detect current values if already configured
$db_host = '';
$db_port = '3306';
$db_name = '';
$db_user = '';
if ($alreadyConfigured && isset($_POST['force_reconfigure'])) {
    @include $configFile;
    if (isset($GLOBALS['config'])) {
        $db_host = $GLOBALS['config']['db']['host'] ?? '';
        $db_port = $GLOBALS['config']['db']['port'] ?? '3306';
        $db_name = $GLOBALS['config']['db']['name'] ?? '';
        $db_user = $GLOBALS['config']['db']['user'] ?? '';
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Centro de Operaciones - Configuración</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; background: #f5f5f5; color: #333; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 100%; max-width: 400px; padding: 30px; }
        h1 { font-size: 24px; margin-bottom: 10px; color: #2c3e50; }
        .subtitle { color: #666; font-size: 14px; margin-bottom: 25px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: 600; margin-bottom: 5px; color: #333; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        input:focus { outline: none; border-color: #2c3e50; background: #f9f9f9; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; margin-bottom: 15px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 15px; }
        button { width: 100%; padding: 12px; background: #2c3e50; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; font-weight: 600; }
        button:hover { background: #1a252f; }
        .info { background: #e7f3ff; border-left: 4px solid #2196F3; padding: 12px; margin-bottom: 20px; border-radius: 4px; font-size: 13px; }
        .next-steps { background: #e8f5e9; border-left: 4px solid #4CAF50; padding: 12px; margin-top: 20px; border-radius: 4px; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Centro de Operaciones</h1>
        <p class="subtitle">Configurar base de datos</p>

        <?php if ($success): ?>
            <div class="success">
                ✓ Configuración guardada correctamente
            </div>
            <div class="next-steps">
                <strong>Próximos pasos:</strong>
                <ol style="margin-left: 20px; margin-top: 10px;">
                    <li>Abre <a href="login.php" style="color: #1976d2;">login.php</a> para verificar que funciona</li>
                    <li>Ejecuta las migraciones: <code>php tools/migrate.php --database=<?php echo htmlspecialchars($db_name); ?> --execute-install --confirm-install=<?php echo htmlspecialchars($db_name); ?></code></li>
                    <li>Crea el administrador: <code>php tools/create_admin.php --email=tu@email.com --password-stdin</code></li>
                    <li>Puedes eliminar este archivo (setup.php)</li>
                </ol>
            </div>
            <br>
            <a href="login.php" style="display: block; text-align: center; padding: 12px; background: #2c3e50; color: white; text-decoration: none; border-radius: 4px;">Ir al login</a>
        <?php else: ?>
            <div class="info">
                Ingresa las credenciales de tu base de datos MySQL en Hostinger. Esta configuración se guardará en <code>config/private.php</code>.
            </div>

            <?php if (!empty($errors)): ?>
                <div class="error">
                    <?php foreach ($errors as $error): ?>
                        ✗ <?php echo htmlspecialchars($error); ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="db_host">Host (servidor)</label>
                    <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($db_host); ?>" placeholder="srv801.hstgr.io" required>
                </div>

                <div class="form-group">
                    <label for="db_port">Puerto</label>
                    <input type="text" id="db_port" name="db_port" value="<?php echo htmlspecialchars($db_port); ?>" placeholder="3306" required>
                </div>

                <div class="form-group">
                    <label for="db_name">Base de datos</label>
                    <input type="text" id="db_name" name="db_name" value="<?php echo htmlspecialchars($db_name); ?>" placeholder="u748338755_centro_ops" required>
                </div>

                <div class="form-group">
                    <label for="db_user">Usuario</label>
                    <input type="text" id="db_user" name="db_user" value="<?php echo htmlspecialchars($db_user); ?>" placeholder="u748338755_centro_ops" required>
                </div>

                <div class="form-group">
                    <label for="db_password">Contraseña</label>
                    <input type="password" id="db_password" name="db_password" placeholder="Tu contraseña" required>
                </div>

                <button type="submit">Guardar configuración</button>

                <?php if ($alreadyConfigured): ?>
                    <input type="hidden" name="force_reconfigure" value="1">
                    <p style="text-align: center; margin-top: 10px; font-size: 12px; color: #666;">
                        (Estás reemplazando la configuración existente)
                    </p>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
