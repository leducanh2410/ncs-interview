<?php

namespace App\Models\Traits;

use Transliterator;

trait NormalizesDiacritics
{
    protected function removeDiacritics(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Handle đ/Đ separately — they are modified letters, not decomposable diacritics
        $value = str_replace(['đ', 'Đ'], ['d', 'D'], $value);

        $transliterator = Transliterator::createFromRules(':: [:Latin:] NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');

        return $transliterator->transliterate($value);
    }
}
