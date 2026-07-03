<?php
/** @var GastosHogar\Auth\Auth $auth */
/** @var bool $authErr */
/** @var string $lockoutMsg */
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gastos del Hogar</title>
<link rel="stylesheet" href="<?= asset('assets/css/login.css') ?>">
</head>
<body>
<div class="box">
  <span class="ico">🏠</span>
  <h1>Gastos del Hogar</h1>
  <p class="sub">Ingresá tu usuario y contraseña para acceder</p>
  <form method="post">
    <?= $auth->csrfField() ?>
    <input type="text" name="user" placeholder="Usuario" autofocus autocomplete="username">
    <input type="password" name="pwd" placeholder="••••••••" autocomplete="current-password">
    <label class="remember">
      <input type="checkbox" name="remember" value="1"> Recordarme
    </label>
    <button type="submit">Entrar</button>
  </form>
  <?php if ($lockoutMsg !== ''): ?>
  <p class="msg-lock">🔒 <?= e($lockoutMsg) ?></p>
  <?php elseif ($authErr): ?>
  <p class="msg-err">Usuario o contraseña incorrectos. Intentá de nuevo.</p>
  <?php endif ?>
  <?php // DEBUG TEMPORAL remember-me movil - sacar despues de diagnosticar ?>
  <p style="font-size:.7rem;color:#94a3b8;margin-top:1rem">
    debug: cookie remember_me <?= isset($_COOKIE['remember_me']) ? 'presente, no autenticó' : 'ausente' ?>
  </p>
</div>
</body>
</html>
