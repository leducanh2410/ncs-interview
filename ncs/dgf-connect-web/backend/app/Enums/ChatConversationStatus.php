<?php

namespace App\Enums;

enum ChatConversationStatus: string
{
    use EnumTrait;

    case Open = 'open';
    case Closed = 'closed';
}
