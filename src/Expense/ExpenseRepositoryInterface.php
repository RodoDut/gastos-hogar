<?php
declare(strict_types=1);

namespace GastosHogar\Expense;

interface ExpenseRepositoryInterface
{
    /** @return Expense[] */
    public function findAll(): array;

    /** @return Expense[] */
    public function findByMonth(string $month): array;

    public function add(Expense $expense): void;

    public function delete(string $id): void;
}
