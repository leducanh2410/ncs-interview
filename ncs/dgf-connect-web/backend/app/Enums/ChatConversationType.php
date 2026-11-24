<?php

namespace App\Enums;

enum ChatConversationType: string
{
    use EnumTrait;

    case AdminGroup = 'admin_group';
    case ShiftGroup = 'shift_group';
    case UserGroup = 'user_group';
    case Support = 'support';
    case GuestSupport = 'guest_support';
}
