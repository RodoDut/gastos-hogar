<?php
declare(strict_types=1);

// ⚠️ OBSOLETO: monolito reemplazado por la arquitectura Clean/SOLID en src/.
// Excluido del deploy (ver .github/workflows/deploy.yml, --exclude='gastos.php').
// Se mantiene temporalmente como referencia hasta confirmar el funcionamiento
// correcto de feature/user-roles en producción. Borrar una vez validado.

// ── Hardening de producción ───────────────────────────────────
ini_set('display_errors', '0');
error_reporting(0);

// ═══════════════════════════════════════════════════════════════
//  GASTOS DEL HOGAR — Configuración desde .env
// ═══════════════════════════════════════════════════════════════
require_once __DIR__ . '/vendor/autoload.php';

(Dotenv\Dotenv::createImmutable(__DIR__))->safeLoad();

define('APP_PASS',     $_ENV['APP_PASS']     ?? '');
define('DATA_FILE',    __DIR__ . '/' . ($_ENV['DATA_FILE'] ?? 'data/gastos.json'));
define('PA',           $_ENV['PERSON_A']     ?? 'Persona A');
define('PB',           $_ENV['PERSON_B']     ?? 'Persona B');
define('SESSION_TTL',  (int) ($_ENV['SESSION_TTL']  ?? 3600));
define('MAX_ATTEMPTS', (int) ($_ENV['MAX_ATTEMPTS'] ?? 3));
define('LOCKOUT_SEC',  (int) ($_ENV['LOCKOUT_SEC']  ?? 60));

$CATS = ['Alimentos', 'Servicios', 'Transporte', 'Salud',
         'Educación', 'Hogar', 'Entretenimiento', 'Ropa', 'Otro'];

$CAT_COLORS = [
    'Alimentos'   => '#16a34a',
    'Servicios'      => '#2563eb',
    'Transporte'     => '#d97706',
    'Salud'          => '#dc2626',
    'Educación'      => '#7c3aed',
    'Hogar'          => '#0891b2',
    'Entretenimiento'=> '#ea580c',
    'Ropa'           => '#db2777',
    'Otro'           => '#64748b',
];

// ── Session hardening ─────────────────────────────────────────
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

// ── HTTP Security Headers ─────────────────────────────────────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");

// ── CSRF Token ────────────────────────────────────────────────
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// ═══════════════════════════════════════════════════════════════
//  HELPERS
// ═══════════════════════════════════════════════════════════════

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float $n): string {
    return '$ ' . number_format($n, 2, ',', '.');
}

function go(string $m): never {
    header('Location: ' . $_SERVER['PHP_SELF'] . '?month=' . urlencode($m));
    exit;
}

/** Valida que el string sea YYYY-MM, devuelve el mes actual si no lo es. */
function validMonth(string $m): string {
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : date('Y-m');
}

/** Valida que el string sea Y-m-d, devuelve hoy si no lo es. */
function validDate(string $d): string {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : date('Y-m-d');
}

function load(): array {
    if (!file_exists(DATA_FILE)) return ['expenses' => []];
    $raw = file_get_contents(DATA_FILE);
    return ($raw !== false ? json_decode($raw, true) : null) ?? ['expenses' => []];
}

/** Escribe el JSON con bloqueo exclusivo para evitar race conditions. */
function save(array $d): void {
    $dir = dirname(DATA_FILE);
    if (!is_dir($dir)) mkdir($dir, 0700, true);

    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Deny from all\n");

    $fh = fopen(DATA_FILE, 'c+');
    if ($fh === false) return;

    if (flock($fh, LOCK_EX)) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    @chmod(DATA_FILE, 0600);
}

/** Soporta contraseña en texto plano Y hash bcrypt. */
function checkPassword(string $input): bool {
    return str_starts_with(APP_PASS, '$2y$')
        ? password_verify($input, APP_PASS)
        : hash_equals(APP_PASS, $input);
}

/** Emite el campo CSRF oculto para incluir en formularios. */
function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf'] ?? '') . '">';
}

/** Valida el token CSRF del POST. Termina con 403 si falla. */
function validateCsrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        die('Token de seguridad inválido. <a href="javascript:history.back()">Volver</a>');
    }
}

