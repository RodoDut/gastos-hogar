<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GastosHogar\Config;
use GastosHogar\Admin\UserController;
use GastosHogar\Auth\Auth;
use GastosHogar\Auth\AuthorizationService;
use GastosHogar\Auth\JsonRememberMeRepository;
use GastosHogar\Auth\RememberMeService;
use GastosHogar\Auth\UnauthorizedActionException;
use GastosHogar\Expense\Expense;
use GastosHogar\Expense\JsonExpenseRepository;
use GastosHogar\Person\Person;
use GastosHogar\Person\JsonPersonRepository;
use GastosHogar\User\JsonUserRepository;
use GastosHogar\User\UserService;
use GastosHogar\View\View;

// ── Bootstrap ────────────────────────────────────────────────────
$dotenv = Dotenv::createImmutable($root);
$dotenv->load();
$dotenv->required(['APP_PASS', 'DATA_FILE', 'SESSION_TTL', 'MAX_ATTEMPTS', 'LOCKOUT_SEC']);

$config         = new Config();
$expRepo        = new JsonExpenseRepository($root . '/' . $config->dataFile);
$personRepo     = new JsonPersonRepository($root . '/data/people.json');
$userRepo       = new JsonUserRepository($root . '/data/people.json');
$authz          = new AuthorizationService();
$userService    = new UserService($userRepo);
$userController = new UserController($userRepo, $userService, $authz);
$auth           = new Auth($config, $userRepo);
$rememberRepo   = new JsonRememberMeRepository($root . '/data/tokens.json');
$rememberMe     = new RememberMeService($config, $rememberRepo, $userRepo);
$view           = new View($root . '/templates');

// ── Session hardening ─────────────────────────────────────────────
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

// ── HTTP Security Headers ─────────────────────────────────────────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ── CSRF Token ────────────────────────────────────────────────────
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// ── Session timeout ───────────────────────────────────────────────
if ($auth->isLoggedIn() && !$auth->checkSessionTimeout()) {
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Logout ────────────────────────────────────────────────────────
if (isset($_POST['logout'])) {
    $auth->validateCsrf();
    $rememberMe->forget();
    $auth->logout();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Remember-me: si no hay sesión activa, intentar restaurarla por cookie ──
if (!$auth->isLoggedIn()) {
    $rememberedUser = $rememberMe->attemptLogin();
    if ($rememberedUser !== null) {
        $auth->loginAs($rememberedUser);
    }
}

// ── Login ─────────────────────────────────────────────────────────
if (!$auth->isLoggedIn()) {
    $lockoutMsg = $auth->getLockoutMessage();
    $authErr    = false;

    if (isset($_POST['pwd']) && $lockoutMsg === '') {
        if ($auth->login((string) ($_POST['user'] ?? ''), (string) $_POST['pwd'])) {
            if (!empty($_POST['remember'])) {
                $rememberMe->issueAndSetCookie($auth->actor());
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        $authErr    = true;
        $lockoutMsg = $auth->getLockoutMessage();
    }

    $view->render('login', compact('auth', 'authErr', 'lockoutMsg'));
    exit;
}

$actor = $auth->actor();

// ── Helpers ───────────────────────────────────────────────────────
function validMonth(string $m): string
{
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : date('Y-m');
}

function validDate(string $d): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : date('Y-m-d');
}

// ── Routing ───────────────────────────────────────────────────────
$page = (isset($_GET['page']) && preg_match('/^[a-z_]+$/', $_GET['page']))
    ? $_GET['page'] : 'app';

// ════════════════════════════════════════════════════════════════
//  SETTINGS
// ════════════════════════════════════════════════════════════════
if ($page === 'settings') {
    $settingsError   = $_SESSION['settings_error'] ?? null;
    $settingsSuccess = $_SESSION['settings_success'] ?? null;
    unset($_SESSION['settings_error'], $_SESSION['settings_success']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $auth->validateCsrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'add_person') {
            $name = trim($_POST['name'] ?? '');
            if ($name !== '') {
                $personRepo->add(new Person(
                    id:   bin2hex(random_bytes(8)),
                    name: $name,
                ));
                $_SESSION['settings_success'] = "Persona «{$name}» agregada.";
            }

        } elseif ($action === 'del_person' && !empty($_POST['pid'])) {
            $pid         = $_POST['pid'];
            $hasExpenses = !empty(array_filter(
                $expRepo->findAll(),
                fn(Expense $e) => $e->who === $pid
            ));

            if ($hasExpenses) {
                $person = $personRepo->findById($pid);
                $_SESSION['settings_error'] = 'No se puede eliminar a «' . ($person?->name ?? $pid) . '» porque tiene gastos registrados.';
            } else {
                $personRepo->delete($pid);
                $_SESSION['settings_success'] = 'Persona eliminada.';
            }
        }

        header('Location: ?page=settings');
        exit;
    }

    $people = $personRepo->findAll();
    $view->render('settings', compact('auth', 'actor', 'config', 'people', 'settingsError', 'settingsSuccess'));
    exit;
}

// ════════════════════════════════════════════════════════════════
//  ADMIN — USUARIOS
// ════════════════════════════════════════════════════════════════
if ($page === 'admin_users') {
    if (!$authz->canManageUsers($actor)) {
        http_response_code(403);
        die('No tenés permisos para acceder a esta sección.');
    }

    $adminError   = $_SESSION['admin_error']   ?? null;
    $adminSuccess = $_SESSION['admin_success'] ?? null;
    unset($_SESSION['admin_error'], $_SESSION['admin_success']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $auth->validateCsrf();
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'create_user') {
                $userController->createUser(
                    $actor,
                    trim($_POST['name']     ?? ''),
                    trim($_POST['username'] ?? ''),
                    (string) ($_POST['password'] ?? ''),
                    (string) ($_POST['role']     ?? 'member')
                );
                $_SESSION['admin_success'] = 'Usuario creado correctamente.';
            } elseif ($action === 'deactivate_user' && !empty($_POST['uid'])) {
                $userController->deactivateUser($actor, $_POST['uid']);
                $_SESSION['admin_success'] = 'Usuario desactivado.';
            } elseif ($action === 'reactivate_user' && !empty($_POST['uid'])) {
                $userController->reactivateUser($actor, $_POST['uid']);
                $_SESSION['admin_success'] = 'Usuario reactivado.';
            }
        } catch (InvalidArgumentException|UnauthorizedActionException $e) {
            $_SESSION['admin_error'] = $e->getMessage();
        }

        header('Location: ?page=admin_users');
        exit;
    }

    $users = $userController->listUsers($actor);
    $view->render('admin_users', compact('auth', 'actor', 'users', 'adminError', 'adminSuccess'));
    exit;
}

