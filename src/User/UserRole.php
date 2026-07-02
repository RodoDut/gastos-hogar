<?php
declare(strict_types=1);

namespace GastosHogar\User;

enum UserRole: string
{
    case Admin  = 'admin';
    case Member = 'member';
}
