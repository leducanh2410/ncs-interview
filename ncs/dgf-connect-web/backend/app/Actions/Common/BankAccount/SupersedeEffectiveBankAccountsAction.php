<?php

namespace App\Actions\Common\BankAccount;

use App\Models\UserBankAccount;

class SupersedeEffectiveBankAccountsAction
{
    public function __invoke(int $userId, int $exceptId): int
    {
        return UserBankAccount::query()
            ->where('user_id', $userId)
            ->effective()
            ->whereKeyNot($exceptId)
            ->update(['superseded_at' => now(), 'updated_at' => now()]);
    }
}
