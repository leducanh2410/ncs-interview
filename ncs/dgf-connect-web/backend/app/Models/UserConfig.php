<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $max_shift_per_day
 * @property ?string $max_shift_per_day_start_date
 * @property ?string $max_shift_per_day_expiry_date
 * @property int $max_hour_per_week
 * @property ?string $max_hour_per_week_start_date
 * @property ?string $max_hour_per_week_expiry_date
 */
class UserConfig extends AbstractModel
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
