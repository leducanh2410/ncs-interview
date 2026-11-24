<?php

namespace App\Enums;

use Illuminate\Support\Facades\App;

enum Locale: string
{
    use EnumTrait;

    case Japanese = 'ja';
    case English = 'en';
    case ChineseSimplified = 'zh-CN';
    case ChineseTraditional = 'zh-TW';
    case Vietnamese = 'vi';
    case Tagalog = 'tl';
    case Portuguese = 'pt';
    case Spanish = 'es';
    case Thai = 'th';
    case Indonesian = 'id';

    public static function getTranslationLocales(): array
    {
        $defaultLocale = App::getLocale();
        $values = [];
        foreach (self::values() as $value) {
            if ($value !== $defaultLocale) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
