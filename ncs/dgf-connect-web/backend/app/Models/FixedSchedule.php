<?php

namespace App\Models;

use App\Traits\FiltersByWorkplace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $workplace_id
 */
class FixedSchedule extends AbstractModel
{
    use FiltersByWorkplace;

    protected $guarded = [];

    protected $hidden = ['created_at', 'updated_at'];

    protected $appends = ['started_at'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(FixedScheduleDetail::class)->orderBy('day');
    }

    /**
     * Apply a single day's shift change: upsert when a shift is given, delete (day off) when null.
     * Shared by the admin's own weekly edit (FixedScheduleService::update()) and change-request approval,
     * so both mutation paths stay identical.
     */
    public function applyDetailForDay(int $day, ?int $workplaceShiftId, array $attributes = []): void
    {
        if ($workplaceShiftId === null) {
            $this->details()->where('day', $day)->delete();

            return;
        }

        $this->details()->updateOrCreate(['day' => $day], ['workplace_shift_id' => $workplaceShiftId, ...$attributes]);
    }

    protected function getStartedAtAttribute(): string
    {
        return $this->created_at->format('Y-m-d');
    }
}
