<?php

namespace App\Support\Residence;

use App\Enums\ResidenceStatus;
use App\Models\UserInfo;
use Carbon\Carbon;
use Throwable;

/**
 * Single source of truth for the residence card slots (1..3) on user_infos.
 *
 * Reading ("which card is the current one") picks the slot with the latest
 * expiry date. Writing ("where does a newly confirmed card go") picks the
 * first empty slot and rotates the history when all slots are taken.
 */
class ResidenceSlotResolver
{
    public const SLOT_COUNT = 3;

    /** Whitespace ignored when comparing card numbers: ASCII blanks, NBSP and the full-width space. */
    public const NUMBER_WHITESPACE = [' ', "\t", "\n", "\r", "\u{00A0}", "\u{3000}"];

    /**
     * Slot holding the current card: latest expiry date wins (later slot wins ties),
     * falling back to the first slot with a status, then the first slot with any data.
     */
    public static function latestSlot(?UserInfo $info): ?int
    {
        if (!$info) {
            return null;
        }

        return self::latestSlotFromCards(self::cardsFromInfo($info));
    }

    public static function latestIsStudyAbroad(?UserInfo $info): bool
    {
        return self::latestStatus($info) === ResidenceStatus::StudyAbroad->value;
    }

    /**
     * Residence status of the current card, or null when no card carries one.
     */
    public static function latestStatus(?UserInfo $info): ?string
    {
        if (!$info) {
            return null;
        }

        $cards = self::cardsFromInfo($info);
        $slot = self::latestSlotFromCards($cards);

        return $slot === null ? null : $cards[$slot]['status'];
    }

