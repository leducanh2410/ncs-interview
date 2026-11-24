<?php

namespace App\Actions\Admin\CheckIn;

use App\Actions\ActionResult;
use App\Enums\PermissionName;
use App\Models\CheckInVerification;
use App\Models\User;

class GetSpoofedCheckInsAction
{
    public function __invoke(array $conditions, User $user): ActionResult
    {
        $spoofedCheckIns = CheckInVerification::query()
            ->with(['checkIn', 'checkIn.user', 'checkIn.shift.workplaceShift', 'checkIn.shift.workplace'])
            ->where('is_spoofed', true)
            ->applyWorkplaceFilter($user, PermissionName::CheckInSpoofList->value)
            ->filter($conditions)
            ->orderBy('created_at', 'desc')
            ->paginate($conditions['page_size'] ?? null, ['*'], 'page', $conditions['page'] ?? 1);

        return new ActionResult(data: $spoofedCheckIns);
    }
}
