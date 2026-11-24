<?php

namespace App\Enums;

enum NameCheckVerdict: string
{
    use EnumTrait;

    case Match = 'match';

    case Partial = 'partial';

    case Mismatch = 'mismatch';
}
