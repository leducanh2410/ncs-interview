<?php

namespace App\Enums;

enum RoleName: string
{
    use EnumTrait;

    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Doctor = 'doctor';
    case Staff = 'staff';
    case StaffManager = 'staff_manager';
}
