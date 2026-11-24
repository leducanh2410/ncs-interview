<?php

namespace App\Enums;

enum RoleId: int
{
    case GlobalAdmin = 1;
    case Admin = 10;
    case User = 100;
}
