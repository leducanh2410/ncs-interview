<?php

namespace App\Actions\PostalCode;

use App\Actions\Ocr\ExtractJapanesePrefectureAction;
use App\Models\JapanPostalCode;
use App\Support\Address\JapaneseAddressNormalizer;
use App\Support\Address\ParsedJapaneseAddress;

/**
 * Splits a free-text Japanese address into the parts Japan Post data knows about,
 * by longest-prefix matching each level against the imported rows.
 */
class ParseJapaneseAddressAction
{
    public function __construct(private readonly ExtractJapanesePrefectureAction $extractPrefecture) {}

    /**
     * @param ?string $prefectureHint used when the address itself does not start with a prefecture
     */
    public function __invoke(string $address, ?string $prefectureHint = null): ParsedJapaneseAddress
    {
        $normalized = JapaneseAddressNormalizer::normalize($address);

        $prefecture = ($this->extractPrefecture)(null, $normalized);
        if ($prefecture !== null) {
            $remainder = $this->after($normalized, $prefecture);
        } else {
            $prefecture = ($this->extractPrefecture)($prefectureHint, null);
            $remainder = $normalized;
        }

        if ($prefecture === null) {
            return new ParsedJapaneseAddress(null, null, null, $normalized);
        }

        $cities = JapanPostalCode::query()->where('prefecture', $prefecture)->distinct()->pluck('city', 'city_key');
        [$cityKey, $city] = $this->longestPrefix($remainder, $cities->all());
        if ($cityKey === null) {
            return new ParsedJapaneseAddress($prefecture, null, null, $remainder);
        }
        $remainder = $this->after($remainder, $cityKey);

        $towns = JapanPostalCode::query()
            ->where('prefecture', $prefecture)
            ->where('city_key', $cityKey)
            ->where('town_key', '!=', '')
            ->distinct()
            ->pluck('town', 'town_key');
        $townSubject = JapaneseAddressNormalizer::stripAzaPrefix($remainder);
        [$townKey, $town] = $this->longestPrefix($townSubject, $towns->all());
        if ($townKey === null) {
            return new ParsedJapaneseAddress($prefecture, $city, null, $remainder);
        }

        return new ParsedJapaneseAddress($prefecture, $city, $town, $this->after($townSubject, $townKey));
    }

    /**
     * @param array<string, string> $candidates normalized key => display text
     * @return array{0: ?string, 1: ?string} the matching key and its display text
     */
    private function longestPrefix(string $subject, array $candidates): array
    {
        $bestKey = null;
        $bestLength = 0;
        foreach ($candidates as $key => $display) {
            $key = (string) $key;
            $length = mb_strlen($key);
            if ($length <= $bestLength || $key === '' || !str_starts_with($subject, $key)) {
                continue;
            }
            $bestKey = $key;
            $bestLength = $length;
        }

        return $bestKey === null ? [null, null] : [$bestKey, $candidates[$bestKey]];
    }

    private function after(string $subject, string $prefix): string
    {
        $position = mb_strpos($subject, $prefix);
        if ($position === false) {
            return $subject;
        }

        return mb_substr($subject, $position + mb_strlen($prefix));
    }
}
