<?php

namespace App\Enums;

enum UserPaperStatus: string
{
    case Registered = 'registered';
    case Pending = 'pending';
    case WaitForApproval = 'wait_for_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
