<?php

namespace App\Enums;

enum AnnouncementState: string
{
    use EnumTrait;

    case Inactive = 'inactive';

    case Scheduled = 'scheduled';

    case Active = 'active';

    case Expired = 'expired';
}
