<?php

namespace App\Enums;

enum ShiftUserStatus: string
{
    use EnumTrait;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
