<?php
/** @var GastosHogar\Auth\Auth $auth */
/** @var GastosHogar\User\User $actor */
/** @var GastosHogar\User\User[] $users */
/** @var string|null $adminError */
/** @var string|null $adminSuccess */
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Usuarios — Gastos del Hogar</title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body>

<header>
  <h1>🏠 Gastos del Hogar</h1>
  <div style="display:flex;gap:.75rem;align-items:center">
    <a href="/" class="logout-btn" style="text-decoration:none">← Volver</a>
    <form method="post">
      <?= $auth->csrfField() ?>
      <button type="submit" name="logout" value="1" class="logout-btn">Salir</button>
    </form>
  </div>
</header>

<main>

  <div class="settings-card">
    <h2 class="settings-title">👤 Usuarios</h2>
    <p class="settings-subtitle">
      Gestioná quién puede acceder a la app y con qué rol.<br>
      Desactivar un usuario le impide iniciar sesión, pero conserva sus gastos históricos.
    </p>

    <?php if ($adminError !== null): ?>
    <div class="settings-alert settings-alert--error"><?= e($adminError) ?></div>
    <?php endif ?>

    <?php if ($adminSuccess !== null): ?>
    <div class="settings-alert settings-alert--success"><?= e($adminSuccess) ?></div>
    <?php endif ?>

    <?php if (empty($users)): ?>
    <p class="empty-state" style="margin:1.5rem 0">No hay usuarios configurados todavía.</p>
    <?php else: ?>
    <ul class="people-list">
      <?php foreach ($users as $user): ?>
      <li class="people-item">
        <div class="person-avatar" style="background:#4f46e51a;border:2px solid #4f46e5">
          <span style="color:#4f46e5;font-weight:800;font-size:.95rem">
            <?= e(mb_strtoupper(mb_substr($user->name, 0, 1))) ?>
          </span>
        </div>
        <span class="person-name">
          <?= e($user->name) ?>
          <small style="color:#94a3b8;font-weight:500"> (<?= e($user->username) ?>)</small>
        </span>
        <span class="badge" style="background:<?= $user->isAdmin() ? '#eef2ff' : '#f1f5f9' ?>;color:<?= $user->isAdmin() ? '#4f46e5' : '#64748b' ?>;border:1px solid <?= $user->isAdmin() ? '#c7d2fe' : '#e2e8f0' ?>">
          <?= $user->isAdmin() ? 'Admin' : 'Miembro' ?>
        </span>
        <span class="badge" style="background:<?= $user->active ? '#f0fdf4' : '#fef2f2' ?>;color:<?= $user->active ? '#16a34a' : '#dc2626' ?>;border:1px solid <?= $user->active ? '#bbf7d0' : '#fecaca' ?>">
          <?= $user->active ? 'Activo' : 'Inactivo' ?>
        </span>
        <?php if ($user->id !== $actor->id): ?>
        <form method="post" class="del-person-form">
          <?= $auth->csrfField() ?>
          <input type="hidden" name="action" value="<?= $user->active ? 'deactivate_user' : 'reactivate_user' ?>">
          <input type="hidden" name="uid"    value="<?= e($user->id) ?>">
          <button type="submit" class="del-btn" title="<?= $user->active ? 'Desactivar' : 'Reactivar' ?>"
                  onclick="return confirm('¿<?= $user->active ? 'Desactivar' : 'Reactivar' ?> a <?= e($user->name) ?>?')">
            <?= $user->active ? '⏸' : '▶' ?>
          </button>
        </form>
        <?php endif ?>
      </li>
      <?php endforeach ?>
    </ul>
    <?php endif ?>

    <form method="post" class="add-person-form" autocomplete="off">
      <?= $auth->csrfField() ?>
      <input type="hidden" name="action" value="create_user">
      <div class="fg" style="margin-bottom:.75rem">
        <div>
          <label>Nombre</label>
          <input type="text" name="name" placeholder="Nombre" required maxlength="50">
        </div>
        <div>
          <label>Usuario</label>
          <input type="text" name="username" placeholder="usuario" required maxlength="30">
        </div>
        <div>
          <label>Contraseña</label>
          <input type="password" name="password" placeholder="••••••••" required minlength="8">
        </div>
        <div>
          <label>Rol</label>
          <select name="role">
            <option value="member">Miembro</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>
      <button type="submit" class="add-btn">Crear usuario</button>
    </form>
  </div>

</main>

</body>
</html>
