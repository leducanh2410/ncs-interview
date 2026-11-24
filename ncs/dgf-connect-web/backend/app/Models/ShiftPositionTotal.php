<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shift_id
 * @property int $workplace_position_id
 * @property int $fixed_total
 * @property int $temp_total
 */
class ShiftPositionTotal extends AbstractModel
{
    protected $guarded = [];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function workplacePosition(): BelongsTo
    {
        return $this->belongsTo(WorkplacePosition::class);
    }
}
