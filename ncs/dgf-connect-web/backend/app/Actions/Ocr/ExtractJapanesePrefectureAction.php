<?php

namespace App\Actions\Ocr;

use App\Enums\Prefecture;

class ExtractJapanesePrefectureAction
{
    public function __invoke(?string $prefecture, ?string $address): ?string
    {
        if ($prefecture !== null) {
            $canonical = Prefecture::findByAlias($prefecture);
            if ($canonical !== null) {
                return Prefecture::from($canonical)->japaneseName();
            }
        }

        if ($address === null) {
            return null;
        }

        // OCR sometimes inserts spaces between Japanese characters. Removing them makes
        // an otherwise exact prefecture name recoverable without changing the full address.
        $compactAddress = preg_replace('/[\s　]+/u', '', $address);
        if (!is_string($compactAddress) || $compactAddress === '') {
            return null;
        }

        $bestMatch = null;
        $bestPosition = null;
        foreach (Prefecture::japaneseNames() as $japaneseName) {
            $position = mb_strpos($compactAddress, $japaneseName);
            if ($position === false) {
                continue;
            }

            // Japanese addresses normally put the prefecture first. Choosing the earliest
            // match also avoids treating the 京都府 substring in 東京都府中市 as Kyoto.
            if ($bestPosition === null || $position < $bestPosition) {
                $bestMatch = $japaneseName;
                $bestPosition = $position;
            }
        }

        return $bestMatch;
    }
}
