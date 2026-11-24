<?php

namespace App\Actions\User\BankAccount;

use App\Actions\ActionResult;
use App\Actions\Common\BankAccount\SupersedeEffectiveBankAccountsAction;
use App\Enums\BankAccountStatus;
use App\Enums\ErrorCode;
use App\Enums\PayoutMethod;
use App\Exceptions\BankAccountStateException;
use App\Models\User;
use App\Models\UserBankAccount;
use Illuminate\Support\Facades\DB;

class ChooseSevenBankAtmAction
{
    public function __construct(
        private readonly SupersedeEffectiveBankAccountsAction $supersede,
        private readonly DiscardBankAccountDraftAction $discardDraft,
        private readonly GetBankAccountAction $getBankAccount
    ) {}

    public function __invoke(User $user): ActionResult
    {
        if (blank($user->info?->phone)) {
            return new ActionResult(error: ErrorCode::BankAccountPhoneRequired, status: 422);
        }

        try {
            DB::transaction(function () use ($user) {
                $this->discardDraft->discard($user);

                $account = UserBankAccount::query()->create([
                    'user_id' => $user->id,
                    'payout_method' => PayoutMethod::SevenBankAtm->value,
                    'status' => BankAccountStatus::Approved->value,
                    'submitted_at' => now(),
                    'approved_at' => now(),
                ]);
                ($this->supersede)($user->id, $account->id);
            });
        } catch (BankAccountStateException $e) {
            return new ActionResult(error: $e->errorCode, status: $e->status);
        }

        return new ActionResult(data: $this->getBankAccount->payload($user));
    }
}
