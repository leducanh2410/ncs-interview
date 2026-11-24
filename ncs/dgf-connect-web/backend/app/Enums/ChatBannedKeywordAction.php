<?php

namespace App\Enums;

enum ChatBannedKeywordAction: string
{
    case Block = 'block';
    case Mask = 'mask';
}
