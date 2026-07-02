<?php
declare(strict_types=1);

namespace GastosHogar\Auth;

interface RememberMeRepositoryInterface
{
    public function findBySelector(string $selector): ?RememberMeToken;

    public function save(RememberMeToken $token): void;

    public function deleteBySelector(string $selector): void;

    public function deleteAllForUser(string $userId): void;

    public function purgeExpired(): void;
}
