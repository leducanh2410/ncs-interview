<?php

namespace App\Enums;

enum OcrResultStatus: string
{
    use EnumTrait;

    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
