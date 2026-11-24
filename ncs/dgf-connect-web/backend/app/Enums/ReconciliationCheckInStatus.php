<?php

namespace App\Enums;

enum ReconciliationCheckInStatus: string
{
    use EnumTrait;

    case None = 'none';
    case Single = 'single';
    case Multiple = 'multiple';
}
