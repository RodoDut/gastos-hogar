<?php
declare(strict_types=1);

namespace GastosHogar\Person;

interface PersonRepositoryInterface
{
    /** @return Person[] */
    public function findAll(): array;

    public function findById(string $id): ?Person;

    public function add(Person $person): void;

    public function delete(string $id): void;
}
