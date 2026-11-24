<?php

namespace App\Enums;

enum AbsentNoticeStatus: string
{
    use EnumTrait;

    case Pending = 'pending';
    case Processed = 'processed';
    case Rejected = 'rejected';

    /**
     * Both processed and rejected are decisions an admin made, so both record who acted and when;
     * only pending means nobody has touched the notice yet.
     */
    public function isHandled(): bool
    {
        return $this !== self::Pending;
    }
}
