<?php
declare(strict_types=1);

namespace GastosHogar\Person;

class JsonPersonRepository implements PersonRepositoryInterface
{
    public function __construct(private readonly string $filePath) {}

    public function findAll(): array
    {
        return array_map(
            fn(array $row) => Person::fromArray($row),
            $this->readRaw()['people'] ?? []
        );
    }

    public function findById(string $id): ?Person
    {
        foreach ($this->findAll() as $person) {
            if ($person->id === $id) {
                return $person;
            }
        }
        return null;
    }

    public function add(Person $person): void
    {
        $data             = $this->readRaw();
        $data['people'][] = $person->toArray();
        $this->write($data);
    }

    public function delete(string $id): void
    {
        $data           = $this->readRaw();
        $data['people'] = array_values(
            array_filter($data['people'], fn(array $p) => $p['id'] !== $id)
        );
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
