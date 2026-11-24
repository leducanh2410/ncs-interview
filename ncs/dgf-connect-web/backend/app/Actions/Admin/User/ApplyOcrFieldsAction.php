<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\OcrResultStatus;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\UserPaperItem;
use App\Models\UserPaperOcrResult;
use App\Services\External\OpenRouterOcrService;
use App\Support\Residence\ResidenceSlotResolver;
use Illuminate\Support\Facades\DB;

class ApplyOcrFieldsAction
{
    // The extracted name is a single string that cannot be split into last/first reliably,
    // so it is deliberately not appliable — admins correct names by hand. The kana names are
    // returned already split by the extractor, so they can be applied.
    private const ALLOWED_FIELDS = [
        OpenRouterOcrService::GROUP_RESIDENCE_CARD => [
            'last_name_kana',
            'first_name_kana',
            'birthday',
            'gender',
            'nationality',
            'address',
            'postal_code',
            'prefecture',
            'residence_number',
            'residence_expiry_date',
            'residence_status',
        ],
        OpenRouterOcrService::GROUP_PASSPORT => ['passport_number', 'passport_expiry_date'],
        OpenRouterOcrService::GROUP_STUDENT_CARD => ['student_card_expiry_date'],
    ];

    private const RESIDENCE_SLOT_FIELDS = ['residence_number', 'residence_expiry_date', 'residence_status'];

    // Fields that live on the users table; everything else is written to user_infos
    private const USER_FIELDS = ['last_name_kana', 'first_name_kana'];

    /**
     * @param string[] $fieldKeys extracted fields the admin chose to apply; values are read
     *                            from the stored OCR result, never from the client
     */
    public function __invoke(User $user, int $ocrResultId, array $fieldKeys): ActionResult
    {
        $result = UserPaperOcrResult::query()->find($ocrResultId);

        if (
            !$result ||
            $result->status !== OcrResultStatus::Completed->value ||
            $result->invalidated_at !== null ||
            !$this->belongsToUser($result, $user)
        ) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => 'The OCR result is not usable for this user']
            );
        }

        $fields = $result->fields ?? [];
        $allowed = self::ALLOWED_FIELDS[$result->ocr_group] ?? [];
        $invalid = array_values(
            array_filter($fieldKeys, fn(string $key) => !in_array($key, $allowed, true) || !isset($fields[$key]))
        );
        if (!empty($invalid)) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: [
                    'message' => 'Some fields cannot be applied from this OCR result',
                    'invalid_fields' => $invalid,
                ]
            );
        }

        DB::transaction(function () use ($user, $result, $fieldKeys) {
            /** @var UserInfo $info */
            $info = UserInfo::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrCreate(['user_id' => $user->id]);

            $slots = $this->resolveResidenceSlots($info, $result, $fieldKeys);
            foreach ($fieldKeys as $key) {
                $value = $result->fields[$key];

                if (in_array($key, self::USER_FIELDS, true)) {
                    $user->{$key} = $value;
                } elseif (in_array($key, self::RESIDENCE_SLOT_FIELDS, true)) {
                    foreach ($slots as $slot) {
                        $info->{$this->slotColumnName($key, $slot)} = $value;
                    }
                } else {
                    $info->{$key} = $value;
                }
            }

            ResidenceSlotResolver::collapseIdenticalCopies($info, $slots);

            $info->save();
            $user->save();
        });

        return new ActionResult(data: $user->refresh()->load('info'));
    }

    private function resolveResidenceSlots(UserInfo $info, UserPaperOcrResult $result, array $fieldKeys): array
    {
        if (empty(array_intersect($fieldKeys, self::RESIDENCE_SLOT_FIELDS))) {
            return [];
        }

        $copies = ResidenceSlotResolver::slotsHoldingNumber($info, $result->fields['residence_number'] ?? null);
        if ($copies !== []) {
            return $copies;
        }

        return [ResidenceSlotResolver::latestSlot($info) ?? ResidenceSlotResolver::prepareSlotForWrite($info)];
    }

    private function slotColumnName(string $field, int $slot): string
    {
        return match ($field) {
            'residence_number' => "residence_number_{$slot}",
            'residence_expiry_date' => "residence_expiry_date_{$slot}",
            'residence_status' => "residence_status_{$slot}",
        };
    }

    private function belongsToUser(UserPaperOcrResult $result, User $user): bool
    {
        $itemIds = $result->item_ids ?? [];
        if (empty($itemIds)) {
            return false;
        }

        $ownedCount = UserPaperItem::query()
            ->whereIn('id', $itemIds)
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))
            ->count();

        return $ownedCount === count($itemIds);
    }
}
