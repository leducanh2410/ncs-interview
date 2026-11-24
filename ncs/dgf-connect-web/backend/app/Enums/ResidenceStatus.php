<?php

namespace App\Enums;

enum ResidenceStatus: string
{
    use EnumTrait;

    case StudyAbroad = 'study_abroad';
    case FamilyStay = 'family_stay';
    case DesignatedActivities = 'designated_activities';
    case LongTermResident = 'long_term_resident';
    case SpouseOfPermanentResident = 'spouse_of_permanent_resident';
    case PermanentResident = 'permanent_resident';
    case SpouseOfJapaneseCitizen = 'spouse_of_japanese_citizen';

    public static function toCrossStaffString(?string $value): string
    {
        return match ($value) {
            self::StudyAbroad->value => '留学',
            self::FamilyStay->value => '家族滞在',
            self::DesignatedActivities->value => '特定活動',
            self::LongTermResident->value => '定住者',
            self::SpouseOfPermanentResident->value => '永住者の配偶者等',
            self::PermanentResident->value => '永住者',
            self::SpouseOfJapaneseCitizen->value => '日本人の配偶者等',
            default => '',
        };
    }

    public static function fromCrossStaffString(?string $value): ?string
    {
        return match ($value) {
            '留学' => self::StudyAbroad->value,
            '家族滞在' => self::FamilyStay->value,
            '特定活動' => self::DesignatedActivities->value,
            '定住者' => self::LongTermResident->value,
            '永住者の配偶者等' => self::SpouseOfPermanentResident->value,
            '永住者' => self::PermanentResident->value,
            '日本人の配偶者等' => self::SpouseOfJapaneseCitizen->value,
            default => null,
        };
    }
}
