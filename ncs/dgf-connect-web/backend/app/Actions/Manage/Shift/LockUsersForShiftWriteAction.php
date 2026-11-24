<?php

namespace App\Actions\Manage\Shift;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class LockUsersForShiftWriteAction
{
    public function __invoke(array $userIds): Collection
    {
        if (empty($userIds)) {
            return new Collection();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'is_disabled'])
            ->keyBy('id');
    }

    public function disabledIds(array $userIds): array
    {
        return $this($userIds)->where('is_disabled', true)->keys()->all();
    }
}
