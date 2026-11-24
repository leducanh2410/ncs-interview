<?php

namespace App\Enums;

enum NotificationIcon: string
{
    use EnumTrait;

    case ANNOUNCEMENT = 'announcement';
    case COG = 'cog';
    case HEART = 'heart';
    case GEMINI = 'gemini';
    case FILE = 'file';
    case BUILDING = 'building';
    case AWARD = 'award';
    case MONEY = 'money';
    case INFO = 'info';
    case WARNING = 'warning';
}
