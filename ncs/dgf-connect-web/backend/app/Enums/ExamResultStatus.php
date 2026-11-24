<?php

namespace App\Enums;

enum ExamResultStatus: string
{
    use EnumTrait;

    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case PendingExternal = 'pending_external';
}
