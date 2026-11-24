<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedScheduleDetail extends AbstractModel
{
    protected $guarded = [];

    public function fixedSchedule(): BelongsTo
    {
        return $this->belongsTo(FixedSchedule::class);
    }

    public function workplaceShift(): BelongsTo
    {
        return $this->belongsTo(WorkplaceShift::class);
    }

    public function workplacePosition(): BelongsTo
    {
        return $this->belongsTo(WorkplacePosition::class);
    }
}
