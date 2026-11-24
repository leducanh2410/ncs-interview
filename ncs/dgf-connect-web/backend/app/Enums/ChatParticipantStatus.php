<?php

namespace App\Enums;

enum ChatParticipantStatus: string
{
    use EnumTrait;

    case Active = 'active';
    case Removed = 'removed';
}
