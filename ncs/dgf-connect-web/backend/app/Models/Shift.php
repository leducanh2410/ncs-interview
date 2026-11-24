<?php

namespace App\Models;

use App\Enums\ShiftUserStatus;
use App\Helpers\WorkHelper;
use App\Traits\FiltersByWorkplace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workplace_id
 * @property int $workplace_shift_id
 * @property int $creator_id
 * @property string $date
 * @property string $adjusted_date
 * @property int $fixed_total
 * @property int $temp_total
 * @property int|null $max_registered_temp
 * @property int $break_minutes
 * @property bool $is_overtime_by_duration
 * @property string|null $unconfirmed_alerted_at
 * @property string|null $missing_check_in_alerted_at
 * @property WorkplaceShift $workplaceShift
 */
class Shift extends AbstractModel
{
    use FiltersByWorkplace;

    protected $guarded = [];

    protected $casts = [
        'is_overtime_by_duration' => 'boolean',
    ];

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function workplaceShift(): BelongsTo
    {
        return $this->belongsTo(WorkplaceShift::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function shiftUsers(): HasMany
    {
        return $this->hasMany(ShiftUser::class);
    }

    public function positionTotals(): HasMany
    {
        return $this->hasMany(ShiftPositionTotal::class);
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    public function fixedUsers(): HasMany
    {
        return $this->shiftUsers()->where('is_fixed', true)->where('status', ShiftUserStatus::Approved);
    }

    public function tempUsers(): HasMany
    {
        return $this->shiftUsers()->where('is_fixed', false)->where('status', ShiftUserStatus::Approved);
    }

    /**
     * Temp rows that occupy a registration slot: pending as well as approved. This is the single
     * definition of "registered" shared by the user-facing shift search, RegisterShiftAction and
     * the recruitment eligibility filter so the list and the register button never disagree.
     */
    public function registeredTempUsers(): HasMany
    {
        return $this->shiftUsers()
            ->where('is_fixed', false)
            ->whereIn('status', [ShiftUserStatus::Approved->value, ShiftUserStatus::Pending->value]);
    }

    /**
     * Whether the explicit registration cap (`max_registered_temp`) has been reached. A null cap
     * means "no explicit cap" and never counts as reached here.
     *
     * @param int|null $registeredTempCount pass a pre-loaded count to avoid a query
     */
    public function isMaxRegisteredTempReached(?int $registeredTempCount = null): bool
    {
        if ($this->max_registered_temp === null) {
            return false;
        }

        $registeredTempCount ??= $this->registeredTempUsers()->count();

        return $registeredTempCount >= $this->max_registered_temp;
    }

    /**
     * Whether no temp registration slot is left for the app user's shift search: the explicit cap
     * is reached when one is set, otherwise the temp headcount itself is already covered by
     * registered (pending + approved) rows.
     *
     * @param int|null $registeredTempCount pass a pre-loaded count to avoid a query
     */
    public function hasNoTempSlotLeft(?int $registeredTempCount = null): bool
    {
        $registeredTempCount ??= $this->registeredTempUsers()->count();

        if ($this->max_registered_temp !== null) {
            return $registeredTempCount >= $this->max_registered_temp;
        }

        return $registeredTempCount >= $this->temp_total;
    }

    /**
     * @return array [Carbon, Carbon, Carbon|null, Carbon|null]
     */
    public function getTimeRange(): array
    {
        $this->loadMissing('workplaceShift');

        return WorkHelper::getShiftTimes(
            $this->date,
            $this->workplaceShift->start_time,
            $this->workplaceShift->end_time,
            $this->workplaceShift->start_break_time,
            $this->workplaceShift->end_break_time
        );
    }

    public function getDuration(bool $breakTimeExcluded = true): float
    {
        [$startTime, $endTime, $startBreakTime, $endBreakTime] = $this->getTimeRange();

        return WorkHelper::getShiftWorkDuration(
            $startTime,
            $endTime,
            $startBreakTime,
            $endBreakTime,
            $this->break_minutes,
            $breakTimeExcluded
        );
    }
}
