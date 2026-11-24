<?php

namespace App\Actions\Admin\BankAccount;

use App\Actions\ActionResult;
use App\Actions\Common\BankAccount\PresentBankAccountAction;
use App\Actions\Common\BankAccount\SupersedeEffectiveBankAccountsAction;
use App\Actions\Common\BankAccount\TransitionBankAccountStatusAction;
use App\Enums\BankAccountStatus;
use App\Enums\ErrorCode;
use App\Enums\NotificationType;
use App\Models\User;
use App\Models\UserBankAccount;
use Illuminate\Support\Facades\DB;

class ApproveBankAccountAction
{
    public function __construct(
        private readonly TransitionBankAccountStatusAction $transition,
        private readonly SupersedeEffectiveBankAccountsAction $supersede,
        private readonly NotifyBankAccountDecisionAction $notify,
        private readonly PresentBankAccountAction $present
    ) {}

    public function __invoke(User $admin, UserBankAccount $bankAccount): ActionResult
    {
        $moved = DB::transaction(function () use ($admin, $bankAccount) {
            $moved = ($this->transition)(
                $bankAccount,
                BankAccountStatus::WaitForApproval,
                BankAccountStatus::Approved,
                ['approver_id' => $admin->id, 'approved_at' => now(), 'rejected_note' => null]
            );
            if (!$moved) {
                return false;
            }

            ($this->supersede)($bankAccount->user_id, $bankAccount->id);
            ($this->notify)($bankAccount, NotificationType::BANK_ACCOUNT_APPROVED);

            return true;
        });

        if (!$moved) {
            return new ActionResult(error: ErrorCode::BankAccountInvalidState, status: 409);
        }

        return new ActionResult(
            data: ($this->present)($bankAccount->refresh(), unmasked: true, includeOldImages: true)
        );
    }
}
