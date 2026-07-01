<?php
declare(strict_types=1);

/**
 * Migración interactiva: agrega username/passwordHash/role/active a los
 * registros de data/people.json que todavía no los tengan (personas que
 * existían antes del sistema de usuarios). Idempotente: las filas que ya
 * tienen esos campos se dejan intactas.
 *
 * Uso (manual, desde la raíz del proyecto):
 *   php scripts/migrate-users.php
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$peopleFile = $root . '/data/people.json';

if (!file_exists($peopleFile)) {
    fwrite(STDERR, "No se encontró {$peopleFile}\n");
    exit(1);
}

$raw  = file_get_contents($peopleFile);
$data = ($raw !== false ? json_decode($raw, true) : null) ?? ['people' => []];
$rows = $data['people'] ?? [];

if (empty($rows)) {
    echo "No hay personas en people.json.\n";
    exit(0);
}

function prompt(string $label, string $default = ''): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    echo "{$label}{$suffix}: ";
    $line = fgets(STDIN);
    $line = $line === false ? '' : trim($line);
    return $line === '' ? $default : $line;
}

function promptPassword(string $label): string
{
    while (true) {
        $pwd = prompt($label);
        if (strlen($pwd) >= 8) {
            return $pwd;
        }
        echo "  La contraseña debe tener al menos 8 caracteres.\n";
    }
}

function slug(string $name): string
{
    $slug = strtolower(trim($name));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $slug);
    $slug = $ascii !== false ? $ascii : $slug;
    $slug = preg_replace('/[^a-z0-9]+/', '', $slug) ?? '';
    return $slug !== '' ? $slug : bin2hex(random_bytes(4));
}

$changed = false;

foreach ($rows as $i => $row) {
    if (isset($row['username'], $row['passwordHash'], $row['role'], $row['active'])) {
        continue;
    }

    $name = $row['name'] ?? ('Persona ' . ($i + 1));
    echo "\n— Migrando a usuario: {$name} —\n";

    $username = prompt('  Usuario (login)', slug((string) $name));
    $password = promptPassword('  Contraseña (mín. 8 caracteres)');
    $role     = strtolower(prompt('  Rol (admin/member)', 'member'));
    $role     = $role === 'admin' ? 'admin' : 'member';

    $rows[$i]['id']           = $row['id'] ?? bin2hex(random_bytes(8));
    $rows[$i]['name']         = $name;
    $rows[$i]['username']     = $username;
    $rows[$i]['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
    $rows[$i]['role']         = $role;
    $rows[$i]['active']       = true;

    $changed = true;
    echo "  ✓ {$name} migrado como '{$username}' ({$role}).\n";
}

if (!$changed) {
    echo "Todas las personas ya tienen datos de usuario. Nada que migrar.\n";
    exit(0);
}

$data['people'] = $rows;

$fh = fopen($peopleFile, 'c+');
if ($fh === false) {
    fwrite(STDERR, "No se pudo abrir {$peopleFile} para escritura.\n");
    exit(1);
}
if (flock($fh, LOCK_EX)) {
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
}
fclose($fh);
@chmod($peopleFile, 0600);

echo "\nMigración completa. " . count($rows) . " persona(s) en {$peopleFile}.\n";
