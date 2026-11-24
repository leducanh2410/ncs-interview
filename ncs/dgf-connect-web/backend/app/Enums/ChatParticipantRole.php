<?php

namespace App\Enums;

enum ChatParticipantRole: string
{
    use EnumTrait;

    case Admin = 'admin';
    case Deputy = 'deputy';
    case Employee = 'employee';
}