    /**
     * @param array<int, array{number: ?string, expiry: ?string, status: ?string}> $cards keyed by slot 1..3
     */
    public static function latestSlotFromCards(array $cards): ?int
    {
        $latestSlot = null;
        $latestDate = null;

        foreach ($cards as $slot => $card) {
            $date = self::parseDate($card['expiry']);
            if ($date === null) {
                continue;
            }

            if ($latestDate === null || $date->gte($latestDate)) {
                $latestDate = $date;
                $latestSlot = $slot;
            }
        }

        if ($latestSlot !== null) {
            return $latestSlot;
        }

        foreach ($cards as $slot => $card) {
            if (!empty($card['status'])) {
                return $slot;
            }
        }

        foreach ($cards as $slot => $card) {
            if (!empty($card['number']) || !empty($card['expiry'])) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Same card number goes back into its own slot; only a new number takes a fresh slot.
     *
     * When the number sits in several slots (the old flow took a new slot on every
     * re-confirm), the extra copies are dropped and the remaining cards packed from
     * slot 1, so a stale copy can no longer outrank the confirmed values as the
     * current card. Mutates $info in memory only — the caller persists the final values.
     */
    public static function prepareSlotForCard(UserInfo $info, ?string $number): int
    {
        $slots = self::slotsHoldingNumber($info, $number);
        if ($slots === []) {
            return self::prepareSlotForWrite($info);
        }

        $keep = $slots[0];
        if (count($slots) === 1) {
            return $keep;
        }

        ['cards' => $packed, 'slot' => $keptSlot] = self::packCards(
            self::cardsFromInfo($info),
            array_slice($slots, 1),
            $keep
        );
        self::writeCards($info, $packed);

        return $keptSlot;
    }

    public static function collapseIdenticalCopies(UserInfo $info, array $slots): void
    {
        if (count($slots) < 2) {
            return;
        }

        $cards = self::cardsFromInfo($info);
        $first = $cards[$slots[0]];
        foreach ($slots as $slot) {
            $card = $cards[$slot];
            if (
                self::normalizeNumber($card['number']) !== self::normalizeNumber($first['number']) ||
                $card['expiry'] !== $first['expiry'] ||
                $card['status'] !== $first['status']
            ) {
                return;
            }
        }

        ['cards' => $packed] = self::packCards($cards, array_slice($slots, 1));
        self::writeCards($info, $packed);
    }

    /**
     * Slots already holding this number (case-insensitive, whitespace ignored), lowest first.
     *
     * @return list<int>
     */
    public static function slotsHoldingNumber(UserInfo $info, ?string $number): array
    {
        $needle = self::normalizeNumber($number);
        if ($needle === null) {
            return [];
        }

        $slots = [];
        foreach (self::cardsFromInfo($info) as $slot => $card) {
            if (self::normalizeNumber($card['number']) === $needle) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * Cards packed from slot 1 in their original order, without the dropped slots and
     * without empty slots. `slot` is where $track ended up (null when not tracked or dropped).
     *
     * @param array<int, array{number: ?string, expiry: ?string, status: ?string}> $cards keyed by slot 1..3
     * @param list<int> $drop
     * @return array{cards: array<int, array{number: ?string, expiry: ?string, status: ?string}>, slot: ?int}
     */
    public static function packCards(array $cards, array $drop = [], ?int $track = null): array
    {
        $drop = array_flip($drop);
        $kept = [];
        $trackedSlot = null;
        foreach ($cards as $slot => $card) {
            if (isset($drop[$slot]) || self::isEmptyCard($card)) {
                continue;
            }
            $kept[] = $card;
            if ($slot === $track) {
                $trackedSlot = count($kept);
            }
        }

        $packed = [];
        for ($slot = 1; $slot <= self::SLOT_COUNT; $slot++) {
            $packed[$slot] = $kept[$slot - 1] ?? ['number' => null, 'expiry' => null, 'status' => null];
        }

        return ['cards' => $packed, 'slot' => $trackedSlot];
    }

    /**
     * @param array<int, array{number: ?string, expiry: ?string, status: ?string}> $cards keyed by slot 1..3
     */
    public static function writeCards(UserInfo $info, array $cards): void
    {
        foreach ($cards as $slot => $card) {
            $info->{"residence_number_{$slot}"} = $card['number'];
            $info->{"residence_expiry_date_{$slot}"} = $card['expiry'];
            $info->{"residence_status_{$slot}"} = $card['status'];
        }
    }

    /**
     * @param array{number: ?string, expiry: ?string, status: ?string} $card
     */
    public static function isEmptyCard(array $card): bool
    {
        return empty($card['number']) && empty($card['expiry']) && empty($card['status']);
    }

    /**
     * Case and whitespace never distinguish two card numbers. The whitespace set is
     * spelled out (rather than `\s`) so that normalizedNumberSql() strips exactly the
     * same characters and a DB query agrees with a PHP comparison.
     */
    public static function normalizeNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $normalized = mb_strtoupper(str_replace(self::NUMBER_WHITESPACE, '', $number));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * SQL expression yielding normalizeNumber() of a column, for matching numbers in a query.
     */
    public static function normalizedNumberSql(string $column): string
    {
        $expression = $column;
        foreach (self::NUMBER_WHITESPACE as $whitespace) {
            $expression = "REPLACE({$expression}, '{$whitespace}', '')";
        }

        return "UPPER({$expression})";
    }

    /**
     * Slot a newly confirmed card must be written to: the first empty slot,
     * or slot 3 after rotating the history (slot 1 data is dropped, 1←2, 2←3).
     * Mutates $info in memory only — the caller persists the final values.
     */
    public static function prepareSlotForWrite(UserInfo $info): int
    {
        $cards = self::cardsFromInfo($info);

        foreach ($cards as $slot => $card) {
            if (self::isEmptyCard($card)) {
                return $slot;
            }
        }

        for ($slot = 1; $slot < self::SLOT_COUNT; $slot++) {
            $next = $slot + 1;
            $info->{"residence_number_{$slot}"} = $info->{"residence_number_{$next}"};
            $info->{"residence_expiry_date_{$slot}"} = $info->{"residence_expiry_date_{$next}"};
            $info->{"residence_status_{$slot}"} = $info->{"residence_status_{$next}"};
        }

        $info->{'residence_number_' . self::SLOT_COUNT} = null;
        $info->{'residence_expiry_date_' . self::SLOT_COUNT} = null;
        $info->{'residence_status_' . self::SLOT_COUNT} = null;

        return self::SLOT_COUNT;
    }

    /**
     * @return array<int, array{number: ?string, expiry: ?string, status: ?string}>
     */
    public static function cardsFromInfo(UserInfo $info): array
    {
        $cards = [];
        for ($slot = 1; $slot <= self::SLOT_COUNT; $slot++) {
            $cards[$slot] = [
                'number' => $info->{"residence_number_{$slot}"},
                'expiry' => $info->{"residence_expiry_date_{$slot}"},
                'status' => $info->{"residence_status_{$slot}"},
            ];
        }

        return $cards;
    }

    private static function parseDate(?string $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
