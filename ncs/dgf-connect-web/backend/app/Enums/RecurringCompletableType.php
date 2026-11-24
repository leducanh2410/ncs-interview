<?php

namespace App\Enums;

enum RecurringCompletableType: string
{
    use EnumTrait;

    case Poll = 'poll';
    case Exam = 'exam';
}
