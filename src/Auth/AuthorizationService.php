<?php
declare(strict_types=1);

namespace GastosHogar\Auth;

use GastosHogar\Expense\Expense;
use GastosHogar\User\User;

/**
 * Punto único de verdad para permisos. Ningún otro punto del código debe
 * decidir autorización a partir de datos de sesión directamente.
 */
class AuthorizationService
{
    public function canManageUsers(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function canEditExpense(User $actor, Expense $expense): bool
    {
        return $expense->ownerId === $actor->id;
    }

    public function canDeleteExpense(User $actor, Expense $expense): bool
    {
        return $expense->ownerId === $actor->id;
    }

    public function canViewAllExpenses(User $actor): bool
    {
        return $actor->isAdmin();
    }
}
