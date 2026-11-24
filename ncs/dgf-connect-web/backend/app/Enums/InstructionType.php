<?php

namespace App\Enums;

enum InstructionType: string
{
    use EnumTrait;

    case Link = 'link';
    case Video = 'video';
    case Image = 'image';
}
