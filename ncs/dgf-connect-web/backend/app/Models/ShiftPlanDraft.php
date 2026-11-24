<?php

namespace App\Models;

use App\Enums\ShiftPlanDraftAction;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A silent, employee-invisible planning row for the Shift Planner. It mirrors the
 * shape of ShiftUser plus an `action` flag and is finalized into (or against) live
 * ShiftUser rows. Kept separate from ShiftUser so employee schedule queries and
 * reminder jobs only ever see finalized assignments.
 *
 * @property int $id
 * @property int $workplace_id
 * @property string $date physical Shift.date
 * @property int $workplace_shift_id
 * @property int $user_id
 * @property int|null $shift_user_id
 * @property int|null $workplace_position_id
 * @property bool $is_fixed
 * @property ShiftPlanDraftAction $action
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class ShiftPlanDraft extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'is_fixed' => 'boolean',
        'action' => ShiftPlanDraftAction::class,
    ];

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function workplaceShift(): BelongsTo
    {
        return $this->belongsTo(WorkplaceShift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shiftUser(): BelongsTo
    {
        return $this->belongsTo(ShiftUser::class);
    }

    public function workplacePosition(): BelongsTo
    {
        return $this->belongsTo(WorkplacePosition::class);
    }
}
