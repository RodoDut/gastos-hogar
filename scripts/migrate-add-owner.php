<?php
declare(strict_types=1);

/**
 * Migración interactiva: agrega owner_id a los gastos de data/gastos.json
 * que todavía no lo tengan. Intenta inferir el dueño comparando el campo
 * "who" del gasto contra el id o el nombre de las personas en people.json;
 * si no puede inferir, pregunta por CLI. Permite además asignar en bloque
 * ("all") el resto de los gastos sin owner_id a una misma persona.
 *
 * Requisito: correr scripts/migrate-users.php primero, así people.json
 * ya tiene id/name para todas las personas.
 *
 * Uso (manual, desde la raíz del proyecto):
 *   php scripts/migrate-add-owner.php
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable($root);
$dotenv->safeLoad();

$peopleFile = $root . '/data/people.json';
$gastosFile = $root . '/' . ($_ENV['DATA_FILE'] ?? 'data/gastos.json');

if (!file_exists($gastosFile)) {
    fwrite(STDERR, "No se encontró {$gastosFile}\n");
    exit(1);
}

$peopleRaw = file_exists($peopleFile) ? file_get_contents($peopleFile) : '{}';
$people    = (json_decode($peopleRaw ?: '{}', true) ?? [])['people'] ?? [];

if (empty($people)) {
    fwrite(STDERR, "No hay personas en {$peopleFile}; migrá usuarios primero.\n");
    exit(1);
}

function findPersonByIdOrName(array $people, string $value): ?array
{
    foreach ($people as $p) {
        if (($p['id'] ?? null) === $value) {
            return $p;
        }
    }
    foreach ($people as $p) {
        if (isset($p['name']) && strcasecmp((string) $p['name'], $value) === 0) {
            return $p;
        }
    }
    return null;
}

function prompt(string $label): string
{
    echo "{$label}: ";
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

$raw  = file_get_contents($gastosFile);
$data = ($raw !== false ? json_decode($raw, true) : null) ?? ['expenses' => []];
$rows = $data['expenses'] ?? [];

if (empty($rows)) {
    echo "No hay gastos en {$gastosFile}.\n";
    exit(0);
}

echo "Personas disponibles:\n";
foreach ($people as $p) {
    echo "  - {$p['name']} (id: {$p['id']})\n";
}
echo "\n";

$defaultOwnerId = null;
$changed        = false;

foreach ($rows as $i => $row) {
    if (!empty($row['owner_id'])) {
        continue;
    }

    $who  = (string) ($row['who']  ?? '');
    $desc = (string) ($row['desc'] ?? '');
    $amt  = $row['amt']  ?? 0;
    $date = (string) ($row['date'] ?? '');

    if ($defaultOwnerId !== null) {
        $rows[$i]['owner_id'] = $defaultOwnerId;
        $changed = true;
        echo "Gasto \"{$desc}\" — \${$amt} — {$date} → asignado automáticamente a {$defaultOwnerId} (modo 'all')\n";
        continue;
    }

    $inferred   = findPersonByIdOrName($people, $who);
    $suggestion = $inferred['id'] ?? ($people[0]['id'] ?? '');

    $suggestionName = '';
    foreach ($people as $p) {
        if ($p['id'] === $suggestion) {
            $suggestionName = $p['name'];
            break;
        }
    }

    echo "Gasto sin owner_id: \"{$desc}\" — \${$amt} — {$date} — who=\"{$who}\"\n";
    $answer = prompt("  Asignar a [{$suggestionName}], escribí otro id/nombre, o 'all' para asignar este y todos los restantes a [{$suggestionName}]");

    if ($answer === '') {
        $ownerId = $suggestion;
    } elseif (strtolower($answer) === 'all') {
        $ownerId        = $suggestion;
        $defaultOwnerId = $suggestion;
    } else {
        $match   = findPersonByIdOrName($people, $answer);
        $ownerId = $match['id'] ?? $suggestion;
    }

    $rows[$i]['owner_id'] = $ownerId;
    $changed = true;
    echo "  ✓ asignado a {$ownerId}\n\n";
}

if (!$changed) {
    echo "Todos los gastos ya tienen owner_id. Nada que migrar.\n";
    exit(0);
}

$data['expenses'] = $rows;

$fh = fopen($gastosFile, 'c+');
if ($fh === false) {
    fwrite(STDERR, "No se pudo abrir {$gastosFile} para escritura.\n");
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
@chmod($gastosFile, 0600);

echo "Migración completa. " . count($rows) . " gasto(s) en {$gastosFile}.\n";
