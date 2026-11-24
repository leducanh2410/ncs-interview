<?php

namespace App\Actions\User\Document;

use App\Actions\ActionResult;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Models\User;
use App\Models\UserPaperStep;
use App\Services\User\DocumentStepService;
use App\Support\Residence\ResidenceSlotResolver;

class GetDocumentStepDetailAction
{
    public function __construct(
        private readonly DocumentStepService $stepService,
        private readonly GetCurrentRejectedDocumentStepKeysAction $getCurrentRejectedDocumentStepKeys
    ) {}

    public function __invoke(User $user, UserPaperStepKey $step): ActionResult
    {
        $userPaper = $user->paper()->first();
        $stepRow = $userPaper
            ? UserPaperStep::query()
                ->where('user_paper_id', $userPaper->id)
                ->where('step_key', $step->value)
                ->with('ocrResult')
                ->first()
            : null;

        $items = $this->stepService->activeStepItems($user, $step);
        $ocrResult = $stepRow?->ocrResult;
        if ($ocrResult?->invalidated_at !== null) {
            $ocrResult = null;
        }
        $rejectedKeys = array_map(
            fn(UserPaperStepKey $key) => $key->value,
            ($this->getCurrentRejectedDocumentStepKeys)($user)
        );
        $needsReupload =
            $stepRow?->status === UserPaperStepStatus::NeedsReupload->value ||
            ($stepRow === null && in_array($step->value, $rejectedKeys, true));

        return new ActionResult(
            data: [
                'key' => $step->value,
                // Preserve the existing API contract: a rejected step is presented as open.
                'status' => $needsReupload ? null : $stepRow?->status,
                // Let clients distinguish an admin rejection from a step that was never uploaded.
                'needs_reupload' => $needsReupload,
                // Echoed back by the confirm request to detect a replaced submission in between
                'ocr_result_id' => $ocrResult?->id,
                // True while the client must offer a clearer re-upload instead of the confirm
                // form; flips back to false once the cycle's OCR run budget is spent
                'can_reupload' => $stepRow?->canReplaceUnreadableUpload() ?? false,
                'confirmed_at' => $stepRow?->confirmed_at,
                'items' => $items
                    ->map(
                        fn($item) => [
                            'id' => $item->id,
                            'type' => $item->type,
                            'file_name' => $item->file_name,
                            'file_path' => $item->file_path,
                            'approved_at' => $item->approved_at,
                        ]
                    )
                    ->values(),
                'ocr_runs' => $stepRow
                    ? $stepRow
                        ->liveOcrResults()
                        ->map(
                            fn($result) => [
                                'group' => $result->ocr_group,
                                'status' => $result->status,
                                'fields' => $result->fields,
                                'warnings' => $result->warnings ?? [],
                                'error' => $result->error,
                            ]
                        )
                        ->values()
                        ->all()
                    : [],
                'ocr' => $ocrResult
                    ? [
                        'status' => $ocrResult->status,
                        'fields' => $ocrResult->fields,
                        'warnings' => $ocrResult->warnings ?? [],
                        'error' => $ocrResult->error,
                    ]
                    : null,
                'current_values' => $this->currentValues($user, $step),
            ]
        );
    }

    /**
     * Current DB values for the step's confirm form, so the client can prefill
     * fields the OCR could not read (or the whole form when OCR failed).
     */
    private function currentValues(User $user, UserPaperStepKey $step): ?array
    {
        $info = $user->info;

        return match ($step) {
            UserPaperStepKey::ResidenceCard => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'first_name_kana' => $user->first_name_kana,
                'last_name_kana' => $user->last_name_kana,
                'birthday' => $info?->birthday,
                'gender' => $info?->gender,
                'nationality' => $info?->nationality,
                'address' => $info?->address,
                'postal_code' => $info?->postal_code,
                'prefecture' => $info?->prefecture,
                'phone' => $info?->phone,
                'emergency_contact_phone' => $info?->emergency_contact_phone,
                'japanese_level' => $info?->japanese_level,
                'registered_location' => $info?->registered_location,
                'is_household_head' => $info?->is_household_head,
                'facebook_link' => $info?->facebook_link,
                'line_link' => $info?->line_link,
                'residence_card' => $this->latestResidenceCard($info),
            ],
            UserPaperStepKey::Passport => [
                'passport_number' => $info?->passport_number,
                'passport_expiry_date' => $info?->passport_expiry_date,
            ],
            UserPaperStepKey::StudentCard => [
                'student_card_expiry_date' => $info?->student_card_expiry_date,
            ],
            UserPaperStepKey::Portrait, UserPaperStepKey::WorkPermit, UserPaperStepKey::Other => null,
        };
    }

    private function latestResidenceCard($info): ?array
    {
        $slot = ResidenceSlotResolver::latestSlot($info);
        if ($slot === null) {
            return null;
        }

        return [
            'residence_number' => $info->{"residence_number_{$slot}"},
            'residence_expiry_date' => $info->{"residence_expiry_date_{$slot}"},
            'residence_status' => $info->{"residence_status_{$slot}"},
        ];
    }
}
