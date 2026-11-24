<?php

namespace App\Actions\Common\BankAccount;

use App\Enums\BankAccountStatus;
use App\Models\UserBankAccount;

class TransitionBankAccountStatusAction
{
    /**
     * @param BankAccountStatus|BankAccountStatus[] $from
     * @param array<string, mixed> $extra
     */
    public function __invoke(
        UserBankAccount $bankAccount,
        BankAccountStatus|array $from,
        BankAccountStatus $to,
        array $extra = []
    ): bool {
        $fromValues = array_map(fn(BankAccountStatus $status) => $status->value, is_array($from) ? $from : [$from]);

        $values = $bankAccount->newInstance()->fill($extra)->getAttributes();

        $changed = UserBankAccount::query()
            ->whereKey($bankAccount->id)
            ->whereIn('status', $fromValues)
            ->update(array_merge($values, ['status' => $to->value, 'updated_at' => now()]));

        if ($changed === 0) {
            return false;
        }

        $bankAccount->refresh();

        return true;
    }
}
