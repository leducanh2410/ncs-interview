<?php

namespace App\Enums;

enum NotificationTargetType: string
{
    use EnumTrait;

    case All = 'all';

    case Workplace = 'workplace';
}
