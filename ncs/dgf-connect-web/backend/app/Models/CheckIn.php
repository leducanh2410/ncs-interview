<?php

namespace App\Models;

use App\Enums\WorkHistoryEditRequestStatus;
use App\Models\Traits\HasWorkHourFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $shift_user_id
 * @property int $shift_id
 * @property int $user_id
 * @property string $check_in_time
 * @property ?string $check_out_time
 * @property ?string $check_in_image_path
 * @property ?string $check_out_image_path
 * @property ?array $check_in_bounding_box
 * @property ?array $check_out_bounding_box
 * @property Shift $shift
 * @property ShiftUser $shiftUser
 * @property ?int $break_minutes
 * @property int $late_minutes
 * @property int $early_minutes
 * @property ?string $late_reason
 * @property ?string $early_reason
 * @property ?int $checked_in_by
 * @property ?int $checked_out_by
 */
class CheckIn extends AbstractModel
{
    use HasWorkHourFields;

    protected $guarded = [];

    protected $appends = [
        'work_hours',
        'work_hours_normal',
        'work_hours_overtime',
        'work_hours_night',
        'work_hours_night_overtime',
    ];

    protected $casts = [
        'late_minutes' => 'integer',
        'early_minutes' => 'integer',
    ];

    public function isLateReasonRequired(): bool
    {
        return $this->late_minutes > 0 &&
            $this->late_reason === null &&
            $this->checked_in_by === null &&
            $this->isWithinReasonWindow($this->check_in_time) &&
            !$this->isShiftUserAbsent();
    }

    public function isEarlyReasonRequired(): bool
    {
        return $this->check_out_time !== null &&
            $this->early_minutes > 0 &&
            $this->early_reason === null &&
            $this->checked_out_by === null &&
            $this->isWithinReasonWindow($this->check_out_time) &&
            !$this->isShiftUserAbsent();
    }

    public function withReasonRequirements(): static
    {
        $this->setAttribute('late_reason_required', $this->isLateReasonRequired());
        $this->setAttribute('early_reason_required', $this->isEarlyReasonRequired());

        return $this;
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shiftUser(): BelongsTo
    {
        return $this->belongsTo(ShiftUser::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(CheckInVerification::class);
    }

    public function checkInVerification(): HasOne
    {
        return $this->hasOne(CheckInVerification::class)->where('is_check_in', true);
    }

    public function checkOutVerification(): HasOne
    {
        return $this->hasOne(CheckInVerification::class)->where('is_check_in', false);
    }

    public function editRequests(): HasMany
    {
        return $this->hasMany(WorkHistoryEditRequest::class);
    }

    public function pendingCheckOutEditRequests(): HasMany
    {
        return $this->editRequests()
            ->where('status', WorkHistoryEditRequestStatus::Pending->value)
            ->whereHas('items', fn(Builder $query) => $query->where('field', 'check_out_time'));
    }

    public function latestEditRequest(): HasOne
    {
        return $this->hasOne(WorkHistoryEditRequest::class)->latestOfMany();
    }

    /**
     * Scope a query to only include check-ins whose shift user is not marked as absent.
     * Absent shifts must never contribute to work hours, even when a check-in exists.
     *
     * Filters the joined column, so the caller must already join shift_users - every
     * aggregation query that needs this does. Without the join MySQL raises an unknown
     * column error rather than returning wrong rows.
     */
    public function scopeShiftNotAbsent(Builder $query): Builder
    {
        return $query->where('shift_users.is_absent', false);
    }

    /**
     * Scope a query to only include check-ins from workplaces the user has access to
     */
    public function scopeApplyWorkplaceFilter(Builder $query, User $user, string $permission): Builder
    {
        $workplaceIds = $user->getWorkplaceIdsByPermission($permission);

        if ($workplaceIds === null) {
            return $query;
        }

        if (empty($workplaceIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('shift', function (Builder $q) use ($workplaceIds) {
            $q->whereIn('workplace_id', $workplaceIds);
        });
    }

    private function isShiftUserAbsent(): bool
    {
        if (array_key_exists('is_absent', $this->attributes)) {
            return (bool) $this->attributes['is_absent'];
        }

        if ($this->relationLoaded('shiftUser')) {
            return (bool) $this->shiftUser?->is_absent;
        }

        return (bool) ShiftUser::query()->whereKey($this->shift_user_id)->value('is_absent');
    }

    private function isWithinReasonWindow(?string $time): bool
    {
        if ($time === null) {
            return false;
        }

        $windowDays = (int) config('work.check_in.attendance_reason_window_day');

        return Carbon::parse($time)->greaterThanOrEqualTo(now()->subDays($windowDays));
    }
}
