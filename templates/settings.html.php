<?php
/** @var GastosHogar\Auth\Auth $auth */
/** @var GastosHogar\User\User $actor */
/** @var GastosHogar\Config $config */
/** @var GastosHogar\Person\Person[] $people */
/** @var string|null $settingsError */
/** @var string|null $settingsSuccess */
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Configuración — Gastos del Hogar</title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body>

<header>
  <h1>🏠 Gastos del Hogar</h1>
  <div style="display:flex;gap:.75rem;align-items:center">
    <a href="/" class="logout-btn" style="text-decoration:none">← Volver</a>
    <?php if ($actor->isAdmin()): ?>
    <a href="?page=admin_users" class="logout-btn" style="text-decoration:none" title="Usuarios">👤 Usuarios</a>
    <?php endif ?>
    <form method="post">
      <?= $auth->csrfField() ?>
      <button type="submit" name="logout" value="1" class="logout-btn">Salir</button>
    </form>
  </div>
</header>

<main>

  <div class="settings-card">
    <h2 class="settings-title">⚙️ Configuración de personas</h2>
    <p class="settings-subtitle">
      Agregá o quitá las personas que comparten los gastos del hogar.<br>
      Cada gasto se asigna a una persona al registrarlo.
    </p>

    <?php if ($settingsError !== null): ?>
    <div class="settings-alert settings-alert--error"><?= e($settingsError) ?></div>
    <?php endif ?>

    <?php if ($settingsSuccess !== null): ?>
    <div class="settings-alert settings-alert--success"><?= e($settingsSuccess) ?></div>
    <?php endif ?>

    <!-- Lista de personas -->
    <?php if (empty($people)): ?>
    <p class="empty-state" style="margin:1.5rem 0">No hay personas configuradas todavía.</p>
    <?php else: ?>
    <ul class="people-list">
      <?php foreach ($people as $i => $person):
        $color = $config->personPalette[$i % count($config->personPalette)]; ?>
      <li class="people-item">
        <div class="person-avatar" style="background:<?= e($color) ?>1a;border:2px solid <?= e($color) ?>">
          <span style="color:<?= e($color) ?>;font-weight:800;font-size:.95rem">
            <?= e(mb_strtoupper(mb_substr($person->name, 0, 1))) ?>
          </span>
        </div>
        <span class="person-name"><?= e($person->name) ?></span>
        <form method="post" class="del-person-form">
          <?= $auth->csrfField() ?>
          <input type="hidden" name="action" value="del_person">
          <input type="hidden" name="pid"    value="<?= e($person->id) ?>">
          <button type="submit" class="del-btn"
                  onclick="return confirm('¿Eliminar a <?= e($person->name) ?>? Esta acción es irreversible.')"
                  title="Eliminar persona">✕</button>
        </form>
      </li>
      <?php endforeach ?>
    </ul>
    <?php endif ?>

    <!-- Formulario agregar -->
    <form method="post" class="add-person-form" autocomplete="off">
      <?= $auth->csrfField() ?>
      <input type="hidden" name="action" value="add_person">
      <div class="add-person-row">
        <input type="text" name="name" placeholder="Nombre de la persona" required maxlength="50">
        <button type="submit" class="add-btn">Agregar</button>
      </div>
    </form>
  </div>

</main>

</body>
</html>
