<?php
declare(strict_types=1);

namespace GastosHogar\User;

/**
 * Comparte el archivo people.json con JsonPersonRepository: cada fila puede
 * tener únicamente id/name (una Person sin login) o además username/
 * passwordHash/role/active (una Person que también es User).
 */
class JsonUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly string $filePath) {}

    public function findAll(): array
    {
        $users = [];
        foreach ($this->readRaw()['people'] ?? [] as $row) {
            if (!isset($row['username'], $row['passwordHash'])) {
                continue;
            }
            $users[] = User::fromArray($row);
        }
        return $users;
    }

    public function findById(string $id): ?User
    {
        foreach ($this->findAll() as $user) {
            if ($user->id === $id) {
                return $user;
            }
        }
        return null;
    }

    public function findByUsername(string $username): ?User
    {
        foreach ($this->findAll() as $user) {
            if (strcasecmp($user->username, $username) === 0) {
                return $user;
            }
        }
        return null;
    }

    public function findActive(): array
    {
        return array_values(array_filter($this->findAll(), fn(User $u) => $u->active));
    }

    public function save(User $user): void
    {
        $data  = $this->readRaw();
        $rows  = $data['people'] ?? [];
        $found = false;

        foreach ($rows as $i => $row) {
            if (($row['id'] ?? null) === $user->id) {
                $rows[$i] = array_merge($row, $user->toArray());
                $found    = true;
                break;
            }
        }

        if (!$found) {
            $rows[] = $user->toArray();
        }

        $data['people'] = $rows;
        $this->write($data);
    }

    private function readRaw(): array
    {
        if (!file_exists($this->filePath)) {
            return ['people' => []];
        }
        $raw = file_get_contents($this->filePath);
        return ($raw !== false ? json_decode($raw, true) : null) ?? ['people' => []];
    }

    private function write(array $data): void
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
            fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fh);
            flock($fh, LOCK_UN);
        }

        fclose($fh);
        @chmod($this->filePath, 0600);
    }
}
