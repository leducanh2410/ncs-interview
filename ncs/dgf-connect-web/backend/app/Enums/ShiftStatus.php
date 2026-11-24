<?php

namespace App\Enums;

enum ShiftStatus: string
{
    case Ended = 'ended';
    case InProgress = 'in_progress';
    case ComingSoon = 'coming_soon';
}
