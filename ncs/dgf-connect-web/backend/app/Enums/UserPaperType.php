<?php

namespace App\Enums;

enum UserPaperType: string
{
    use EnumTrait;

    case Passport = 'passport';
    case ForeignerCardFront = 'foreigner_card_front';
    case ForeignerCardBack = 'foreigner_card_back';
    case ForeignerCardScan = 'foreigner_card_scan';
    case StudentCardFront = 'student_card_front';
    case StudentCardBack = 'student_card_back';
    case PortraitImage = 'portrait_image';
    case WorkPermitStamp = 'work_permit_stamp';
    case Other = 'other';

    public static function getRequiredTypes(): array
    {
        return [
            self::Passport->value,
            self::ForeignerCardFront->value,
            self::ForeignerCardBack->value,
            self::PortraitImage->value,
        ];
    }
}
