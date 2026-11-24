<?php

namespace App\Actions\Admin\BankAccount;

use App\Actions\ActionResult;
use App\Actions\Common\BankAccount\PresentBankAccountAction;
use App\Actions\Common\BankAccount\TransitionBankAccountStatusAction;
use App\Enums\BankAccountStatus;
use App\Enums\ErrorCode;
use App\Enums\NotificationType;
use App\Models\User;
use App\Models\UserBankAccount;
use Illuminate\Support\Facades\DB;

class RejectBankAccountAction
{
    public function __construct(
        private readonly TransitionBankAccountStatusAction $transition,
        private readonly NotifyBankAccountDecisionAction $notify,
        private readonly PresentBankAccountAction $present
    ) {}

    public function __invoke(User $admin, UserBankAccount $bankAccount, string $note): ActionResult
    {
        $moved = DB::transaction(function () use ($admin, $bankAccount, $note) {
            $moved = ($this->transition)(
                $bankAccount,
                BankAccountStatus::WaitForApproval,
                BankAccountStatus::Rejected,
                ['approver_id' => $admin->id, 'rejected_note' => $note]
            );
            if (!$moved) {
                return false;
            }

            ($this->notify)($bankAccount, NotificationType::BANK_ACCOUNT_REJECTED);

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
