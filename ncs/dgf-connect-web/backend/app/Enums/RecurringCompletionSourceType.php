<?php

namespace App\Enums;

enum RecurringCompletionSourceType: string
{
    use EnumTrait;

    case PollResponse = 'poll_response';
    case ExamResult = 'exam_result';
}
