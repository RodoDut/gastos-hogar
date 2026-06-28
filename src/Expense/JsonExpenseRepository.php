<?php
declare(strict_types=1);

namespace GastosHogar\Expense;

class JsonExpenseRepository implements ExpenseRepositoryInterface
{
    public function __construct(private readonly string $filePath) {}

    public function findAll(): array
    {
        return array_map(
            fn(array $row) => Expense::fromArray($row),
            $this->readRows()
        );
    }

    public function findByMonth(string $month): array
    {
        return array_values(array_filter(
            $this->findAll(),
            fn(Expense $e) => str_starts_with($e->date, $month)
        ));
    }

    public function add(Expense $expense): void
    {
        $data               = $this->readRaw();
        $data['expenses'][] = $expense->toArray();
        $this->write($data);
    }

    public function delete(string $id): void
    {
        $data               = $this->readRaw();
        $data['expenses']   = array_values(
            array_filter($data['expenses'], fn(array $e) => $e['id'] !== $id)
        );
        $this->write($data);
    }

    private function readRows(): array
    {
        return $this->readRaw()['expenses'];
    }

    private function readRaw(): array
    {
        if (!file_exists($this->filePath)) {
            return ['expenses' => []];
        }
        $raw = file_get_contents($this->filePath);
        return ($raw !== false ? json_decode($raw, true) : null) ?? ['expenses' => []];
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
