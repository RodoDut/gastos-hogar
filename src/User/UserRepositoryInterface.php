<?php
declare(strict_types=1);

namespace GastosHogar\User;

interface UserRepositoryInterface
{
    /** @return User[] */
    public function findAll(): array;

    public function findById(string $id): ?User;

    public function findByUsername(string $username): ?User;

    /** @return User[] */
    public function findActive(): array;

    public function save(User $user): void;
}
