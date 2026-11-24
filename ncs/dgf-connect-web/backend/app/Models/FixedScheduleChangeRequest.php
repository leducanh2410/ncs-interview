<?php

namespace App\Models;

use App\Traits\FiltersByWorkplace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * @property int $id
 * @property int $fixed_schedule_id
 * @property int $workplace_id
 * @property string $status
 * @property ?int $processed_by_id
 * @property ?string $processed_at
 */
class FixedScheduleChangeRequest extends AbstractModel
{
    use FiltersByWorkplace;

    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function fixedSchedule(): BelongsTo
    {
        return $this->belongsTo(FixedSchedule::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(FixedScheduleChangeRequestDay::class)->orderBy('day');
    }

    public function user(): HasOneThrough
    {
        return $this->hasOneThrough(User::class, FixedSchedule::class, 'id', 'id', 'fixed_schedule_id', 'user_id');
    }
}
