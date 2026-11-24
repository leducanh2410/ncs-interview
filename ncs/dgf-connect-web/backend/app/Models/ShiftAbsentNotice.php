<?php

namespace App\Models;

use App\Enums\AbsentNoticeStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shift_user_id
 * @property AbsentNoticeStatus $status
 * @property int|null $processed_by_id
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property ShiftUser $shiftUser
 * @property User|null $processedBy
 */
class ShiftAbsentNotice extends AbstractModel
{
    protected $fillable = ['shift_user_id', 'status', 'processed_by_id', 'processed_at'];

    protected $casts = [
        'status' => AbsentNoticeStatus::class,
        'processed_at' => 'datetime',
    ];

    public function shiftUser(): BelongsTo
    {
        return $this->belongsTo(ShiftUser::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_id');
    }
}
