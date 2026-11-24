<?php

namespace App\Actions\Common\BankAccount;

use App\Enums\BankAccountImageFolder;
use App\Enums\BankAccountStatus;
use App\Helpers\UrlHelper;
use App\Models\UserBankAccount;
use App\Models\UserBankAccountImage;
use App\Support\BankAccount\BankAccountSensitiveFields;

class PresentBankAccountAction
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        UserBankAccount $bankAccount,
        bool $unmasked = false,
        bool $includeOldImages = false,
        bool $includeImages = true
    ): array {
        $bankAccount->loadMissing('images');
        $images = $bankAccount->images
            ->filter(
                fn(UserBankAccountImage $image) => $includeImages &&
                    ($includeOldImages || $image->folder === BankAccountImageFolder::Current)
            )
            ->sortBy('id')
            ->values()
            ->map(
                fn(UserBankAccountImage $image) => [
                    'id' => $image->id,
                    'file_path' => $image->file_path,
                    'folder' => $image->folder->value,
                    'url' => UrlHelper::getStorageUrl($image->file_path),
                ]
            )
            ->all();

        return [
            'id' => $bankAccount->id,
            'user_id' => $bankAccount->user_id,
            'payout_method' => $bankAccount->payout_method->value,
            'status' => $bankAccount->status->value,
            'bank_name' => $bankAccount->bank_name,
            'bank_code' => $bankAccount->bank_code,
            'branch_name' => $bankAccount->branch_name,
            'branch_code' => $bankAccount->branch_code,
            'account_type' => $bankAccount->account_type?->value,
            'account_number_masked' => $bankAccount->maskedAccountNumber(),
            'account_number' => $unmasked ? $bankAccount->account_number : null,
            'account_holder_kana' => $bankAccount->account_holder_kana,
            'account_holder_latin' => $bankAccount->account_holder_latin,
            'is_yucho' => $bankAccount->is_yucho,
            'yucho_symbol' => $bankAccount->yucho_symbol,
            'yucho_number' => $unmasked
                ? $bankAccount->yucho_number
                : BankAccountSensitiveFields::mask($bankAccount->yucho_number),
            'ocr_result_id' => $bankAccount->ocr_result_id,
            'ocr_run_count' => $bankAccount->ocr_run_count,
            'ocr_max_runs' => (int) config('bank_account.ocr_max_runs'),
            'can_reupload' => $bankAccount->canReupload(),
            'ocr_fields' => $unmasked
                ? $bankAccount->ocr_fields
                : BankAccountSensitiveFields::maskFields($bankAccount->ocr_fields),
            'ocr_warnings' => $bankAccount->ocrResult?->warnings ?? [],
            'ocr_error' =>
                $bankAccount->status === BankAccountStatus::OcrFailed ? $bankAccount->ocrResult?->error : null,
            'confirmed_fields' => $unmasked
                ? $bankAccount->confirmed_fields
                : BankAccountSensitiveFields::maskFields($bankAccount->confirmed_fields),
            'entered_manually' => $bankAccount->entered_manually,
            'name_check' => $bankAccount->name_check,
            'input_mismatches' => BankAccountSensitiveFields::maskMismatches($bankAccount->input_mismatches ?? []),
            'approver_id' => $bankAccount->approver_id,
            'submitted_at' => $bankAccount->submitted_at?->toDateTimeString(),
            'approved_at' => $bankAccount->approved_at?->toDateTimeString(),
            'rejected_note' => $bankAccount->rejected_note,
            'superseded_at' => $bankAccount->superseded_at?->toDateTimeString(),
            'images' => $images,
            'images_restricted' => !$includeImages,
            'created_at' => $bankAccount->created_at?->toDateTimeString(),
            'updated_at' => $bankAccount->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forOwner(UserBankAccount $bankAccount): array
    {
        $confirmable = $bankAccount->status->isConfirmable();
        $presented = $this($bankAccount, unmasked: $confirmable, includeImages: $confirmable);
        if (is_array($presented['name_check'])) {
            $presented['name_check'] = [
                'verdict' => $presented['name_check']['verdict'] ?? null,
                'warnings' => $presented['name_check']['warnings'] ?? [],
            ];
        }

        return $presented;
    }
}
