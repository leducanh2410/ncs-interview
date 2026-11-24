<?php

namespace App\Enums;

enum Nationality: string
{
    use EnumTrait;

    case Vietnam = 'vietnam';
    case Nepal = 'nepal';
    case SriLanka = 'sri_lanka';
    case Myanmar = 'myanmar';
    case Indonesia = 'indonesia';
    case Cambodia = 'cambodia';
    case Thailand = 'thailand';
    case Pakistan = 'pakistan';
    case Bangladesh = 'bangladesh';
    case Mongolia = 'mongolia';
    case Laos = 'laos';
    case India = 'india';
    case Singapore = 'singapore';
    case Korea = 'korea';
    case China = 'china';
    case EastTimor = 'east_timor';
    case Philippines = 'philippines';
    case Bhutan = 'bhutan';
    case Brunei = 'brunei';
    case Malaysia = 'malaysia';
    case Maldives = 'maldives';
    case Other = 'other';

    public static function toCrossStaffString(?string $value): string
    {
        return match ($value) {
            self::Vietnam->value => 'ベトナム',
            self::Nepal->value => 'ネパール',
            self::SriLanka->value => 'スリランカ',
            self::Myanmar->value => 'ミャンマー',
            self::Indonesia->value => 'インドネシア',
            self::Cambodia->value => 'カンボジア',
            self::Thailand->value => 'タイ',
            self::Pakistan->value => 'パキスタン',
            self::Bangladesh->value => 'バングラデシュ',
            self::Mongolia->value => 'モンゴル',
            self::Laos->value => 'ラオス',
            self::India->value => 'インド',
            self::Singapore->value => 'シンガポール',
            self::Korea->value => '韓国',
            self::China->value => '中国',
            self::EastTimor->value => '東ティモール',
            self::Philippines->value => 'フィリピン',
            self::Bhutan->value => 'ブータン',
            self::Brunei->value => 'ブルネイ',
            self::Malaysia->value => 'マレーシア',
            self::Maldives->value => 'モルディブ',
            self::Other->value => 'その他',
            default => '',
        };
    }

    public static function fromCrossStaffString(?string $value): string
    {
        return match ($value) {
            'ベトナム' => self::Vietnam->value,
            'ネパール' => self::Nepal->value,
            'スリランカ' => self::SriLanka->value,
            'ミャンマー' => self::Myanmar->value,
            'インドネシア' => self::Indonesia->value,
            'カンボジア' => self::Cambodia->value,
            'タイ' => self::Thailand->value,
            'パキスタン' => self::Pakistan->value,
            'バングラデシュ' => self::Bangladesh->value,
            'モンゴル' => self::Mongolia->value,
            'ラオス' => self::Laos->value,
            'インド' => self::India->value,
            'シンガポール' => self::Singapore->value,
            '韓国' => self::Korea->value,
            '中国' => self::China->value,
            '東ティモール' => self::EastTimor->value,
            'フィリピン' => self::Philippines->value,
            'ブータン' => self::Bhutan->value,
            'ブルネイ' => self::Brunei->value,
            'マレーシア' => self::Malaysia->value,
            'モルディブ' => self::Maldives->value,
            default => self::Other->value,
        };
    }
}
