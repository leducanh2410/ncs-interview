<?php

namespace App\Actions\User\Document;

use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Models\UserInfo;
use App\Models\UserPaperStep;
use App\Support\Residence\ResidenceSlotResolver;

/**
 * Collapses residence slots holding the same card number more than once
 * (re-confirming after a rejection used to take a new slot instead of overwriting).
 *
 * Identical copies simply fold into one. Copies that disagree on expiry date or
 * status are only folded when the employee's confirmed residence-card step names
 * the copy to keep: after the fix that confirmation would have overwritten the
 * card's slot, so it is the state the flow was meant to leave behind. Without it
 * the group is left untouched and reported, since keeping the latest expiry could
 * throw away a correction made after a rejection.
 */
class CollapseDuplicateResidenceSlotsAction
{
    /**
     * @return array{cards: array<int, array{number: ?string, expiry: ?string, status: ?string}>, unresolved: list<string>}
     *   `cards` keyed by slot 1..3: the packed slots, or the original slots when `unresolved` lists card
     *   numbers whose copies disagree and no confirmation picks the one to keep.
     */
    public function __invoke(UserInfo $info): array
    {
        $cards = ResidenceSlotResolver::cardsFromInfo($info);

        $groups = [];
        foreach ($cards as $slot => $card) {
            $key = ResidenceSlotResolver::normalizeNumber($card['number']);
            if ($key !== null) {
                $groups[$key][$slot] = $card;
            }
        }

        $confirmed = null;
        $confirmedLoaded = false;
        $drop = [];
        $unresolved = [];
        foreach ($groups as $key => $members) {
            if (count($members) < 2) {
                continue;
            }

            $candidates = $members;
            if (!$this->allIdentical($members)) {
                if (!$confirmedLoaded) {
                    $confirmed = $this->confirmedCard($info);
                    $confirmedLoaded = true;
                }
                $candidates = $this->matchingConfirmed($members, $key, $confirmed);
                if ($candidates === []) {
                    $unresolved[] = $members[array_key_first($members)]['number'];

                    continue;
                }
            }

            $keep = ResidenceSlotResolver::latestSlotFromCards($candidates) ?? array_key_last($candidates);
            foreach (array_keys($members) as $slot) {
                if ($slot !== $keep) {
                    $drop[$slot] = true;
                }
            }
        }

        if ($unresolved !== []) {
            return ['cards' => $cards, 'unresolved' => $unresolved];
        }

        ['cards' => $packed] = ResidenceSlotResolver::packCards($cards, array_keys($drop));

        return ['cards' => $packed, 'unresolved' => []];
    }

    /**
     * @param array<int, array{number: ?string, expiry: ?string, status: ?string}> $members
     */
    private function allIdentical(array $members): bool
    {
        $first = $members[array_key_first($members)];
        foreach ($members as $card) {
            if ($card['expiry'] !== $first['expiry'] || $card['status'] !== $first['status']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Members carrying the expiry date and status the employee last confirmed for this number.
     *
     * @param array<int, array{number: ?string, expiry: ?string, status: ?string}> $members
     * @param array{number: ?string, expiry: ?string, status: ?string}|null $confirmed
     * @return array<int, array{number: ?string, expiry: ?string, status: ?string}>
     */
    private function matchingConfirmed(array $members, string $key, ?array $confirmed): array
    {
        if ($confirmed === null || ResidenceSlotResolver::normalizeNumber($confirmed['number']) !== $key) {
            return [];
        }

        return array_filter(
            $members,
            fn(array $card) => $card['expiry'] === $confirmed['expiry'] && $card['status'] === $confirmed['status']
        );
    }

    /**
     * The card fields of the employee's residence-card step while its confirmation stands
     * (a rejection clears them, so a rejected confirmation never picks the keeper).
     *
     * @return array{number: ?string, expiry: ?string, status: ?string}|null
     */
    private function confirmedCard(UserInfo $info): ?array
    {
        $fields = UserPaperStep::query()
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $info->user_id))
            ->where('step_key', UserPaperStepKey::ResidenceCard->value)
            ->where('status', UserPaperStepStatus::Confirmed->value)
            ->value('confirmed_fields');
        if ($fields === null) {
            return null;
        }

        $fields = is_string($fields) ? json_decode($fields, true) : $fields;
        if (!is_array($fields) || empty($fields['residence_number'])) {
            return null;
        }

        return [
            'number' => $fields['residence_number'],
            'expiry' => $fields['residence_expiry_date'] ?? null,
            'status' => $fields['residence_status'] ?? null,
        ];
    }
}
