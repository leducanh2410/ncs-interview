<?php

namespace App\Actions\User\BankAccount;

use App\Enums\BankAccountImageFolder;
use App\Enums\BankAccountStatus;
use App\Enums\ErrorCode;
use App\Enums\OcrResultStatus;
use App\Enums\PayoutMethod;
use App\Exceptions\BankAccountStateException;
use App\Models\User;
use App\Models\UserBankAccount;

class StartBankAccountCycleAction
{
    public function __invoke(User $user): UserBankAccount
    {
        $current = UserBankAccount::query()->where('user_id', $user->id)->orderByDesc('id')->lockForUpdate()->first();

        if ($current === null || $current->status === BankAccountStatus::Approved) {
            return UserBankAccount::query()->create([
                'user_id' => $user->id,
                'payout_method' => PayoutMethod::BankTransfer->value,
                'status' => BankAccountStatus::Draft->value,
            ]);
        }

        if ($current->status->isLocked()) {
            throw new BankAccountStateException(ErrorCode::BankAccountLocked);
        }

        if (
            $current->status === BankAccountStatus::Rejected ||
            $current->payout_method !== PayoutMethod::BankTransfer
        ) {
            $current
                ->ocrResults()
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);
            $current
                ->images()
                ->where('folder', BankAccountImageFolder::Current->value)
                ->update(['folder' => BankAccountImageFolder::Old->value]);
            $current->update([
                'payout_method' => PayoutMethod::BankTransfer->value,
                'status' => BankAccountStatus::Draft->value,
                'bank_name' => null,
                'bank_code' => null,
                'branch_name' => null,
                'branch_code' => null,
                'account_type' => null,
                'account_number' => null,
                'account_holder_kana' => null,
                'account_holder_latin' => null,
                'is_yucho' => false,
                'yucho_symbol' => null,
                'yucho_number' => null,
                'ocr_result_id' => null,
                'ocr_run_count' => 0,
                'ocr_fields' => null,
                'confirmed_fields' => null,
                'entered_manually' => false,
                'name_check' => null,
                'input_mismatches' => null,
                'submitted_at' => null,
                'approver_id' => null,
                'approved_at' => null,
                'rejected_note' => null,
            ]);
            $current
                ->ocrResults()
                ->where('status', OcrResultStatus::Processing->value)
                ->update([
                    'status' => OcrResultStatus::Failed->value,
                    'error' => 'Superseded by a new upload',
                    'completed_at' => now(),
                ]);
        }

        return $current->refresh();
    }
}
