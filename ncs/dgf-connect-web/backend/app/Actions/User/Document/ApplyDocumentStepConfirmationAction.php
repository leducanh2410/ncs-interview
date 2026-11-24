<?php

namespace App\Actions\User\Document;

use App\Enums\ErrorCode;
use App\Enums\OcrResultStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\UserPaperStep;
use App\Support\Residence\ResidenceSlotResolver;
use Illuminate\Support\Arr;

/**
 * Confirms one step's fields onto the profile.
 *
 * Shared by the per-step confirm endpoint and the single submit that replaces it, so both
 * enforce the same guards and write the same shape. Must be called inside a transaction
 * that already holds the user's info row, because the residence card rotates card slots.
 */
class ApplyDocumentStepConfirmationAction
{
    private const RESIDENCE_INFO_FIELDS = [
        'birthday',
        'gender',
        'nationality',
        'address',
        'postal_code',
        'prefecture',
        'phone',
        'emergency_contact_phone',
        'japanese_level',
        'registered_location',
        'is_household_head',
        'facebook_link',
        'line_link',
    ];

    /**
     * @param array $data confirmed fields, including the ocr_result_id the form was built from
     * @return ?ErrorCode null on success
     */
    public function __invoke(User $user, UserInfo $info, UserPaperStepKey $step, array $data): ?ErrorCode
    {
        $expectedOcrResultId = (int) Arr::pull($data, 'ocr_result_id');

        $userPaperId = $user->paper()->value('id');
        /** @var ?UserPaperStep $stepRow */
        $stepRow = $userPaperId
            ? UserPaperStep::query()
                ->where('user_paper_id', $userPaperId)
                ->where('step_key', $step->value)
                ->lockForUpdate()
                ->first()
            : null;

        if (!$stepRow || $stepRow->status === UserPaperStepStatus::NeedsReupload->value) {
            return ErrorCode::UserPaperStepNotUploaded;
        }

        if ($stepRow->status === UserPaperStepStatus::Confirmed->value) {
            return ErrorCode::UserPaperStepLocked;
        }

        // An admin rejection followed by a re-upload replaced the run this form was built
        // from; stale fields must not be confirmed against the new upload. A replacement
        // repoints ocr_result_id, so an echoed id that still matches and is not superseded
        // means the form belongs to the upload currently on file.
        if (
            (int) $stepRow->ocr_result_id !== $expectedOcrResultId ||
            $stepRow->ocrResult()->whereNotNull('invalidated_at')->exists()
        ) {
            return ErrorCode::UserPaperStepChanged;
        }

        // The primary run alone does not answer that, because a step reads several groups
        // and they are replaced one at a time: rotation maintenance re-runs only the scan,
        // leaving ocr_result_id pointing at an untouched, still-live residence_card run.
        // A live run that has not finished is that replacement in flight — the form was
        // built before it and describes a reading that is being redone.
        //
        // Its own code, because nothing was re-uploaded: reloading the step changes
        // nothing and the client has to wait for the run instead. The step can already
        // read ocr_failed on the group that did finish, so this is what the manual
        // fallback sees when it opens too early. Failed runs stay confirmable, which is
        // what that fallback relies on once every run is in.
        if (
            $stepRow
                ->ocrResults()
                ->whereNull('invalidated_at')
                ->where('status', OcrResultStatus::Processing->value)
                ->exists()
        ) {
            return ErrorCode::UserPaperStepOcrStillProcessing;
        }

        // While a clearer re-upload is still possible (OCR run budget not spent), the user
        // must take it; once the budget is exhausted manual confirmation opens up instead
        if ($stepRow->canReplaceUnreadableUpload()) {
            return ErrorCode::UserPaperStepOcrRequiredFieldsMissing;
        }

        match ($step) {
            UserPaperStepKey::ResidenceCard => $this->applyResidenceCard($user, $info, $data),
            UserPaperStepKey::Passport => $info->fill([
                'passport_number' => $data['passport_number'],
                'passport_expiry_date' => $data['passport_expiry_date'],
            ]),
            UserPaperStepKey::StudentCard => $info->fill([
                'student_card_expiry_date' => $data['student_card_expiry_date'],
            ]),
            default => null,
        };

        $stepRow->update([
            'status' => UserPaperStepStatus::Confirmed->value,
            'confirmed_at' => now(),
            'confirmed_fields' => $data,
        ]);

        return null;
    }

    private function applyResidenceCard(User $user, UserInfo $info, array $data): void
    {
        // Re-confirming the same card overwrites its slot and removes stale copies.
        $slot = ResidenceSlotResolver::prepareSlotForCard($info, $data['residence_number']);
        $info->{"residence_number_{$slot}"} = $data['residence_number'];
        $info->{"residence_expiry_date_{$slot}"} = $data['residence_expiry_date'];
        $info->{"residence_status_{$slot}"} = $data['residence_status'];

        $info->fill(Arr::only($data, self::RESIDENCE_INFO_FIELDS));

        $nameFields = array_filter(
            Arr::only($data, ['first_name', 'last_name', 'first_name_kana', 'last_name_kana']),
            fn($value) => $value !== null
        );
        if (!empty($nameFields)) {
            $user->update($nameFields);
        }
    }
}
