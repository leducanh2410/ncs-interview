<?php

namespace App\Actions\PostalCode;

use App\Enums\PostalCodeConfidence;
use App\Models\JapanPostalCode;
use App\Support\Address\JapaneseAddressNormalizer;
use App\Support\Address\ParsedJapaneseAddress;
use App\Support\Address\PostalCodeResolution;
use Illuminate\Support\Collection;

/**
 * Derives the postal code of an address from Japan Post data. Only an unambiguous
 * match yields a code; anything less is reported with candidates so a human decides.
 */
class ResolvePostalCodeFromAddressAction
{
    private const UNIT_CHOME = 'chome';

    private const UNIT_BANCHI = 'banchi';

    private const MAX_CANDIDATES = 10;

    public function __construct(private readonly ParseJapaneseAddressAction $parse) {}

    public function __invoke(string $address, ?string $prefectureHint = null): PostalCodeResolution
    {
        $parsed = ($this->parse)($address, $prefectureHint);
        if ($parsed->prefecture === null || $parsed->city === null) {
            return new PostalCodeResolution(null, PostalCodeConfidence::None, $parsed);
        }

        $cityRows = JapanPostalCode::query()
            ->where('prefecture', $parsed->prefecture)
            ->where('city_key', JapaneseAddressNormalizer::normalize($parsed->city));

        if ($parsed->town === null) {
            return $this->resolveWithoutTown(
                (clone $cityRows)->where('town_key', '')->orderBy('id')->get(),
                (clone $cityRows)->where('town_key', '!=', '')->exists(),
                $parsed
            );
        }

        $rows = $cityRows
            ->where(
                'town_key',
                JapaneseAddressNormalizer::normalize(JapaneseAddressNormalizer::stripAzaPrefix($parsed->town))
            )
            ->orderBy('id')
            ->get();

        return $this->pickAmong($rows, $parsed);
    }

    /**
     * @param Collection<int, JapanPostalCode> $cityLevelRows
     * @param bool $cityHasTowns whether Japan Post lists any town for the city at all
     */
    private function resolveWithoutTown(
        Collection $cityLevelRows,
        bool $cityHasTowns,
        ParsedJapaneseAddress $parsed
    ): PostalCodeResolution {
        // Villages covered by a single code ("〜一円") and cities where a 番地 follows the
        // city name directly have a genuine city-level code; the catch-all row does not.
        // In a city that does list towns, a remainder that is not a 番地 is a town we failed
        // to recognize (OCR misread, renamed area), so the city-level code is only a candidate.
        $wholeCity = $cityLevelRows->filter(fn(JapanPostalCode $row) => !$row->is_city_default);
        $remainderIsBanchi = preg_match('/^\d/u', $parsed->rest) === 1;
        if ($wholeCity->count() === 1 && ($remainderIsBanchi || !$cityHasTowns)) {
            return $this->exact($wholeCity->first(), $parsed);
        }

        $candidates = $this->candidates($cityLevelRows);

        return new PostalCodeResolution(
            null,
            $candidates === [] ? PostalCodeConfidence::None : PostalCodeConfidence::CityDefault,
            $parsed,
            $candidates
        );
    }

    /**
     * @param Collection<int, JapanPostalCode> $rows every code sharing the matched town
     */
    private function pickAmong(Collection $rows, ParsedJapaneseAddress $parsed): PostalCodeResolution
    {
        if ($rows->count() === 1) {
            return $this->exact($rows->first(), $parsed);
        }
        if ($rows->isEmpty()) {
            return new PostalCodeResolution(null, PostalCodeConfidence::None, $parsed);
        }
        // Several qualifiers can still map to one code, in which case the split is irrelevant
        if ($rows->pluck('postal_code')->unique()->count() === 1) {
            return $this->exact($rows->first(), $parsed);
        }

        // The town is split by 丁目 or 番地 ranges held in the parenthetical detail
        [$number, $subNumber, $unit] = $this->leadingNumber($parsed->rest);
        if ($number !== null) {
            $verdicts = $rows->map(
                fn(JapanPostalCode $row) => $this->detailCovers($row->town_detail, $number, $subNumber, $unit)
            );
            $hits = $rows->filter(fn(JapanPostalCode $row, int $key) => $verdicts[$key] === true);
            $undecided = $rows->filter(fn(JapanPostalCode $row, int $key) => $verdicts[$key] === null);

            // Only settle the split once every row could be judged; a row split by a unit we cannot
            // read (線, 階, ...) or by a sub-number the address does not state may still be the answer
            if ($undecided->isEmpty()) {
                if ($hits->count() === 1) {
                    return $this->exact($hits->first(), $parsed);
                }

                if ($hits->isEmpty()) {
                    $other = $rows->filter(
                        fn(JapanPostalCode $row) => JapaneseAddressNormalizer::normalize($row->town_detail) === 'その他'
                    );
                    if ($other->count() === 1) {
                        return $this->exact($other->first(), $parsed);
                    }
                }
            }
        }

        return new PostalCodeResolution(null, PostalCodeConfidence::Ambiguous, $parsed, $this->candidates($rows));
    }

