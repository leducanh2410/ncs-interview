<?php

namespace App\Enums;

enum AdminLeaveRequestStatus: string
{
    use EnumTrait;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
