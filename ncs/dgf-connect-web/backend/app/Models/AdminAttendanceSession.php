<?php

namespace App\Models;

use App\Enums\AdminAttendanceEventType;
use App\Services\Admin\AdminAttendanceService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $workplace_id
 * @property string $work_date
 * @property string $first_check_in_at
 * @property ?string $last_check_out_at
 * @property ?int $work_minutes
 * @property bool $has_missing_checkout
 * @property ?string $invalidated_reason
 * @property User $user
 * @property Workplace $workplace
 * @property \Illuminate\Database\Eloquent\Collection<int, AdminAttendanceInterval> $intervals
 */
class AdminAttendanceSession extends AbstractModel
{
    protected $guarded = [];

    protected $appends = ['work_hours', 'checkout_deadline_at', 'is_overnight'];

    private ?Carbon $cachedLatestCheckInAt = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AdminAttendanceEvent::class, 'session_id')->orderBy('event_time');
    }

    /**
     * Valid, credited Attendance_Intervals only (Requirement 8) — excludes interrupted or
     * still-open spans, which never get a row here. See `AdminAttendanceService::recomputeDay()`.
     */
    public function intervals(): HasMany
    {
        return $this->hasMany(AdminAttendanceInterval::class, 'session_id')->orderBy('started_at');
    }

    /**
     * The check-in time `checkout_deadline_at` and `is_overnight` are anchored on: this session's
     * latest check-in event, falling back to `first_check_in_at` if no events are loaded yet (a
     * session always has at least one CheckIn event by the time it's serialized within the same
     * request — see `AdminAttendanceService::persistCheckIn()`).
     *
     * Anchored on the *latest* check-in, not `first_check_in_at`: a same-day re-check-in after an
     * earlier checkout (Checkpoint_Commit) is the one actually keeping the session open, so it —
     * not the day's first, possibly daytime, check-in — decides whether the overnight (08:00)
     * grace applies. Public (not just an internal helper for the two attributes above) so
     * `openCheckInAt()` shares this exact computation instead of running its own separate query
     * for the same value — one query, one source of truth.
     *
     * Memoized: called from two attribute accessors on every serialization, and the session's
     * event log cannot change mid-request once loaded.
     */
    public function latestCheckInAt(): Carbon
    {
        if ($this->cachedLatestCheckInAt === null) {
            $latestCheckInAt = $this->events->where('type', AdminAttendanceEventType::CheckIn)->max('event_time');
            $this->cachedLatestCheckInAt = Carbon::parse($latestCheckInAt ?? $this->first_check_in_at);
        }

        return $this->cachedLatestCheckInAt;
    }

    public function openCheckInAt(): ?Carbon
    {
        $latestCheckIn = $this->latestCheckInAt();

        if ($this->last_check_out_at !== null && !Carbon::parse($this->last_check_out_at)->lt($latestCheckIn)) {
            return null;
        }

        return $latestCheckIn;
    }

    /**
     * Worked-hours as a formatted string: the sum of this session's valid Attendance_Intervals
     * (Requirement 8), not the raw earliest-check-in-to-latest-check-out span. Empty when no
     * interval has been credited yet.
     */
    protected function getWorkHoursAttribute(): string
    {
        if (!isset($this->work_minutes)) {
            return '';
        }

        return number_format($this->work_minutes / 60, 2);
    }

    /**
     * The absolute timestamp at which this session's Valid_Checkout_Window closes (Requirement
     * 2.6), so no client re-implements the 20:00/08:00 rule. Never persisted — recomputed on
     * serialization via `AdminAttendanceService::checkoutDeadlineFor()` so it stays correct for
     * historical rows even if the rule changes later.
     *
     * Anchored on the session's *latest* check-in event, not `first_check_in_at`: a same-day
     * re-check-in after an earlier checkout (Checkpoint_Commit) is the one actually keeping the
     * session open, so it — not the day's first, possibly daytime, check-in — decides whether the
     * overnight (08:00) grace applies.
     */
    protected function getCheckoutDeadlineAtAttribute(): string
    {
        return AdminAttendanceService::checkoutDeadlineFor($this->latestCheckInAt())->toIso8601String();
    }

    /**
     * Whether this session's Valid_Checkout_Window is the overnight (08:00 next day) branch, as
     * opposed to the same-day-only (00:00 next day) branch. Exposed directly so clients decide
     * whether to show a "check out before {deadline}" reminder by reading a flag rather than by
     * comparing `checkout_deadline_at` against a check-in date — no client needs to know that
     * 20:00 is the cutoff, or infer it from date arithmetic at all.
     */
    protected function getIsOvernightAttribute(): bool
    {
        return AdminAttendanceService::isOvernightCheckIn($this->latestCheckInAt());
    }

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'has_missing_checkout' => 'boolean',
        ];
    }
}
