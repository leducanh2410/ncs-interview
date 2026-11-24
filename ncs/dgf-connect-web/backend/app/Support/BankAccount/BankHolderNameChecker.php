<?php

namespace App\Support\BankAccount;

use App\Enums\NameCheckVerdict;
use App\Models\User;

class BankHolderNameChecker
{
    public const WARNING_KANA_UNAVAILABLE = 'kana_unavailable';

    /**
     * @return array{verdict: string, kana: array|null, latin: array|null, warnings: string[], ai: null}
     */
    public function check(User $user, ?string $holderKana, ?string $holderLatin): array
    {
        $warnings = [];
        $registeredKanaParts = $this->parts([$user->last_name_kana, $user->first_name_kana], 'kana');
        $registeredLatinParts = $this->parts([$user->last_name, $user->first_name], 'latin');
        $holderKanaParts = $this->parts([$holderKana], 'kana');
        $holderLatinParts = $this->parts([$holderLatin], 'latin');

        $kana = null;
        $kanaVerdict = null;
        if ($registeredKanaParts === []) {
            $warnings[] = self::WARNING_KANA_UNAVAILABLE;
        } elseif ($holderKanaParts !== []) {
            $kanaVerdict = $this->compareParts($registeredKanaParts, $holderKanaParts);
            $kana = [
                'registered' => implode(' ', $registeredKanaParts),
                'holder' => implode(' ', $holderKanaParts),
                'verdict' => $kanaVerdict->value,
            ];
        }

        $latin = null;
        $latinVerdict = null;
        if ($registeredLatinParts !== [] && $holderLatinParts !== []) {
            $latinVerdict = $this->compareParts($registeredLatinParts, $holderLatinParts);
            $latin = [
                'registered' => implode(' ', $registeredLatinParts),
                'holder' => implode(' ', $holderLatinParts),
                'verdict' => $latinVerdict->value,
            ];
        }

        return [
            'verdict' => $this->combine($kanaVerdict, $latinVerdict)->value,
            'kana' => $kana,
            'latin' => $latin,
            'warnings' => $warnings,
            'ai' => null,
        ];
    }

    /**
     * @param array<int, string|null> $values
     * @return string[]
     */
    private function parts(array $values, string $script): array
    {
        $parts = [];
        foreach ($values as $value) {
            $normalized =
                $script === 'kana'
                    ? BankAccountValueNormalizer::kana($value)
                    : BankAccountValueNormalizer::string($value);
            if ($normalized === null) {
                continue;
            }
            foreach (preg_split('/[\s・･]+/u', $normalized) ?: [] as $part) {
                $key =
                    $script === 'kana'
                        ? BankAccountValueNormalizer::kanaComparisonKey($part)
                        : BankAccountValueNormalizer::latinComparisonKey($part);
                if ($key !== null) {
                    $parts[] = $key;
                }
            }
        }

        return $parts;
    }

    /**
     * @param string[] $registered
     * @param string[] $holder
     */
    private function compareParts(array $registered, array $holder): NameCheckVerdict
    {
        $registeredSorted = $registered;
        $holderSorted = $holder;
        sort($registeredSorted);
        sort($holderSorted);
        if ($registeredSorted === $holderSorted) {
            return NameCheckVerdict::Match;
        }

        $registeredJoined = implode('', $registered);
        $holderJoined = implode('', $holder);
        if ($registeredJoined === $holderJoined || implode('', array_reverse($registered)) === $holderJoined) {
            return NameCheckVerdict::Match;
        }

        $missingFromHolder = array_diff($registered, $holder);
        $extraInHolder = array_diff($holder, $registered);
        if ($extraInHolder === [] && count($missingFromHolder) < count($registered)) {
            return NameCheckVerdict::Partial;
        }
        if ($missingFromHolder === [] && $extraInHolder !== []) {
            return NameCheckVerdict::Partial;
        }
        if (count($holder) === 1 && str_contains($registeredJoined, $holderJoined) && mb_strlen($holderJoined) >= 3) {
            return NameCheckVerdict::Partial;
        }

        return NameCheckVerdict::Mismatch;
    }

    private function combine(?NameCheckVerdict $kana, ?NameCheckVerdict $latin): NameCheckVerdict
    {
        $verdicts = array_filter([$kana, $latin]);
        if ($verdicts === []) {
            return NameCheckVerdict::Mismatch;
        }
        if (in_array(NameCheckVerdict::Match, $verdicts, true)) {
            return NameCheckVerdict::Match;
        }
        if (in_array(NameCheckVerdict::Partial, $verdicts, true)) {
            return NameCheckVerdict::Partial;
        }

        return NameCheckVerdict::Mismatch;
    }
}
