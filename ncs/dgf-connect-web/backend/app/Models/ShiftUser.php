<?php

namespace App\Models;

use App\Enums\ShiftUserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shift_id
 * @property int $user_id
 * @property int|null $created_by
 * @property int $workplace_position_id
 * @property int|null $approver_id
 * @property string|null $approved_at
 * @property bool $is_fixed
 * @property bool $is_overtime_allowed
 * @property bool $is_absent
 * @property bool $is_reported
 * @property bool $is_urgent_registration
 * @property string $status
 * @property string $confirmed_at
 * @property string|null $final_confirmation_reminded_at
 * @property Shift $shift
 * @property ShiftAbsentNotice|null $absentNotice
 * @property CheckIn|null $checkIn
 * @property ShiftReport|null $report
 */
class ShiftUser extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'is_overtime_allowed' => 'boolean',
        'is_absent' => 'boolean',
        'is_fixed' => 'boolean',
        'is_reported' => 'boolean',
        'is_urgent_registration' => 'boolean',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workplacePosition(): BelongsTo
    {
        return $this->belongsTo(WorkplacePosition::class);
    }

    public function checkIn(): HasOne
    {
        return $this->hasOne(CheckIn::class);
    }

    public function absentNotice(): HasOne
    {
        return $this->hasOne(ShiftAbsentNotice::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(ShiftReport::class);
    }

    /**
     * @param ?Carbon $from earliest day a shift may fall on; defaults to tomorrow, which is
     *        what locking an account means by upcoming — today's shift is already too late
     *        to act on there. A caller that only warns wants today's included.
     */
    public function scopeUpcomingForUser(Builder $query, int $userId, ?Carbon $from = null): Builder
    {
        return $query
            ->where('shift_users.user_id', $userId)
            ->whereIn('shift_users.status', [ShiftUserStatus::Pending->value, ShiftUserStatus::Approved->value])
            ->whereHas(
                'shift',
                fn(Builder $shift) => $shift->whereDate('date', '>=', ($from ?? Carbon::tomorrow())->toDateString())
            );
    }
}
