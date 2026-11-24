<?php

namespace App\Enums;

enum AttachmentType: string
{
    use EnumTrait;

    case File = 'file';
    case Link = 'link';
}
