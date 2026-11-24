<?php

namespace App\Enums;

enum UserStatus: string
{
    use EnumTrait;

    case Active = 'active';
    case Pending = 'pending';
    case PendingDeletion = 'pending_deletion';
}
