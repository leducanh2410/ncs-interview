<?php

namespace App\Enums;

enum SupportType: string
{
    use EnumTrait;

    case Document = 'document';
    case Shift = 'shift';
    case Salary = 'salary';
    case Transportation = 'transportation';
    case Other = 'other';
}
