<?php

namespace App\Enums;

enum ReconciliationBatchStatus: string
{
    use EnumTrait;

    case Draft = 'draft';
    case Confirmed = 'confirmed';
}
