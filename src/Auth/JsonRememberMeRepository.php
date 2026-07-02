<?php
declare(strict_types=1);

namespace GastosHogar\Auth;

/**
 * Almacena los tokens de "recordarme" en data/tokens.json como un array plano
 * (a diferencia de people.json/gastos.json, que envuelven las filas en un objeto).
 */
class JsonRememberMeRepository implements RememberMeRepositoryInterface
{
    public function __construct(private readonly string $filePath) {}

    public function findBySelector(string $selector): ?RememberMeToken
    {
        foreach ($this->readRaw() as $row) {
            if (($row['selector'] ?? null) === $selector) {
                return RememberMeToken::fromArray($row);
            }
        }
        return null;
    }

    public function save(RememberMeToken $token): void
    {
        $rows  = $this->readRaw();
        $found = false;

        foreach ($rows as $i => $row) {
            if (($row['selector'] ?? null) === $token->selector) {
                $rows[$i] = $token->toArray();
                $found    = true;
                break;
            }
        }

        if (!$found) {
            $rows[] = $token->toArray();
        }

        $this->write($rows);
    }

    public function deleteBySelector(string $selector): void
    {
        $rows = array_values(array_filter(
            $this->readRaw(),
            fn(array $row) => ($row['selector'] ?? null) !== $selector
        ));
        $this->write($rows);
    }

    public function deleteAllForUser(string $userId): void
    {
        $rows = array_values(array_filter(
            $this->readRaw(),
            fn(array $row) => ($row['userId'] ?? null) !== $userId
        ));
        $this->write($rows);
    }

    public function purgeExpired(): void
    {
        $now  = time();
        $rows = array_values(array_filter(
            $this->readRaw(),
            fn(array $row) => (int) ($row['expiresAt'] ?? 0) >= $now
        ));
        $this->write($rows);
    }

    private function readRaw(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }
        $raw = file_get_contents($this->filePath);
        return ($raw !== false ? json_decode($raw, true) : null) ?? [];
    }

    private function write(array $rows): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        $fh = fopen($this->filePath, 'c+');
        if ($fh === false) {
            return;
        }

        if (flock($fh, LOCK_EX)) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fh);
            flock($fh, LOCK_UN);
        }

        fclose($fh);
        @chmod($this->filePath, 0600);
    }
}
