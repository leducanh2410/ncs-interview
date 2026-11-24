<?php

namespace App\Enums;

enum ChatMessageType: string
{
    use EnumTrait;

    case Text = 'text';
    case Image = 'image';
    case File = 'file';
    case System = 'system';
}
