<?php

namespace App\Actions\Chat\Keyword;

class CheckMessageBlockedAction
{
    public function __construct(private readonly LoadBannedKeywordsAction $load) {}

    public function __invoke(string $body): bool
    {
        return $this->matches($body) !== [];
    }

    /**
     * Return the distinct block keywords found in the body (order of definition preserved). Empty
     * when nothing matches. Callers surface these to the sender so they know exactly which term
     * blocked the message.
     *
     * @return list<string>
     */
    public function matches(string $body): array
    {
        $keywords = ($this->load)()['block'];

        if ($keywords === []) {
            return [];
        }

        $matched = [];

        // Intentional substring match (no word boundaries): Japanese and Vietnamese don't
        // delimit words with spaces, so boundary anchors would silently skip those locales.
        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            $pattern = '/' . preg_quote($keyword, '/') . '/iu';
            if (preg_match($pattern, $body) && !in_array($keyword, $matched, true)) {
                $matched[] = $keyword;
            }
        }

        return $matched;
    }
}
