<?php

namespace App\Actions\User\BankAccount;

use App\Actions\ActionResult;
use App\Actions\Common\BankAccount\PresentBankAccountAction;
use App\Models\User;
use App\Models\UserBankAccount;

class GetBankAccountAction
{
    public function __construct(private readonly PresentBankAccountAction $present) {}

    public function __invoke(User $user): ActionResult
    {
        return new ActionResult(data: $this->payload($user));
    }

    /**
     * @return array{current: array|null, effective: array|null}
     */
    public function payload(User $user): array
    {
        $current = UserBankAccount::query()
            ->where('user_id', $user->id)
            ->with(['images', 'ocrResult'])
            ->orderByDesc('id')
            ->first();
        $effective = UserBankAccount::query()
            ->where('user_id', $user->id)
            ->effective()
            ->with(['images', 'ocrResult'])
            ->orderByDesc('id')
            ->first();

        return [
            'current' => $current ? $this->present->forOwner($current) : null,
            'effective' => $effective ? $this->present->forOwner($effective) : null,
        ];
    }
}
