<?php

namespace App\Enums;

enum FixedScheduleChangeRequestStatus: string
{
    use EnumTrait;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
