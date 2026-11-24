<?php

namespace App\Models;

use App\Enums\RecurringCompletableType;
use App\Enums\RecurringCompletionSourceType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property RecurringCompletableType $completable_type
 * @property int $completable_id
 * @property int $workplace_id
 * @property int $occurrence
 * @property int|null $trigger_shift_user_id
 * @property int|null $anchor_shift_user_id
 * @property Carbon $anchor_date
 * @property Carbon $anchor_start_at
 * @property int $anchor_sort_id
 * @property RecurringCompletionSourceType $source_type
 * @property int $source_id
 * @property Carbon $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RecurringCompletion extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'completable_type' => RecurringCompletableType::class,
        'source_type' => RecurringCompletionSourceType::class,
        'completable_id' => 'integer',
        'workplace_id' => 'integer',
        'occurrence' => 'integer',
        'anchor_sort_id' => 'integer',
        'source_id' => 'integer',
        'anchor_date' => 'date',
        'anchor_start_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function triggerShiftUser(): BelongsTo
    {
        return $this->belongsTo(ShiftUser::class, 'trigger_shift_user_id');
    }

    public function anchorShiftUser(): BelongsTo
    {
        return $this->belongsTo(ShiftUser::class, 'anchor_shift_user_id');
    }
}
