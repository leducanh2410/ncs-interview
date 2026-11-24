<?php

namespace App\Actions\Chat\Keyword;

class FilterChatBodyAction
{
    public function __construct(private readonly LoadBannedKeywordsAction $load) {}

    public function __invoke(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        $keywords = ($this->load)()['mask'];

        if ($keywords === []) {
            return $body;
        }

        $chars = mb_str_split($body);
        $length = count($chars);

        // Flag every character position covered by ANY keyword first, then collapse the covered
        // runs into '***'. Masking keyword-by-keyword on a progressively rewritten body would let
        // overlapping keywords leave sensitive fragments exposed: e.g. with "abc" and "bcd",
        // masking "abc" in "abcd" yields "***d", after which "bcd" no longer matches and "d" leaks.
        //
        // Substring match (no word boundaries): Japanese and Vietnamese don't delimit words with
        // spaces, so boundary anchors would silently skip those locales. Matching is done on
        // character indices so multibyte text is masked precisely.
        $covered = array_fill(0, $length, false);
        $anyMatch = false;

        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            $keywordLength = mb_strlen($keyword);
            $offset = 0;

            while (($position = mb_stripos($body, $keyword, $offset)) !== false) {
                $anyMatch = true;
                for ($i = $position; $i < $position + $keywordLength; $i++) {
                    $covered[$i] = true;
                }
                // Advance by one so overlapping occurrences are still found.
                $offset = $position + 1;
            }
        }

        if (!$anyMatch) {
            return $body;
        }

        $result = '';
        $i = 0;
        while ($i < $length) {
            if (!$covered[$i]) {
                $result .= $chars[$i];
                $i++;

                continue;
            }

            $result .= '***';
            while ($i < $length && $covered[$i]) {
                $i++;
            }
        }

        return $result;
    }

    /**
     * Return the distinct mask keywords present in the body (definition order preserved). Empty
     * when nothing was masked. Callers surface these on send so the sender understands why their
     * text became '***'.
     *
     * @return list<string>
     */
    public function matchedKeywords(?string $body): array
    {
        if ($body === null || $body === '') {
            return [];
        }

        $keywords = ($this->load)()['mask'];
        $matched = [];

        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            if (mb_stripos($body, $keyword) !== false && !in_array($keyword, $matched, true)) {
                $matched[] = $keyword;
            }
        }

        return $matched;
    }
}