// ════════════════════════════════════════════════════════════════
//  MAIN APP
// ════════════════════════════════════════════════════════════════
$people      = $personRepo->findAll();
$peopleById  = [];
$peopleColors = [];
foreach ($people as $i => $person) {
    $peopleById[$person->id]   = $person;
    $peopleColors[$person->id] = $config->personPalette[$i % count($config->personPalette)];
}

$curMonth = validMonth($_POST['month'] ?? $_GET['month'] ?? date('Y-m'));

// ── POST Actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->validateCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add' && !empty(trim($_POST['desc'] ?? '')) && isset($_POST['amt'])) {
            $validIds = array_keys($peopleById);
            $who      = in_array($_POST['who'] ?? '', $validIds, true)
                ? $_POST['who'] : ($validIds[0] ?? '');

            $cat = in_array($_POST['cat'] ?? '', array_keys($config->categoryColors), true)
                ? $_POST['cat'] : 'Otro';

            if ($who !== '') {
                $expRepo->add(new Expense(
                    id:      bin2hex(random_bytes(8)),
                    who:     $who,
                    desc:    trim($_POST['desc']),
                    amt:     max(0.0, (float) str_replace(',', '.', $_POST['amt'])),
                    cat:     $cat,
                    date:    validDate($_POST['date'] ?? date('Y-m-d')),
                    ownerId: $actor->id,
                ));
            }

        } elseif ($action === 'del' && !empty($_POST['eid'])) {
            $expense = $expRepo->findById($_POST['eid']);
            if ($expense !== null) {
                if (!$authz->canDeleteExpense($actor, $expense)) {
                    throw new UnauthorizedActionException('No podés eliminar un gasto que no cargaste vos.');
                }
                $expRepo->delete($expense->id);
            }
        }
    } catch (UnauthorizedActionException $e) {
        http_response_code(403);
        die(e($e->getMessage()));
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?month=' . urlencode($curMonth));
    exit;
}

// ── Data for view ─────────────────────────────────────────────────
$exps = $expRepo->findByMonth($curMonth);
usort($exps, fn($a, $b) => strcmp($b->date, $a->date));

$expsByPerson   = [];
$totalsByPerson = [];
$total          = 0.0;

foreach ($people as $person) {
    $personExps                  = array_values(array_filter($exps, fn($e) => $e->who === $person->id));
    $expsByPerson[$person->id]   = $personExps;
    $totalsByPerson[$person->id] = array_sum(array_column(array_map(fn($e) => $e->toArray(), $personExps), 'amt'));
    $total                      += $totalsByPerson[$person->id];
}

$ideal = count($people) > 0 && $total > 0.01 ? $total / count($people) : 0.0;

$balances     = [];
$pctsByPerson = [];
foreach ($people as $person) {
    $paid                      = $totalsByPerson[$person->id] ?? 0.0;
    $balances[$person->id]     = $paid - $ideal;   // positivo: pagó de más; negativo: debe
    $pctsByPerson[$person->id] = $total > 0
        ? round($paid / $total * 100, 1)
        : (count($people) > 0 ? round(100.0 / count($people), 1) : 0.0);
}

$ML     = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
           'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mLabel = $ML[(int) substr($curMonth, 5, 2) - 1] . ' ' . substr($curMonth, 0, 4);
$prevM  = date('Y-m', strtotime($curMonth . '-01 -1 month'));
$nextM  = date('Y-m', strtotime($curMonth . '-01 +1 month'));
$isNow  = ($curMonth === date('Y-m'));

$view->render('app', compact(
    'config', 'auth', 'actor', 'people', 'peopleById', 'peopleColors',
    'exps', 'expsByPerson', 'totalsByPerson',
    'total', 'ideal', 'balances', 'pctsByPerson',
    'mLabel', 'prevM', 'nextM', 'isNow', 'curMonth'
));
