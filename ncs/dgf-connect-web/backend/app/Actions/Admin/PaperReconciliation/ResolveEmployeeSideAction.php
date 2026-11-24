<?php

namespace App\Actions\Admin\PaperReconciliation;

use App\Enums\UserPaperType;
use App\Models\User;
use App\Models\UserPaperItem;
use App\Support\Papers\CurrentPaperItemSelector;
use App\Support\Residence\ResidenceSlotResolver;
use DateTimeInterface;

/**
 * The employee side of the comparison, for one or many users at once.
 *
 * Two unrelated things resolved together because every row needs both: the profile fields
 * the verdict is computed from, and the employee's own scan image the popup displays.
 *
 * No user_paper_ocr_results row is read anywhere in here. The employee side is the record
 * the company acts on, not a second machine reading of an image.
 */
class ResolveEmployeeSideAction
{
    public function __construct(private readonly CurrentPaperItemSelector $currentItems) {}

    /**
     * @param int[] $userIds
     * @return array<int, array{fields: array<string, mixed>, residence_missing: bool,
     *         cards: array<int, array{number: ?string, expiry: ?string, status: ?string}>,
     *         current_slot: ?int, scan_item: ?UserPaperItem}>
     */
    public function __invoke(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $profiles = $this->profiles($userIds);
        $scans = $this->scanItems($userIds);

        $resolved = [];
        foreach ($userIds as $userId) {
            $profile = $profiles[$userId] ?? null;
            $cards = $this->cards($profile);
            $slot = ResidenceSlotResolver::latestSlotFromCards($cards);
            $residenceMissing = $this->residenceMissing($cards);

            $resolved[$userId] = [
                'fields' => $this->fields($profile, $cards, $slot),
                'residence_missing' => $residenceMissing,
                'cards' => $cards,
                'current_slot' => $residenceMissing ? null : $slot,
                'scan_item' => $scans[$userId] ?? null,
            ];
        }

        return $resolved;
    }

    /**
     * One query joining users to user_infos, so the per-row cost does not grow with N.
     *
     * @param int[] $userIds
     * @return array<int, object>
     */
    private function profiles(array $userIds): array
    {
        return User::query()
            ->leftJoin('user_infos', 'user_infos.user_id', '=', 'users.id')
            ->whereIn('users.id', $userIds)
            ->get([
                'users.id',
                'users.last_name',
                'users.first_name',
                'users.last_name_kana',
                'users.first_name_kana',
                'user_infos.birthday',
                'user_infos.gender',
                'user_infos.nationality',
                'user_infos.address',
                'user_infos.prefecture',
                'user_infos.residence_number_1',
                'user_infos.residence_expiry_date_1',
                'user_infos.residence_status_1',
                'user_infos.residence_number_2',
                'user_infos.residence_expiry_date_2',
                'user_infos.residence_status_2',
                'user_infos.residence_number_3',
                'user_infos.residence_expiry_date_3',
                'user_infos.residence_status_3',
            ])
            ->keyBy('id')
            ->all();
    }

    /**
     * The image the popup shows. It affects no verdict: it is there for the reviewer's
     * own eyes, and the Current_Item_Rule is shared so this screen and the employee
     * detail page never display different pictures for the same person.
     *
     * @param int[] $userIds
     * @return array<int, UserPaperItem>
     */
    private function scanItems(array $userIds): array
    {
        $type = UserPaperType::ForeignerCardScan->value;

        $items = UserPaperItem::query()
            ->join('user_papers', 'user_papers.id', '=', 'user_paper_items.user_paper_id')
            ->whereIn('user_papers.user_id', $userIds)
            ->where('user_paper_items.type', $type)
            ->whereNull('user_paper_items.deleted_at')
            ->orderByDesc('user_paper_items.id')
            ->select('user_paper_items.*', 'user_papers.user_id as owner_id')
            ->get()
            ->groupBy('owner_id');

        $resolved = [];
        foreach ($items as $ownerId => $ofUser) {
            $chosen = ($this->currentItems)(collect($ofUser->all()), [$type])->get($type);
            if ($chosen) {
                $resolved[(int) $ownerId] = $chosen;
            }
        }

        return $resolved;
    }

    /**
     * @return array<int, array{number: ?string, expiry: ?string, status: ?string}> keyed by slot 1..3
     */
    private function cards(?object $profile): array
    {
        $cards = [];

        for ($slot = 1; $slot <= ResidenceSlotResolver::SLOT_COUNT; $slot++) {
            $cards[$slot] = [
                'number' => $profile->{"residence_number_{$slot}"} ?? null,
                'expiry' => $this->asDate($profile->{"residence_expiry_date_{$slot}"} ?? null),
                'status' => $profile->{"residence_status_{$slot}"} ?? null,
            ];
        }

        return $cards;
    }

    /**
     * @param array<int, array{number: ?string, expiry: ?string, status: ?string}> $cards
     */
    private function residenceMissing(array $cards): bool
    {
        foreach ($cards as $card) {
            if (!empty($card['number']) || !empty($card['expiry']) || !empty($card['status'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The profile as stored. An absent residence card blanks the three residence fields
     * and nothing else: name, birthday, gender and nationality still compare, so a scanned
     * card bearing somebody else's name is caught on exactly the employees most likely to
     * have a problem.
     *
     * @param array<int, array{number: ?string, expiry: ?string, status: ?string}> $cards
     * @return array<string, mixed>
     */
    private function fields(?object $profile, array $cards, ?int $slot): array
    {
        $card = $slot === null ? ['number' => null, 'expiry' => null, 'status' => null] : $cards[$slot];

        $name = mb_trim(($profile->last_name ?? '') . ' ' . ($profile->first_name ?? ''));

        return [
            'name' => $name === '' ? null : $name,
            'last_name_kana' => $profile->last_name_kana ?? null,
            'first_name_kana' => $profile->first_name_kana ?? null,
            'birthday' => $this->asDate($profile->birthday ?? null),
            'gender' => $profile->gender ?? null,
            'nationality' => $profile->nationality ?? null,
            'address' => $profile->address ?? null,
            'prefecture' => $profile->prefecture ?? null,
            'residence_number' => $card['number'],
            'residence_expiry_date' => $card['expiry'],
            'residence_status' => $card['status'],
            // The profile never records the wording printed on the card, only the mapped
            // status. The admin side carries it; this side has nothing to show.
            'residence_status_raw' => null,
        ];
    }

    /**
     * Date columns are compared as Y-m-d strings on both sides. UserInfo declares no casts
     * today, so this is a no-op in practice — it keeps the comparison correct if one is
     * ever added.
     */
    private function asDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return mb_substr((string) $value, 0, 10);
    }
}
