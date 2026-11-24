<?php

namespace App\Models;

use App\Traits\FiltersByWorkplace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $check_in_id
 * @property int $workplace_id
 * @property ?int $requested_by_id
 * @property string $status
 * @property ?int $processed_by_id
 * @property ?string $processed_at
 * @property ?string $reject_reason
 * @property ?string $snapshot_check_in_time
 * @property ?string $snapshot_check_out_time
 * @property ?int $snapshot_break_minutes
 */
class WorkHistoryEditRequest extends AbstractModel
{
    use FiltersByWorkplace;

    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function checkIn(): BelongsTo
    {
        return $this->belongsTo(CheckIn::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkHistoryEditRequestItem::class);
    }
}
