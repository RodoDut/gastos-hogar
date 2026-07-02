<?php
declare(strict_types=1);

namespace GastosHogar\Admin;

use GastosHogar\Auth\AuthorizationService;
use GastosHogar\Auth\UnauthorizedActionException;
use GastosHogar\User\User;
use GastosHogar\User\UserRepositoryInterface;
use GastosHogar\User\UserRole;
use GastosHogar\User\UserService;
use InvalidArgumentException;

class UserController
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UserService $userService,
        private readonly AuthorizationService $authz,
    ) {}

    /** @return User[] */
    public function listUsers(User $actor): array
    {
        $this->assertCanManage($actor);
        return $this->users->findAll();
    }

    public function createUser(User $actor, string $name, string $username, string $password, string $role): User
    {
        $this->assertCanManage($actor);
        $userRole = UserRole::tryFrom($role) ?? UserRole::Member;
        return $this->userService->createUser($name, $username, $password, $userRole);
    }

    public function deactivateUser(User $actor, string $targetId): void
    {
        $this->assertCanManage($actor);
        if ($targetId === $actor->id) {
            throw new InvalidArgumentException('No podés desactivarte a vos mismo.');
        }
        $this->userService->deactivateUser($targetId);
    }

    public function reactivateUser(User $actor, string $targetId): void
    {
        $this->assertCanManage($actor);
        $this->userService->reactivateUser($targetId);
    }

    private function assertCanManage(User $actor): void
    {
        if (!$this->authz->canManageUsers($actor)) {
            throw new UnauthorizedActionException('No tenés permisos para gestionar usuarios.');
        }
    }
}
