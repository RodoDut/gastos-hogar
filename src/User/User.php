<?php
declare(strict_types=1);

namespace GastosHogar\User;

class User
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $username,
        public readonly string $passwordHash,
        public readonly UserRole $role,
        public readonly bool $active,
    ) {}

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'username'     => $this->username,
            'passwordHash' => $this->passwordHash,
            'role'         => $this->role->value,
            'active'       => $this->active,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id:           $data['id'],
            name:         $data['name'] ?? $data['username'],
            username:     $data['username'],
            passwordHash: $data['passwordHash'],
            role:         UserRole::from($data['role'] ?? 'member'),
            active:       (bool) ($data['active'] ?? true),
        );
    }
}
