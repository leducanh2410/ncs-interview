<?php

namespace App\Enums;

enum ReconciliationDecision: string
{
    use EnumTrait;

    case Amazon = 'amazon';
    case Dgf = 'dgf';
}
