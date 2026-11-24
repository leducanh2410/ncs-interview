<?php

namespace App\Enums;

enum RoleName: string
{
    use EnumTrait;

    case GlobalAdmin = 'global_admin';
    case Admin = 'admin';
    case User = 'user';

    public static function adminRoles(): array
    {
        return [self::GlobalAdmin->value, self::Admin->value];
    }
}
