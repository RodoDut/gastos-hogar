<?php
/** @var GastosHogar\Config $config */
/** @var GastosHogar\Auth\Auth $auth */
/** @var GastosHogar\User\User $actor */
/** @var GastosHogar\Person\Person[] $people */
/** @var array<string,GastosHogar\Person\Person> $peopleById */
/** @var array<string,string> $peopleColors */
/** @var GastosHogar\Expense\Expense[] $exps */
/** @var array<string,GastosHogar\Expense\Expense[]> $expsByPerson */
/** @var array<string,float> $totalsByPerson */
/** @var float $total */
/** @var float $ideal */
/** @var array<string,float> $balances */
/** @var array<string,float> $pctsByPerson */
/** @var string $mLabel */
/** @var string $prevM */
/** @var string $nextM */
/** @var bool $isNow */
/** @var string $curMonth */
/** @var string|null $appError */
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gastos del Hogar</title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body>

<header>
  <h1>🏠 Gastos del Hogar</h1>
  <div style="display:flex;gap:.75rem;align-items:center">
    <a href="?page=settings" class="logout-btn" style="text-decoration:none" title="Configuración">⚙️ Config</a>
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

  <?php if ($appError !== null): ?>
  <div class="settings-alert settings-alert--error" style="margin-bottom:1rem"><?= e($appError) ?></div>
  <?php endif ?>

  <!-- Sin personas configuradas -->
  <?php if (empty($people)): ?>
  <div class="balance-banner" style="margin-top:2rem;justify-content:center;text-align:center;flex-direction:column;gap:.5rem">
    <span style="font-size:2rem">⚙️</span>
    <span>No hay personas configuradas.<br>
      <a href="?page=settings" style="color:#4f46e5;font-weight:700">Ir a Configuración</a> para agregar personas.</span>
  </div>
  <?php else: ?>

  <!-- Navegación de mes -->
  <div class="month-nav">
    <a href="?month=<?= e($prevM) ?>" class="nav-btn" title="Mes anterior">‹</a>
    <h2><?= e($mLabel) ?></h2>
    <?php if ($isNow): ?>
      <span class="nav-btn ghost" aria-hidden="true">›</span>
    <?php else: ?>
      <a href="?month=<?= e($nextM) ?>" class="nav-btn" title="Mes siguiente">›</a>
    <?php endif ?>
  </div>

  <!-- Cards resumen -->
  <div class="summary">
    <?php foreach ($people as $person):
      $color = $peopleColors[$person->id]; ?>
    <div class="card" style="--person-color:<?= e($color) ?>">
      <div class="card-accent"></div>
      <div class="card-label"><?= e($person->name) ?></div>
      <div class="card-value" style="color:<?= e($color) ?>"><?= money($totalsByPerson[$person->id] ?? 0) ?></div>
      <div class="card-count">
        <?= count($expsByPerson[$person->id] ?? []) ?> gasto<?= count($expsByPerson[$person->id] ?? []) !== 1 ? 's' : '' ?>
      </div>
    </div>
    <?php endforeach ?>
    <div class="card ct">
      <div class="card-accent" style="background:linear-gradient(90deg,<?= implode(',', array_values($peopleColors)) ?>)"></div>
      <div class="card-label">Total del mes</div>
      <div class="card-value"><?= money($total) ?></div>
      <div class="card-count"><?= count($exps) ?> gasto<?= count($exps) !== 1 ? 's' : '' ?></div>
    </div>
    <div class="card ci">
      <div class="card-accent" style="background:#f59e0b"></div>
      <div class="card-label">Aporte ideal c/u</div>
      <div class="card-value"><?= money($ideal) ?></div>
      <div class="card-count">para quedar <?= count($people) > 0 ? round(100 / count($people)) : 0 ?>% c/u</div>
    </div>
  </div>

  <!-- Barra de distribución -->
  <?php if ($total > 0): ?>
  <div class="split-bar-wrap">
    <div class="split-labels">
      <?php foreach ($people as $person): ?>
      <span style="color:<?= e($peopleColors[$person->id]) ?>;font-size:.8rem;font-weight:700">
        <?= e($person->name) ?>
      </span>
      <?php endforeach ?>
    </div>
    <div class="split-track">
      <?php foreach ($people as $i => $person):
        $color = $peopleColors[$person->id];
        $pct   = $pctsByPerson[$person->id] ?? 0;
        $isLast = $i === count($people) - 1; ?>
      <div style="<?= $isLast ? 'flex:1' : "width:{$pct}%" ?>;background:<?= e($color) ?>;height:100%"></div>
      <?php endforeach ?>
    </div>
    <div class="split-pcts">
      <?php foreach ($people as $person): ?>
      <span><?= $pctsByPerson[$person->id] ?? 0 ?>%</span>
      <?php endforeach ?>
    </div>
  </div>
  <?php endif ?>

  <!-- Balance -->
  <div class="balance-banner">
    <span class="bi">⚖️</span>
    <div style="flex:1">
      <?php if ($total < 0.01): ?>
        <span style="color:#94a3b8">Sin gastos registrados este mes.</span>
      <?php else: ?>
        <?php foreach ($people as $person):
          $surplus = $balances[$person->id] ?? 0;
          $color   = $peopleColors[$person->id]; ?>
        <div style="margin-bottom:.25rem">
          <strong style="color:<?= e($color) ?>"><?= e($person->name) ?></strong>
          <?php if (abs($surplus) < 0.5): ?>
            — aportó exacto ✅
          <?php elseif ($surplus > 0): ?>
            — pagó <strong><?= money($surplus) ?></strong> de más
          <?php else: ?>
            — debe <strong><?= money(abs($surplus)) ?></strong> para quedar par
          <?php endif ?>
        </div>
        <?php endforeach ?>
      <?php endif ?>
    </div>
  </div>

  <!-- Formulario de carga -->
  <div class="add-form">
    <div class="add-form-title">➕ Registrar gasto</div>
    <form method="post" id="addForm" autocomplete="off" enctype="multipart/form-data">
      <?= $auth->csrfField() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="month"  value="<?= e($curMonth) ?>">
      <input type="hidden" name="who"    id="whoInput" value="<?= e($people[0]->id ?? '') ?>">

      <div class="fg">

        <div class="span2">
          <label>¿Quién pagó?</label>
          <div class="who-btns">
            <?php foreach ($people as $i => $person):
              $color = $peopleColors[$person->id]; ?>
            <button type="button"
                    class="who-btn<?= $i === 0 ? ' who-btn--active' : '' ?>"
                    <?= $i === 0 ? 'style="border-color:' . e($color) . ';color:' . e($color) . ';background:#f8f9ff"' : '' ?>
                    data-person-id="<?= e($person->id) ?>"
                    data-person-color="<?= e($color) ?>"
                    onclick="selectWho(this)">
              <?= e($person->name) ?>
            </button>
            <?php endforeach ?>
          </div>
        </div>

        <div class="span2">
          <label>Descripción</label>
          <input type="text" name="desc" placeholder="Ej: Supermercado, Luz, Netflix..." required>
        </div>

        <div>
          <label>Monto</label>
          <input type="number" name="amt" step="0.01" min="0.01"
                 placeholder="0,00" required inputmode="decimal">
        </div>

        <div>
          <label>Fecha</label>
          <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="span2">
          <label>Categoría</label>
          <select name="cat">
            <?php foreach ($config->categories as $cat): ?>
            <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
            <?php endforeach ?>
          </select>
        </div>

        <div class="span2">
          <label>📎 Comprobante (opcional)</label>
          <input type="file" name="ticket" accept="image/jpeg,image/png,application/pdf" capture="environment">
        </div>

        <div class="span2">
          <button type="submit" class="add-btn">Agregar gasto</button>
        </div>

      </div>
    </form>
  </div>

  <!-- Columnas de gastos por persona -->
  <div class="cols">
    <?php foreach ($people as $person):
      $color    = $peopleColors[$person->id];
      $personExps = $expsByPerson[$person->id] ?? []; ?>
    <div class="col-person">
      <div class="col-head" style="background:<?= e($color) ?>">
        <span><?= e($person->name) ?></span>
        <span class="col-head-total"><?= money($totalsByPerson[$person->id] ?? 0) ?></span>
      </div>
      <div class="exp-list">
        <?php if (empty($personExps)): ?>
          <p class="empty-state">Sin gastos registrados</p>
        <?php else: ?>
          <?php foreach ($personExps as $exp):
            $catColor = $config->categoryColors[$exp->cat] ?? '#64748b'; ?>
          <div class="exp-row">
            <div class="exp-info">
              <span class="exp-desc"><?= e($exp->desc) ?></span>
              <div class="exp-meta">
                <span class="badge" style="background:<?= e($catColor) ?>22;color:<?= e($catColor) ?>;border:1px solid <?= e($catColor) ?>44">
                  <?= e($exp->cat) ?>
                </span>
                <span class="exp-date"><?= date('d/m', strtotime($exp->date)) ?></span>
                <?php if ($exp->ownerId !== $actor->id): ?>
                <span class="badge" style="background:#eef2ff;color:#4f46e5;border:1px solid #c7d2fe">
                  Cargado por <?= e($peopleById[$exp->ownerId]->name ?? '—') ?>
                </span>
                <?php endif ?>
                <?php if ($exp->ticketFilename !== null): ?>
                <a href="?page=ticket&eid=<?= e($exp->id) ?>" target="_blank" rel="noopener" class="ticket-link" title="Ver comprobante">📎 Comprobante</a>
                <?php endif ?>
              </div>
            </div>
            <span class="exp-amt"><?= money($exp->amt) ?></span>
            <?php if ($exp->ownerId === $actor->id): ?>
            <form method="post" enctype="multipart/form-data" class="ticket-attach-form">
              <?= $auth->csrfField() ?>
              <input type="hidden" name="action" value="attach_ticket">
              <input type="hidden" name="eid"    value="<?= e($exp->id) ?>">
              <input type="hidden" name="month"  value="<?= e($curMonth) ?>">
              <label class="ticket-attach-btn" title="<?= $exp->ticketFilename !== null ? 'Reemplazar comprobante' : 'Adjuntar comprobante' ?>">
                📎
                <input type="file" name="ticket" accept="image/jpeg,image/png,application/pdf" capture="environment" onchange="submitTicketForm(this)" hidden>
              </label>
            </form>
            <?php if ($exp->ticketFilename !== null): ?>
            <form method="post">
              <?= $auth->csrfField() ?>
              <input type="hidden" name="action" value="remove_ticket">
              <input type="hidden" name="eid"    value="<?= e($exp->id) ?>">
              <input type="hidden" name="month"  value="<?= e($curMonth) ?>">
              <button type="submit" class="del-btn" title="Quitar comprobante"
                      data-desc="<?= e($exp->desc) ?>"
                      onclick="return confirm('¿Quitar el comprobante de «' + this.dataset.desc + '»?')">📎✕</button>
            </form>
            <?php endif ?>
            <form method="post">
              <?= $auth->csrfField() ?>
              <input type="hidden" name="action" value="del">
              <input type="hidden" name="eid"    value="<?= e($exp->id) ?>">
              <input type="hidden" name="month"  value="<?= e($curMonth) ?>">
              <button type="submit" class="del-btn" title="Eliminar"
                      data-desc="<?= e($exp->desc) ?>"
                      onclick="return confirm('¿Eliminar «' + this.dataset.desc + '»?')">×</button>
            </form>
            <?php endif ?>
          </div>
          <?php endforeach ?>
        <?php endif ?>
      </div>
    </div>
    <?php endforeach ?>
  </div>

  <?php endif ?><!-- /empty people check -->

</main>

<script src="<?= asset('assets/js/app.js') ?>"></script>

</body>
</html>
