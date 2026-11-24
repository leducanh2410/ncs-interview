<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trait for filtering queries by workplace based on user permissions
 *
 * Usage:
 * - Use this trait in models that have a workplace_id column
 * - Call applyWorkplaceFilter() on query builder to filter results based on user's accessible workplaces
 *
 * Example:
 * class Shift extends Model
 * {
 *     use FiltersByWorkplace;
 * }
 *
 * // In action or controller:
 * $shifts = Shift::query()
 *     ->applyWorkplaceFilter($user, 'shift.list')
 *     ->get();
 */
trait FiltersByWorkplace
{
    /**
     * Scope a query to only include records from workplaces the user has access to
     *
     * @param Builder $query
     * @param User $user The user to check permissions for
     * @param string $permission The permission to check (e.g., 'shift.list')
     * @return Builder
     */
    public function scopeApplyWorkplaceFilter(Builder $query, User $user, string $permission): Builder
    {
        $workplaceIds = $user->getWorkplaceIdsByPermission($permission);

        // If null, user has global access - no filtering needed
        if ($workplaceIds === null) {
            return $query;
        }

        // If empty array, user has no access - return empty result
        if (empty($workplaceIds)) {
            return $query->whereRaw('1 = 0');
        }

        // Filter by accessible workplace IDs
        return $query->whereIn('workplace_id', $workplaceIds);
    }
}
