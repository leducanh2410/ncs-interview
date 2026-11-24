<?php

namespace App\Models;

use App\Enums\AdminLeaveRequestStatus;
use App\Enums\AdminLeaveType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A submitted leave or expense request. Immutable after submission apart from its decision: a
 * mistaken request is rejected and re-submitted rather than edited.
 *
 * @property int $id
 * @property int $requester_id
 * @property AdminLeaveType $type
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property int|null $leave_days snapshot taken at creation, never recomputed
 * @property string $reason
 * @property AdminLeaveRequestStatus $status
 * @property int|null $decided_by_id
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property string|null $reject_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property User $requester
 * @property User|null $decidedBy
 * @property Collection<int, AdminLeaveRequestAttachment> $attachments
 */
class AdminLeaveRequest extends AbstractModel
{
    protected $fillable = [
        'requester_id',
        'type',
        'start_date',
        'end_date',
        'leave_days',
        'reason',
        'status',
        'decided_by_id',
        'decided_at',
        'reject_reason',
    ];

    protected $casts = [
        'type' => AdminLeaveType::class,
        'status' => AdminLeaveRequestStatus::class,
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'leave_days' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function decidedBy(): BelongsTo
    {
        // Deliberately unfiltered: a decider who is later disabled must still resolve, because
        // the decision record has to stay readable for as long as the request does. Users are
        // deactivated via `is_disabled` rather than deleted, so no scope needs relaxing here —
        // this comment exists so nobody later "fixes" it by excluding disabled users.
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AdminLeaveRequestAttachment::class);
    }
}