    /**
     * @return array{0: ?int, 1: ?int, 2: ?string} the number the remainder starts with, its
     *                                            sub-number ("3667-3" -> 3) and its unit, if stated
     */
    private function leadingNumber(string $rest): array
    {
        if (!preg_match('/^(\d+)(?:[−\-](\d+))?(丁目|番地|番|号)?/u', $rest, $m)) {
            return [null, null, null];
        }

        $unit = match ($m[3] ?? '') {
            '丁目' => self::UNIT_CHOME,
            '番地', '番' => self::UNIT_BANCHI,
            default => null,
        };

        return [(int) $m[1], ($m[2] ?? '') !== '' ? (int) $m[2] : null, $unit];
    }

    /**
     * @return ?bool true/false when the detail's ranges decide the address; null when they
     *               cannot be judged (unit we do not read, or a sub-number the address lacks)
     */
    private function detailCovers(?string $detail, int $number, ?int $subNumber, ?string $addressUnit): ?bool
    {
        $detail = JapaneseAddressNormalizer::normalize($detail);
        // "その他", "次のビルを除く": nothing numeric to compare against
        if ($detail === '' || !preg_match('/\d/', $detail)) {
            return false;
        }

        $detailUnit = match (true) {
            str_ends_with($detail, '丁目') => self::UNIT_CHOME,
            str_ends_with($detail, '番地'), str_ends_with($detail, '番') => self::UNIT_BANCHI,
            default => null,
        };
        if ($detailUnit === null) {
            return null;
        }
        if ($addressUnit !== null && $addressUnit !== $detailUnit) {
            return false;
        }

        // Ranges read "A", "A〜B", "A−a" (one 番地 with a sub-number) or "A−a〜B−b"
        preg_match_all('/(\d+)(?:[−\-](\d+))?(?:[〜~～](\d+)(?:[−\-](\d+))?)?/u', $detail, $ranges, PREG_SET_ORDER);
        $undecided = false;
        foreach ($ranges as $range) {
            $from = (int) $range[1];
            $fromSub = ($range[2] ?? '') !== '' ? (int) $range[2] : null;
            $hasTo = ($range[3] ?? '') !== '';
            $to = $hasTo ? (int) $range[3] : $from;
            $toSub = $hasTo ? (($range[4] ?? '') !== '' ? (int) $range[4] : null) : $fromSub;

            if ($number < $from || $number > $to) {
                continue;
            }
            if ($fromSub === null && $toSub === null) {
                return true;
            }
            if ($subNumber === null) {
                $undecided = true;

                continue;
            }
            $aboveFrom = $number > $from || $subNumber >= ($fromSub ?? 0);
            $belowTo = $number < $to || $toSub === null || $subNumber <= $toSub;
            if ($aboveFrom && $belowTo) {
                return true;
            }
        }

        return $undecided ? null : false;
    }

    private function exact(JapanPostalCode $row, ParsedJapaneseAddress $parsed): PostalCodeResolution
    {
        return new PostalCodeResolution($row->postal_code, PostalCodeConfidence::Exact, $parsed, [$row->toCandidate()]);
    }

    /**
     * @param Collection<int, JapanPostalCode> $rows
     */
    private function candidates(Collection $rows): array
    {
        return $rows->take(self::MAX_CANDIDATES)->map(fn(JapanPostalCode $row) => $row->toCandidate())->values()->all();
    }
}
