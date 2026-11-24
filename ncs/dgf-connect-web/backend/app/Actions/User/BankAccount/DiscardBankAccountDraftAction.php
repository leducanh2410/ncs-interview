<?php

namespace App\Actions\User\BankAccount;

use App\Actions\ActionResult;
use App\Enums\BankAccountStatus;
use App\Enums\ErrorCode;
use App\Exceptions\BankAccountStateException;
use App\Models\User;
use App\Models\UserBankAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DiscardBankAccountDraftAction
{
    public function __construct(private readonly GetBankAccountAction $getBankAccount) {}

    public function __invoke(User $user): ActionResult
    {
        try {
            DB::transaction(fn() => $this->discard($user));
        } catch (BankAccountStateException $e) {
            return new ActionResult(error: $e->errorCode, status: $e->status);
        }

        return new ActionResult(data: $this->getBankAccount->payload($user));
    }

    public function discard(User $user): void
    {
        $current = UserBankAccount::query()->where('user_id', $user->id)->orderByDesc('id')->lockForUpdate()->first();
        if ($current === null) {
            return;
        }
        if ($current->status->isLocked()) {
            throw new BankAccountStateException(ErrorCode::BankAccountLocked);
        }
        if (in_array($current->status, [BankAccountStatus::Approved, BankAccountStatus::Rejected], true)) {
            return;
        }

        $paths = $current->images()->pluck('file_path')->all();
        $current->delete();
        DB::afterCommit(fn() => Storage::delete($paths));
    }
}
