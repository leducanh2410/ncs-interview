<?php

namespace App\Enums;

enum ReconciliationMatchStatus: string
{
    use EnumTrait;

    case Exact = 'exact';
    case Similar = 'similar';
    case Ambiguous = 'ambiguous';
    case Unmatched = 'unmatched';
    case DuplicateExternal = 'duplicate_external';
}
