<?php

namespace App\Enums;

enum PollTargetType: string
{
    case GLOBAL = 'global';
    case WORKPLACE = 'workplace';
    case SPECIFIC_USERS = 'specific_users';
}
