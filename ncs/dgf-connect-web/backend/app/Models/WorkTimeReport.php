<?php

namespace App\Models;

use App\Models\Traits\HasWorkHourFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkTimeReport extends AbstractModel
{
    use HasWorkHourFields;

    protected $guarded = [];

    protected $appends = [
        'work_hours_up_1',
        'work_hours',
        'work_hours_normal',
        'work_hours_overtime',
        'work_hours_night',
        'work_hours_night_overtime',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(WorkTimeReportDetail::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include work time reports from workplaces the user has access to
     */
    public function scopeApplyWorkplaceFilter(
        Builder $query,
        User $user,
        string $permission,
        ?string $relation = null
    ): Builder {
        $workplaceIds = $user->getWorkplaceIdsByPermission($permission);

        if ($workplaceIds === null) {
            return $query;
        }

        if (empty($workplaceIds)) {
            return $query->whereRaw('1 = 0');
        }

        $relation = $relation ?? 'details';

        return $query->whereHas($relation, function (Builder $q) use ($workplaceIds) {
            $q->whereIn('workplace_id', $workplaceIds);
        });
    }
}
