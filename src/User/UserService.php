<?php
declare(strict_types=1);

namespace GastosHogar\User;

use InvalidArgumentException;

class UserService
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(private readonly UserRepositoryInterface $users) {}

    public function createUser(string $name, string $username, string $password, UserRole $role): User
    {
        $name     = trim($name);
        $username = trim($username);

        if ($name === '' || $username === '') {
            throw new InvalidArgumentException('Nombre y usuario son obligatorios.');
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException(
                'La contraseña debe tener al menos ' . self::MIN_PASSWORD_LENGTH . ' caracteres.'
            );
        }

        if ($this->users->findByUsername($username) !== null) {
            throw new InvalidArgumentException("El usuario «{$username}» ya existe.");
        }

        $user = new User(
            id:           bin2hex(random_bytes(8)),
            name:         $name,
            username:     $username,
            passwordHash: password_hash($password, PASSWORD_DEFAULT),
            role:         $role,
            active:       true,
        );

        $this->users->save($user);
        return $user;
    }

    public function deactivateUser(string $id): void
    {
        $user = $this->mustFind($id);
        $this->users->save(new User(
            $user->id, $user->name, $user->username, $user->passwordHash, $user->role, active: false
        ));
    }

    public function reactivateUser(string $id): void
    {
        $user = $this->mustFind($id);
        $this->users->save(new User(
            $user->id, $user->name, $user->username, $user->passwordHash, $user->role, active: true
        ));
    }

    private function mustFind(string $id): User
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            throw new InvalidArgumentException('Usuario no encontrado.');
        }
        return $user;
    }
}
