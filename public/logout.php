<?php
declare(strict_types=1);
try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    if (!$auth->current()) { header('Location: login.php', true, 303); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Centro\Security::validCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) { http_response_code(403); exit('Solicitud no válida.'); }
        Centro\Security::destroy();
        header('Location: login.php', true, 303); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET, POST'); exit; }
    $csrf = htmlspecialchars(Centro\Security::csrf(), ENT_QUOTES, 'UTF-8');
} catch (Throwable $error) { http_response_code(503); exit('El acceso privado no está disponible.'); }
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Cerrar sesión · Centro de Operaciones</title><link rel="stylesheet" href="assets/css/app.css"><link rel="stylesheet" href="assets/css/auth.css"></head>
<body class="bare auth-page"><main class="card auth-confirm"><div class="eyebrow">Acceso privado</div><h1>Cerrar sesión</h1><p>Se cerrará el acceso al servidor. Los datos locales del negocio se conservan en este navegador.</p><form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= $csrf ?>"><div class="btn-row"><a class="btn" href="index.php">Volver al panel</a><button class="btn btn-primary" type="submit">Cerrar sesión</button></div></form></main></body></html>
