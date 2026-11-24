<?php

namespace App\Enums;

enum NotificationStatus: string
{
    use EnumTrait;

    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';
}
