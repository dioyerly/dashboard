<?php
declare(strict_types=1);
try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    $signedIn = $auth->current() !== null;
} catch (Throwable $error) {
    error_log('Centro login initialization failed [' . get_class($error) . ']');
    http_response_code(503);
    exit('El acceso privado no está disponible. Revisá la configuración del servidor.');
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Acceso privado · Centro de Operaciones</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
<link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="bare auth-page">
<main class="welcome" aria-labelledby="access-title">
  <div class="art"><h1>Todo tu ecosistema,<br><em>un solo pulso.</em></h1><p>Centro de Operaciones<br>Un espacio privado para tu negocio digital.</p></div>
  <div class="frm">
    <div class="eyebrow">Acceso privado</div>
    <h2 id="access-title">Ingresá a tu panel</h2>
<?php if ($signedIn): ?>
    <p>Tu sesión ya está iniciada.</p>
    <a class="btn btn-primary" href="index.php">Volver al panel</a>
    <a class="btn" href="logout.php">Cerrar sesión</a>
<?php else: ?>
    <form id="server-login">
      <div class="field"><label for="email">Correo electrónico</label><input class="input" id="email" name="email" type="email" autocomplete="username" maxlength="254" required autofocus></div>
      <div class="field"><label for="password">Contraseña</label><input class="input" id="password" name="password" type="password" autocomplete="current-password" maxlength="72" required></div>
      <p class="err-msg" id="auth-message" role="status" aria-live="polite"></p>
      <button class="btn btn-primary auth-submit" type="submit" disabled>Ingresar</button>
      <noscript><p>Activá JavaScript para iniciar sesión mediante la API privada.</p></noscript>
    </form>
<?php endif; ?>
    <p class="small muted auth-note">Los datos del negocio siguen guardándose en este navegador. Todavía no hay sincronización con MySQL.</p>
    <p class="tiny muted">Acceso de administrador único. No hay registro público.</p>
  </div>
</main>
<script src="assets/js/auth-login.js"></script>
</body>
</html>
