<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftReport extends AbstractModel
{
    protected $guarded = [];

    public function shiftUser(): BelongsTo
    {
        return $this->belongsTo(ShiftUser::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include shift reports from workplaces the user has access to
     */
    public function scopeApplyWorkplaceFilter(Builder $query, User $user, string $permission): Builder
    {
        $workplaceIds = $user->getWorkplaceIdsByPermission($permission);

        if ($workplaceIds === null) {
            return $query;
        }

        if (empty($workplaceIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('shiftUser.shift', function (Builder $q) use ($workplaceIds) {
            $q->whereIn('workplace_id', $workplaceIds);
        });
    }
}
