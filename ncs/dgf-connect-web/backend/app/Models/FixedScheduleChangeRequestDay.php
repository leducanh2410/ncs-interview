<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $fixed_schedule_change_request_id
 * @property int $day
 * @property ?int $current_workplace_shift_id
 * @property ?int $requested_workplace_shift_id
 */
class FixedScheduleChangeRequestDay extends AbstractModel
{
    protected $guarded = [];

    public function fixedScheduleChangeRequest(): BelongsTo
    {
        return $this->belongsTo(FixedScheduleChangeRequest::class);
    }

    public function currentWorkplaceShift(): BelongsTo
    {
        return $this->belongsTo(WorkplaceShift::class, 'current_workplace_shift_id');
    }

    public function requestedWorkplaceShift(): BelongsTo
    {
        return $this->belongsTo(WorkplaceShift::class, 'requested_workplace_shift_id');
    }
}
