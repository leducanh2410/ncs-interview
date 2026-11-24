<?php

namespace App\Actions\Chat\Concerns;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Collection;

trait ResolvesGlobalAdminUserIds
{
    /**
     * Given a set of user IDs, return a lookup map of those holding the global admin role.
     *
     * @param  iterable<int|null>  $userIds
     * @return array<int, bool>
     */
    private function globalAdminUserIdMap(iterable $userIds): array
    {
        $ids = Collection::make($userIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        // Mirrors User::hasRole(GlobalAdmin): a user is a global admin if they hold the global_admin
        // role in any scope. Kept as a single batched query (not per-user hasRole) to avoid N+1 over
        // the participant list, but the resulting set is identical to hasRole (verified in tests).
        $adminIds = User::query()
            ->whereIn('id', $ids->all())
            ->whereHas('roles', fn($query) => $query->where('name', RoleName::GlobalAdmin->value))
            ->pluck('id')
            ->all();

        return array_fill_keys($adminIds, true);
    }
}
