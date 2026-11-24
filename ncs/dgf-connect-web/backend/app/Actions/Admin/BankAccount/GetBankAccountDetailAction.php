<?php

namespace App\Actions\Admin\BankAccount;

use App\Actions\ActionResult;
use App\Actions\Common\BankAccount\PresentBankAccountAction;
use App\Enums\PermissionName;
use App\Models\User;
use App\Models\UserBankAccount;

class GetBankAccountDetailAction
{
    public function __construct(private readonly PresentBankAccountAction $present) {}

    public function __invoke(User $admin, UserBankAccount $bankAccount): ActionResult
    {
        $unmasked = $admin->hasPermission(PermissionName::BankAccountApprove->value);
        $bankAccount->load([
            'images',
            'ocrResult',
            'user:id,first_name,last_name,short_name,first_name_kana,last_name_kana,staff_no,profile_picture',
            'user.workplaces:id,name',
            'approver:id,first_name,last_name,short_name',
        ]);

        $history = UserBankAccount::query()
            ->where('user_id', $bankAccount->user_id)
            ->whereKeyNot($bankAccount->id)
            ->with(['images', 'ocrResult'])
            ->orderByDesc('id')
            ->get()
            ->map(
                fn(UserBankAccount $row) => ($this->present)(
                    $row,
                    unmasked: $unmasked,
                    includeOldImages: true,
                    includeImages: $unmasked
                )
            )
            ->all();

        $data = ($this->present)($bankAccount, unmasked: $unmasked, includeOldImages: true, includeImages: $unmasked);
        $data['user'] = [
            'id' => $bankAccount->user->id,
            'name' => $bankAccount->user->name,
            'name_kana' => $bankAccount->user->name_kana,
            'staff_no' => $bankAccount->user->staff_no,
            'profile_picture' => $bankAccount->user->profile_picture,
            'workplaces' => $bankAccount->user->workplaces
                ->map(fn($w) => ['id' => $w->id, 'name' => $w->name])
                ->values()
                ->all(),
        ];
        $data['approver'] = $bankAccount->approver
            ? ['id' => $bankAccount->approver->id, 'name' => $bankAccount->approver->name]
            : null;
        $data['history'] = $history;

        return new ActionResult(data: $data);
    }
}
