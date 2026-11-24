<?php

namespace App\Enums;

enum WorkHistoryEditRequestStatus: string
{
    use EnumTrait;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
