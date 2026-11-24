<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Notification extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
    ];

    public function readStates(): HasMany
    {
        return $this->hasMany(NotificationReadState::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class);
    }

    public function workplaces(): BelongsToMany
    {
        return $this->belongsToMany(Workplace::class, 'notification_workplaces')->using(NotificationWorkplace::class);
    }

    public function isTargeted(): bool
    {
        return $this->type === NotificationType::RECRUITMENT->value ||
            $this->target_type === NotificationTargetType::Workplace->value;
    }

    public function scopeTargeted(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            $inner
                ->where('notifications.type', NotificationType::RECRUITMENT->value)
                ->orWhere('notifications.target_type', NotificationTargetType::Workplace->value);
        });
    }

    public function scopeUntargeted(Builder $query): Builder
    {
        return $query
            ->where('notifications.type', '!=', NotificationType::RECRUITMENT->value)
            ->where('notifications.target_type', NotificationTargetType::All->value);
    }

    /**
     * Restricts recruitment notifications to the people they were actually sent to.
     *
     * Applied by every employee-facing read path — list, unread count, mark-read,
     * mark-all-read — from this one place so the rule cannot drift between them.
     *
     * The legacy escape hatch keys off recipients_resolved_count being null, never off
     * "has no recipient rows". Those two are not the same thing: a notification that
     * resolved to nobody also has no rows, and treating it as legacy would show a
     * notification aimed at no one to everyone. Null means never resolved (it predates
     * targeting, or has not been sent yet); zero means resolved and reaching nobody.
     *
     * Non-recruitment types short-circuit on the first condition and never touch
     * notification_recipients at all.
     */
    public function scopeVisibleToUser(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $outer) use ($userId) {
            $outer
                ->where(fn(Builder $inner) => $inner->untargeted())
                ->orWhereNull('recipients_resolved_count')
                ->orWhereExists(function ($sub) use ($userId) {
                    $sub->select(DB::raw(1))
                        ->from('notification_recipients')
                        ->whereColumn('notification_recipients.notification_id', 'notifications.id')
                        ->where('notification_recipients.user_id', $userId);
                });
        });
    }

    /**
     * Whether this specific notification may be read by the given user.
     *
     * Mirrors scopeVisibleToUser for the single-record case, where a query scope cannot help.
     *
     * The null-resolved-count escape hatch only applies to notifications that have actually
     * gone out: a PENDING recruitment notification also has a null count simply because it
     * has not been resolved yet, and allowing it through here would let anyone mark it read
     * before send time — which downstream code (e.g. urgent-shift detection) then trusts as
     * a real read.
     */
    public function isVisibleToUser(int $userId): bool
    {
        if (!$this->isTargeted()) {
            return true;
        }

        // The `status` column has no enum cast, so an instance that was just filled in this
        // same request (rather than loaded fresh from the database) may still be holding the
        // raw NotificationStatus case instead of its string value.
        $status = $this->status instanceof NotificationStatus ? $this->status->value : $this->status;
        if ($status !== NotificationStatus::SENT->value) {
            return false;
        }

        if ($this->recipients_resolved_count === null) {
            return true;
        }

        return $this->recipients()->where('user_id', $userId)->exists();
    }

    public function translations(): HasMany
    {
        return $this->hasMany(NotificationTranslation::class);
    }

    /**
     * Sent recruitment notifications advertising a given shift.
     *
     * The shift id lives inside the JSON `data` column (RecruitmentCriteria), not a real
     * column, so every caller that needs "notifications about shift X" — the hot-bonus
     * lookup on the shift detail screen, the recipient-access check below — goes through
     * this one query instead of re-deriving the JSON path each time.
     */
    public function scopeRecruitmentForShift(Builder $query, int $shiftId): Builder
    {
        return $query
            ->where('type', NotificationType::RECRUITMENT->value)
            ->where('status', NotificationStatus::SENT->value)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.shift_id')) = ?", [(string) $shiftId]);
    }

    /**
     * Whether a user was resolved as a recipient of a sent recruitment notification for the
     * given shift.
     *
     * Nearby-workplace and frequent-urgent-group recipients are not necessarily members of
     * the shift's workplace, so this is checked alongside plain workplace membership to
     * decide whether they may view/register for the shift the notification advertised.
     */
    public static function hasRecruitmentRecipientForShift(int $shiftId, int $userId): bool
    {
        return self::query()
            ->recruitmentForShift($shiftId)
            ->whereHas('recipients', fn(Builder $query) => $query->where('user_id', $userId))
            ->exists();
    }
}
