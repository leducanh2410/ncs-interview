<?php

namespace App\Enums;

enum RoleId: int
{
    case SuperAdmin = 1;
    case Admin = 10;
    case Doctor = 25;
    case Staff = 100;
    case StaffManager = 50;
}