// ═══════════════════════════════════════════════════════════════
//  FLUJO DE AUTENTICACIÓN
// ═══════════════════════════════════════════════════════════════

// ── Session timeout ───────────────────────────────────────────
if (isset($_SESSION['ok'])) {
    if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > SESSION_TTL) {
        session_destroy();
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
    $_SESSION['last_active'] = time();
}

// ── Logout ────────────────────────────────────────────────────
if (isset($_POST['logout'])) {
    validateCsrf();
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

// ── Login ─────────────────────────────────────────────────────
if (!isset($_SESSION['ok'])) {
    $authErr    = false;
    $lockoutMsg = '';

    if (isset($_POST['pwd'])) {
        $attempts = (int)($_SESSION['login_attempts'] ?? 0);
        $lastTry  = (int)($_SESSION['last_attempt']   ?? 0);
        $elapsed  = time() - $lastTry;

        if ($attempts >= MAX_ATTEMPTS && $elapsed < LOCKOUT_SEC) {
            $wait       = LOCKOUT_SEC - $elapsed;
            $lockoutMsg = "Demasiados intentos. Esperá {$wait} segundo" . ($wait !== 1 ? 's' : '') . '.';
        } else {
            if ($elapsed >= LOCKOUT_SEC) {
                $_SESSION['login_attempts'] = 0; // resetear tras el lockout
            }

            if (checkPassword($_POST['pwd'])) {
                $_SESSION['login_attempts'] = 0;
                session_regenerate_id(true);          // FIX AUTH-01: session fixation
                $_SESSION['ok']          = 1;
                $_SESSION['last_active'] = time();
                $_SESSION['csrf']        = bin2hex(random_bytes(32)); // renovar token
            } else {
                $_SESSION['login_attempts'] = (int)($_SESSION['login_attempts'] ?? 0) + 1;
                $_SESSION['last_attempt']   = time();
                $authErr = true;
            }
        }
    }

    if (!isset($_SESSION['ok'])) {
        renderLogin($authErr, $lockoutMsg); exit;
    }
}

// ═══════════════════════════════════════════════════════════════
//  ACCIONES POST
// ═══════════════════════════════════════════════════════════════

$curMonth = validMonth($_POST['month'] ?? $_GET['month'] ?? date('Y-m'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['logout'])) {
    validateCsrf(); // FIX CSRF-01: validación en toda acción POST

    $action = $_POST['action'] ?? '';
    $db     = load();

    if ($action === 'add' && !empty(trim($_POST['desc'] ?? '')) && isset($_POST['amt'])) {
        $db['expenses'][] = [
            'id'   => bin2hex(random_bytes(8)),
            'who'  => in_array($_POST['who'] ?? '', [PA, PB]) ? $_POST['who'] : PA,
            'desc' => trim($_POST['desc']),              // FIX DATA-01: sin e() al guardar
            'amt'  => max(0, (float) str_replace(',', '.', $_POST['amt'])),
            'cat'  => in_array($_POST['cat'] ?? '', array_keys($GLOBALS['CAT_COLORS']))
                        ? $_POST['cat'] : 'Otro',        // FIX DATA-01: sin e() al guardar
            'date' => validDate($_POST['date'] ?? date('Y-m-d')), // FIX VALIDATION-01
        ];
        save($db);

    } elseif ($action === 'del' && !empty($_POST['eid'])) {
        $db['expenses'] = array_values(
            array_filter($db['expenses'], fn($x) => $x['id'] !== $_POST['eid'])
        );
        save($db);
    }

    go($curMonth);
}

// ═══════════════════════════════════════════════════════════════
//  DATOS DEL MES ACTUAL
// ═══════════════════════════════════════════════════════════════

$all  = load()['expenses'];
$exps = array_values(array_filter($all, fn($e) => str_starts_with($e['date'], $curMonth)));
usort($exps, fn($a, $b) => strcmp($b['date'], $a['date']));

$ea    = array_values(array_filter($exps, fn($e) => $e['who'] === PA));
$eb    = array_values(array_filter($exps, fn($e) => $e['who'] === PB));
$ta    = array_sum(array_column($ea, 'amt'));
$tb    = array_sum(array_column($eb, 'amt'));
$total = $ta + $tb;
$diff  = $ta - $tb;

$ML     = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
           'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mLabel = $ML[(int) substr($curMonth, 5, 2) - 1] . ' ' . substr($curMonth, 0, 4);
$prevM  = date('Y-m', strtotime($curMonth . '-01 -1 month'));
$nextM  = date('Y-m', strtotime($curMonth . '-01 +1 month'));
$isNow  = ($curMonth === date('Y-m'));

if ($total < 0.01) {
    $balIcon = '📋'; $balMsg = 'Sin gastos registrados este mes.';
} elseif (abs($diff) < 0.5) {
    $balIcon = '✅'; $balMsg = '¡Perfecto! Los dos están aportando por igual.';
} elseif ($diff > 0) {
    $owes   = $diff / 2;
    $balIcon = '⚖️';
    $balMsg  = '<strong>' . e(PA) . '</strong> pagó ' . money($diff) . ' más. '
             . '<strong>' . e(PB) . '</strong> debería compensar <strong>' . money($owes) . '</strong> para quedar 50/50.';
} else {
    $owes   = abs($diff) / 2;
    $balIcon = '⚖️';
    $balMsg  = '<strong>' . e(PB) . '</strong> pagó ' . money(abs($diff)) . ' más. '
             . '<strong>' . e(PA) . '</strong> debería compensar <strong>' . money($owes) . '</strong> para quedar 50/50.';
}

$pctA = $total > 0 ? round($ta / $total * 100, 1) : 50;
$pctB = $total > 0 ? round($tb / $total * 100, 1) : 50;

// ═══════════════════════════════════════════════════════════════
//  PÁGINA DE LOGIN
// ═══════════════════════════════════════════════════════════════

function renderLogin(bool $err, string $lockoutMsg = ''): void {
    $csrf = e($_SESSION['csrf'] ?? '');
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gastos del Hogar</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:linear-gradient(135deg,#312e81 0%,#4f46e5 50%,#7c3aed 100%);
     display:flex;align-items:center;justify-content:center;
     font-family:'Segoe UI',system-ui,-apple-system,sans-serif}
.box{background:#fff;border-radius:28px;padding:52px 40px;max-width:380px;width:calc(100% - 2rem);
     text-align:center;box-shadow:0 32px 80px rgba(0,0,0,.3)}
.ico{font-size:4rem;margin-bottom:1.25rem;display:block}
h1{font-size:1.75rem;font-weight:800;color:#1e293b;margin-bottom:.5rem;letter-spacing:-.02em}
.sub{color:#64748b;margin-bottom:2rem;font-size:.95rem;line-height:1.5}
input[type=password]{width:100%;padding:15px;border:2px solid #e2e8f0;border-radius:14px;
   font-size:1.1rem;outline:none;text-align:center;letter-spacing:6px;transition:.2s;color:#1e293b}
input:focus{border-color:#4f46e5;box-shadow:0 0 0 4px rgba(79,70,229,.1)}
button{margin-top:1rem;width:100%;padding:15px;
       background:linear-gradient(135deg,#4f46e5,#7c3aed);
       color:#fff;border:none;border-radius:14px;font-size:1rem;font-weight:700;
       cursor:pointer;transition:.2s;letter-spacing:.02em}
button:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(79,70,229,.4)}
.msg-err{margin-top:1rem;color:#dc2626;font-size:.875rem;font-weight:600;
         background:#fef2f2;border-radius:8px;padding:.6rem 1rem}
.msg-lock{margin-top:1rem;color:#92400e;font-size:.875rem;font-weight:600;
          background:#fffbeb;border-radius:8px;padding:.6rem 1rem}
</style>
</head>
<body>
<div class="box">
  <span class="ico">🏠</span>
  <h1>Gastos del Hogar</h1>
  <p class="sub">Ingresá la contraseña para acceder</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="password" name="pwd" placeholder="••••••••" autofocus autocomplete="current-password">
    <button type="submit">Entrar</button>
  </form>
  <?php if ($lockoutMsg): ?>
  <p class="msg-lock">🔒 <?= e($lockoutMsg) ?></p>
  <?php elseif ($err): ?>
  <p class="msg-err">Contraseña incorrecta. Intentá de nuevo.</p>
  <?php endif ?>
</div>
</body>
</html>
    <?php
}

// ═══════════════════════════════════════════════════════════════
//  APP PRINCIPAL
// ═══════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gastos del Hogar</title>
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/* ── Base ── */
body {
  background: #f1f5f9;
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  color: #1e293b;
  min-height: 100vh;
  -webkit-font-smoothing: antialiased;
}

/* ── Header ── */
header {
  background: linear-gradient(135deg, #312e81 0%, #4f46e5 60%, #7c3aed 100%);
  color: #fff;
  padding: 1rem 1.5rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 100;
  box-shadow: 0 4px 20px rgba(79,70,229,.35);
}
header h1 {
  font-size: 1.15rem;
  font-weight: 800;
  letter-spacing: -.02em;
  display: flex;
  align-items: center;
  gap: .5rem;
}
.logout-btn {
  background: rgba(255,255,255,.15);
  border: 1px solid rgba(255,255,255,.25);
  color: #fff;
  padding: .45rem 1rem;
  border-radius: 8px;
  cursor: pointer;
  font-size: .82rem;
  font-weight: 600;
  transition: .2s;
  backdrop-filter: blur(4px);
}
.logout-btn:hover { background: rgba(255,255,255,.25); }

/* ── Layout ── */
main { max-width: 980px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }

/* ── Month nav ── */
.month-nav {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1.25rem;
  margin-bottom: 1.5rem;
}
.nav-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 2px 10px rgba(0,0,0,.1);
  color: #4f46e5;
  text-decoration: none;
  font-size: 1.3rem;
  font-weight: 700;
  line-height: 1;
  transition: .2s;
}
.nav-btn:hover { background: #4f46e5; color: #fff; transform: scale(1.08); }
.nav-btn.ghost { visibility: hidden; }
.month-nav h2 {
  font-size: 1.3rem;
  font-weight: 800;
  color: #1e293b;
  letter-spacing: -.02em;
  min-width: 210px;
  text-align: center;
}

/* ── Summary grid ── */
.summary {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: .875rem;
  margin-bottom: 1rem;
}
.card {
  background: #fff;
  border-radius: 18px;
  padding: 1.25rem 1.25rem 1rem;
  box-shadow: 0 2px 14px rgba(0,0,0,.06);
  position: relative;
  overflow: hidden;
}
.card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
}
.card.ca::before  { background: #4f46e5; }
.card.cb::before  { background: #e11d48; }
.card.ct::before  { background: linear-gradient(90deg, #4f46e5, #e11d48); }
.card.ci::before  { background: #f59e0b; }
.card-label {
  font-size: .7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: #94a3b8;
  margin-bottom: .6rem;
}
.card-value {
  font-size: 1.45rem;
  font-weight: 800;
  letter-spacing: -.02em;
  color: #1e293b;
  line-height: 1;
}
.ca .card-value { color: #4f46e5; }
.cb .card-value { color: #e11d48; }
.card-count {
  font-size: .75rem;
  color: #94a3b8;
  margin-top: .35rem;
  font-weight: 500;
}

/* ── Split bar ── */
.split-bar-wrap {
  background: #fff;
  border-radius: 16px;
  padding: 1rem 1.25rem;
  box-shadow: 0 2px 14px rgba(0,0,0,.06);
  margin-bottom: 1rem;
}
.split-labels {
  display: flex;
  justify-content: space-between;
  font-size: .8rem;
  font-weight: 700;
  margin-bottom: .6rem;
}
.lbl-a { color: #4f46e5; }
.lbl-b { color: #e11d48; }
.split-track {
  height: 14px;
  border-radius: 99px;
  background: #f1f5f9;
  overflow: hidden;
  display: flex;
}
.split-a {
  background: linear-gradient(90deg, #4f46e5, #818cf8);
  transition: width .5s cubic-bezier(.4,0,.2,1);
  border-radius: 99px 0 0 99px;
}
.split-b {
  background: linear-gradient(90deg, #f43f5e, #e11d48);
  flex: 1;
  border-radius: 0 99px 99px 0;
}
.split-pcts {
  display: flex;
  justify-content: space-between;
  margin-top: .4rem;
  font-size: .75rem;
  color: #94a3b8;
  font-weight: 600;
}

/* ── Balance banner ── */
.balance-banner {
  background: #fff;
  border-radius: 16px;
  padding: .9rem 1.25rem;
  margin-bottom: 1.5rem;
  box-shadow: 0 2px 14px rgba(0,0,0,.06);
  display: flex;
  align-items: center;
  gap: .75rem;
  font-size: .9rem;
  color: #475569;
  line-height: 1.5;
}
.balance-banner .bi { font-size: 1.5rem; flex-shrink: 0; }
.balance-banner strong { color: #1e293b; }

/* ── Add form ── */
.add-form {
  background: #fff;
  border-radius: 18px;
  padding: 1.5rem;
  box-shadow: 0 2px 14px rgba(0,0,0,.06);
  margin-bottom: 1.5rem;
}
.add-form-title {
  font-size: .95rem;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 1.1rem;
  display: flex;
  align-items: center;
  gap: .5rem;
}
.fg {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .75rem;
}
.fg .span2 { grid-column: span 2; }
label {
  display: block;
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: #64748b;
  margin-bottom: .35rem;
}
input[type=text],
input[type=number],
input[type=date],
select {
  width: 100%;
  padding: 10px 12px;
  border: 2px solid #e2e8f0;
  border-radius: 11px;
  font-size: .9rem;
  color: #1e293b;
  outline: none;
  transition: .2s;
  background: #fff;
  font-family: inherit;
}
input:focus, select:focus {
  border-color: #4f46e5;
  box-shadow: 0 0 0 3px rgba(79,70,229,.12);
}
.who-btns { display: flex; gap: .5rem; }
.who-btn {
  flex: 1;
  padding: 10px 8px;
  border: 2px solid #e2e8f0;
  border-radius: 11px;
  background: #fff;
  font-size: .875rem;
  font-weight: 700;
  cursor: pointer;
  transition: .2s;
  color: #64748b;
  font-family: inherit;
}
.who-btn.active-a { border-color: #4f46e5; background: #eef2ff; color: #4f46e5; }
.who-btn.active-b { border-color: #e11d48; background: #fff1f2; color: #e11d48; }
.who-btn:not(.active-a):not(.active-b):hover { border-color: #94a3b8; background: #f8fafc; }

.add-btn {
  width: 100%;
  padding: 12px;
  background: linear-gradient(135deg, #4f46e5, #7c3aed);
  color: #fff;
  border: none;
  border-radius: 11px;
  font-size: .95rem;
  font-weight: 700;
  cursor: pointer;
  transition: .2s;
  font-family: inherit;
}
.add-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(79,70,229,.35); }
.add-btn:active { transform: translateY(0); }

/* ── Expense columns ── */
.cols {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}
.col-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: .85rem 1.1rem;
  border-radius: 14px 14px 0 0;
  color: #fff;
  font-weight: 800;
  font-size: .9rem;
}
.col-a .col-head { background: linear-gradient(135deg, #4338ca, #4f46e5, #6366f1); }
.col-b .col-head { background: linear-gradient(135deg, #be123c, #e11d48, #f43f5e); }
.col-head-total { font-size: .8rem; font-weight: 700; opacity: .85; }
.exp-list {
  background: #fff;
  border-radius: 0 0 14px 14px;
  box-shadow: 0 4px 16px rgba(0,0,0,.07);
  min-height: 60px;
}
.exp-row {
  display: flex;
  align-items: center;
  gap: .65rem;
  padding: .7rem 1rem;
  border-bottom: 1px solid #f1f5f9;
  transition: background .15s;
}
.exp-row:last-child { border-bottom: none; }
.exp-row:hover { background: #fafafa; }
.exp-info { flex: 1; min-width: 0; }
.exp-desc {
  display: block;
  font-size: .875rem;
  font-weight: 600;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.exp-meta { display: flex; align-items: center; gap: .4rem; margin-top: .25rem; flex-wrap: wrap; }
.badge { font-size: .67rem; font-weight: 700; padding: 2px 7px; border-radius: 99px; white-space: nowrap; }
.exp-date { font-size: .72rem; color: #94a3b8; font-weight: 500; }
.exp-amt { font-size: .9rem; font-weight: 800; color: #1e293b; white-space: nowrap; flex-shrink: 0; }
.del-btn {
  background: none;
  border: none;
  cursor: pointer;
  color: #cbd5e1;
  font-size: 1.2rem;
  line-height: 1;
  padding: 3px 5px;
  border-radius: 6px;
  transition: .15s;
  flex-shrink: 0;
}
.del-btn:hover { color: #ef4444; background: #fef2f2; }
.empty-state { padding: 2rem 1rem; text-align: center; color: #94a3b8; font-size: .875rem; font-style: italic; }

/* ── Responsive ── */
@media (max-width: 700px) {
  .summary { grid-template-columns: 1fr 1fr; }
  .cols    { grid-template-columns: 1fr; }
  .fg      { grid-template-columns: 1fr; }
  .fg .span2 { grid-column: span 1; }
  header h1  { font-size: 1rem; }
}
@media (max-width: 400px) {
  .card-value { font-size: 1.1rem; }
}
</style>
</head>
<body>

<!-- Header -->
<header>
  <h1>🏠 Gastos del Hogar</h1>
  <form method="post">
    <?= csrfField() ?>
    <button type="submit" name="logout" value="1" class="logout-btn">Salir</button>
  </form>
</header>

<main>

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
    <div class="card ca">
      <div class="card-label"><?= e(PA) ?></div>
      <div class="card-value"><?= money($ta) ?></div>
      <div class="card-count"><?= count($ea) ?> gasto<?= count($ea) !== 1 ? 's' : '' ?></div>
    </div>
    <div class="card cb">
      <div class="card-label"><?= e(PB) ?></div>
      <div class="card-value"><?= money($tb) ?></div>
      <div class="card-count"><?= count($eb) ?> gasto<?= count($eb) !== 1 ? 's' : '' ?></div>
    </div>
    <div class="card ct">
      <div class="card-label">Total del mes</div>
      <div class="card-value"><?= money($total) ?></div>
      <div class="card-count"><?= count($exps) ?> gasto<?= count($exps) !== 1 ? 's' : '' ?></div>
    </div>
    <div class="card ci">
      <div class="card-label">Aporte ideal c/u</div>
      <div class="card-value"><?= money($total / 2) ?></div>
      <div class="card-count">para quedar 50/50</div>
    </div>
  </div>

  <!-- Barra de distribución -->
  <?php if ($total > 0): ?>
  <div class="split-bar-wrap">
    <div class="split-labels">
      <span class="lbl-a"><?= e(PA) ?></span>
      <span style="color:#94a3b8;font-size:.75rem;font-weight:600">DISTRIBUCIÓN DEL MES</span>
      <span class="lbl-b"><?= e(PB) ?></span>
    </div>
    <div class="split-track">
      <div class="split-a" style="width:<?= $pctA ?>%"></div>
      <div class="split-b"></div>
    </div>
    <div class="split-pcts">
      <span><?= $pctA ?>%</span>
      <span><?= $pctB ?>%</span>
    </div>
  </div>
  <?php endif ?>

  <!-- Balance -->
  <div class="balance-banner">
    <span class="bi"><?= $balIcon ?></span>
    <span><?= $balMsg ?></span>
  </div>

  <!-- Formulario de carga -->
  <div class="add-form">
    <div class="add-form-title">➕ Registrar gasto</div>
    <form method="post" id="addForm" autocomplete="off">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="month"  value="<?= e($curMonth) ?>">
      <input type="hidden" name="who"    id="whoInput" value="<?= e(PA) ?>">

      <div class="fg">

        <div class="span2">
          <label>¿Quién pagó?</label>
          <div class="who-btns">
            <button type="button" class="who-btn active-a"
                    onclick="setWho(<?= json_encode(PA) ?>, this, 'active-a')">
              <?= e(PA) ?>
            </button>
            <button type="button" class="who-btn"
                    onclick="setWho(<?= json_encode(PB) ?>, this, 'active-b')">
              <?= e(PB) ?>
            </button>
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
            <?php foreach ($CATS as $c): ?>
            <option value="<?= e($c) ?>"><?= e($c) ?></option>
            <?php endforeach ?>
          </select>
        </div>

        <div class="span2">
          <button type="submit" class="add-btn">Agregar gasto</button>
        </div>

      </div>
    </form>
  </div>

  <!-- Columnas de gastos -->
  <div class="cols">

    <!-- Columna A -->
    <div class="col-a">
      <div class="col-head">
        <span><?= e(PA) ?></span>
        <span class="col-head-total"><?= money($ta) ?></span>
      </div>
      <div class="exp-list">
        <?php if (empty($ea)): ?>
          <p class="empty-state">Sin gastos registrados</p>
        <?php else: ?>
          <?php foreach ($ea as $exp):
            $c = $GLOBALS['CAT_COLORS'][$exp['cat']] ?? '#64748b'; ?>
          <div class="exp-row">
            <div class="exp-info">
              <span class="exp-desc"><?= e($exp['desc']) ?></span>
              <div class="exp-meta">
                <span class="badge" style="background:<?= e($c) ?>22;color:<?= e($c) ?>;border:1px solid <?= e($c) ?>44">
                  <?= e($exp['cat']) ?>
                </span>
                <span class="exp-date"><?= date('d/m', strtotime($exp['date'])) ?></span>
              </div>
            </div>
            <span class="exp-amt"><?= money($exp['amt']) ?></span>
            <form method="post">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="del">
              <input type="hidden" name="eid"    value="<?= e($exp['id']) ?>">
              <input type="hidden" name="month"  value="<?= e($curMonth) ?>">
              <button type="submit" class="del-btn" title="Eliminar"
                      data-desc="<?= e($exp['desc']) ?>"
                      onclick="return confirm('¿Eliminar «' + this.dataset.desc + '»?')">×</button>
            </form>
          </div>
          <?php endforeach ?>
        <?php endif ?>
      </div>
    </div>

    <!-- Columna B -->
    <div class="col-b">
      <div class="col-head">
        <span><?= e(PB) ?></span>
        <span class="col-head-total"><?= money($tb) ?></span>
      </div>
      <div class="exp-list">
        <?php if (empty($eb)): ?>
          <p class="empty-state">Sin gastos registrados</p>
        <?php else: ?>
          <?php foreach ($eb as $exp):
            $c = $GLOBALS['CAT_COLORS'][$exp['cat']] ?? '#64748b'; ?>
          <div class="exp-row">
            <div class="exp-info">
              <span class="exp-desc"><?= e($exp['desc']) ?></span>
              <div class="exp-meta">
                <span class="badge" style="background:<?= e($c) ?>22;color:<?= e($c) ?>;border:1px solid <?= e($c) ?>44">
                  <?= e($exp['cat']) ?>
                </span>
                <span class="exp-date"><?= date('d/m', strtotime($exp['date'])) ?></span>
              </div>
            </div>
            <span class="exp-amt"><?= money($exp['amt']) ?></span>
            <form method="post">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="del">
              <input type="hidden" name="eid"    value="<?= e($exp['id']) ?>">
              <input type="hidden" name="month"  value="<?= e($curMonth) ?>">
              <button type="submit" class="del-btn" title="Eliminar"
                      data-desc="<?= e($exp['desc']) ?>"
                      onclick="return confirm('¿Eliminar «' + this.dataset.desc + '»?')">×</button>
            </form>
          </div>
          <?php endforeach ?>
        <?php endif ?>
      </div>
    </div>

  </div><!-- .cols -->

</main>

<script>
function setWho(name, btn, activeClass) {
  document.getElementById('whoInput').value = name;
  document.querySelectorAll('.who-btn').forEach(b => {
    b.classList.remove('active-a', 'active-b');
  });
  btn.classList.add(activeClass);
}
</script>

</body>
</html>
