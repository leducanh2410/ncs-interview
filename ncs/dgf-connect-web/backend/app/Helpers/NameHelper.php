<?php

namespace App\Helpers;

class NameHelper
{
    /**
     * Tokens that mark the end of a Sinhalese "ge name" (ancestral/house name)
     * block. The house block leads the full name and ends at one of these.
     */
    private const HOUSE_MARKERS = [
        'MUDIYANSELAGE',
        'MUDIYANSALAGE',
        'MIDIYANSELAGE',
        'ARACHCHIGE',
        'ARACHCHILAGE',
        'PATABENDIGE',
        'VIDANALAGE',
        'VIDANELAGE',
        'WALAWUWE',
        'WALAWWE',
        'WALAUWWE',
        'WASALA',
        'GEDARA',
        'RALALAGE',
        'RALAGE',
        'BEHETHGE',
        'BANDARALAGE',
    ];

    /**
     * Common Western names that happen to end in "-ge"/"-lage" but are not
     * Sinhalese house-name markers. Excluded from the generic suffix
     * heuristic in isHouseMarker() so a calling/family name like "George" or
     * "Savage" isn't mistaken for a house-name block. Not exhaustive.
     */
    private const GE_SUFFIX_EXCLUSIONS = ['GEORGE', 'SAVAGE'];

    /**
     * Honorifics/titles that sit between the house block and the actual calling
     * name. They are skipped so the calling name is picked, e.g.
     * "... ARACHCHIGE DON ESHINI ..." -> "Eshini ...".
     */
    private const TITLES = ['DON', 'MUHANDIRAM', 'APPUHAMY', 'APPUHAMI'];

    /**
     * Shorten a (typically very long) Sri Lankan full name to the person's two
     * given names, uppercased for consistency with the rest of the system's
     * name display, e.g.:
     *   "Karnasooriya Ragalage Tharindu Ravishann Perera" -> "THARINDU RAVISHANN"
     *   "CHANDRASEKARA JAYARATHNA MUDIYANSELAGE THARINDU DANANJAY ..." -> "THARINDU DANANJAY"
     *   "Lokuruge Deshan" -> "DESHAN LOKURUGE"
     *   "Aroshan Manujaya" -> "AROSHAN MANUJAYA"
     *
     * Strategy: collapse the leading house-name block, then keep the first two
     * given names in full. When only a single given name remains, fall back to
     * appending the family name (the first house token, or the trailing given
     * token when no house block is detected).
     */
    public static function shortenSriLankaName(string $fullName): string
    {
        $tokens = preg_split('/\s+/', mb_trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($tokens) === 0) {
            return '';
        }

        if (count($tokens) === 1) {
            return mb_strtoupper($tokens[0]);
        }

        [$house, $given] = self::splitHouseAndGiven($tokens);

        $given = self::dropLeadingTitles($given);

        // When the block after the house name is only a title (e.g. the name was
        // entered as "<calling name> ... <house> DON"), the calling name sits
        // before the house block, so fall back to the leading tokens.
        if (self::isTitle($given[0]) && count($house) > 0) {
            $given = self::dropLeadingTitles($house);
            $house = [];
        }

        $callingName = mb_strtoupper($given[0]);

        // Prefer the person's second given name (e.g. "Tharindu Ravishann").
        $secondGiven = self::secondGivenName($given);

        if ($secondGiven !== null) {
            return $callingName . ' ' . mb_strtoupper($secondGiven);
        }

        // Only one given name: fall back to the family/house name.
        $familyToken = self::pickFamilyToken($tokens, $given, $house);

        if ($familyToken === null) {
            return $callingName;
        }

        return $callingName . ' ' . mb_strtoupper($familyToken);
    }

    /**
     * The given name that follows the calling name, skipping honorific titles
     * and any repeat of the calling name. Returns null when the name carries a
     * single given name.
     *
     * @param  array<int, string>  $given
     */
    private static function secondGivenName(array $given): ?string
    {
        $callingUpper = mb_strtoupper($given[0]);

        foreach (array_slice($given, 1) as $token) {
            if (self::isTitle($token) || mb_strtoupper($token) === $callingUpper) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * Choose the family-name token, used only when the name carries a single
     * given name. A token that occurs more than once in the full name is almost
     * always the official surname (it is written at both ends), so it wins.
     * Otherwise fall back to the first house token, then the trailing given
     * token.
     *
     * @param  array<int, string>  $tokens  all tokens of the full name
     * @param  array<int, string>  $given   resolved given-name tokens
     * @param  array<int, string>  $house   resolved house-name tokens
     */
    private static function pickFamilyToken(array $tokens, array $given, array $house): ?string
    {
        $callingUpper = mb_strtoupper($given[0]);

        $counts = array_count_values(array_map('mb_strtoupper', $tokens));

        foreach ($tokens as $token) {
            $upper = mb_strtoupper($token);

            if ($upper === $callingUpper || self::isTitle($token) || mb_strlen($upper) <= 2) {
                continue;
            }

            if (($counts[$upper] ?? 0) >= 2) {
                return $token;
            }
        }

        $fallback = $house[0] ?? ($given[count($given) - 1] ?? null);

        return $fallback === null || mb_strtoupper($fallback) === $callingUpper ? null : $fallback;
    }

    /**
     * Split tokens into [houseBlock, givenNames] using the last house marker as
     * the boundary. Returns [[], $tokens] when no house block is detected.
     *
     * @param  array<int, string>  $tokens
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function splitHouseAndGiven(array $tokens): array
    {
        $boundary = -1;

        foreach ($tokens as $index => $token) {
            if (self::isHouseMarker($token)) {
                $boundary = $index;
            }
        }

        if ($boundary >= 0 && $boundary < count($tokens) - 1) {
            return [array_slice($tokens, 0, $boundary + 1), array_slice($tokens, $boundary + 1)];
        }

        return [[], $tokens];
    }

    /**
     * Drop leading honorific tokens, keeping at least one token so a name made
     * up solely of a title still yields a value.
     *
     * @param  array<int, string>  $given
     * @return array<int, string>
     */
    private static function dropLeadingTitles(array $given): array
    {
        while (count($given) > 1 && self::isTitle($given[0])) {
            array_shift($given);
        }

        return array_values($given);
    }

    private static function isTitle(string $token): bool
    {
        return in_array(mb_strtoupper($token), self::TITLES, true);
    }

    private static function isHouseMarker(string $token): bool
    {
        $upper = mb_strtoupper($token);

        if (in_array($upper, self::HOUSE_MARKERS, true)) {
            return true;
        }

        if (in_array($upper, self::GE_SUFFIX_EXCLUSIONS, true)) {
            return false;
        }

        // Generic Sinhalese genitive endings, e.g. "Lokuruge", "Bandaralage".
        return str_ends_with($upper, 'LAGE') || (str_ends_with($upper, 'GE') && mb_strlen($upper) > 4);
    }
}
