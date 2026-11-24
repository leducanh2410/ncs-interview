<?php

namespace App\Enums;

enum ExamSessionStatus: string
{
    use EnumTrait;

    case InProgress = 'in_progress';
    case NotStarted = 'not_started';
    case Submitted = 'submitted';
}
