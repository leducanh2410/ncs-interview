<?php

namespace App\Models;

use App\Traits\FiltersByWorkplace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workplace_id
 * @property bool $is_enabled
 * @property ?string $deadline
 */
class FixedScheduleChangeConfig extends AbstractModel
{
    use FiltersByWorkplace;

    protected $guarded = [];

    protected $appends = ['is_window_open'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'deadline' => 'date:Y-m-d',
    ];

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function getIsWindowOpenAttribute(): bool
    {
        return $this->is_enabled && $this->deadline !== null && now()->startOfDay()->lte($this->deadline);
    }
}
