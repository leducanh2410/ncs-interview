<?php

namespace App\Actions\Manage\Shift;

use App\Actions\ActionResult;
use App\Enums\ChatConversationStatus;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\ChatConversation;
use App\Models\CheckIn;
use App\Models\Notification;
use App\Models\Shift;
use App\Models\ShiftAbsentNotice;
use App\Models\ShiftPlanDraft;
use App\Models\ShiftReport;
use App\Models\ShiftUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Summarises everything a shift deletion would take with it (via FK cascade or explicitly) or
 * leave dangling, so the admin can be shown a precise warning before confirming. Also reports
 * whether the delete is blocked; DeleteShiftAction re-evaluates the same guard inside its
 * transaction and never trusts this payload.
 */
class GetShiftDeleteImpactAction
{
    public const BLOCK_REASON_HAS_CHECK_INS = 'has_check_ins';

    public const BLOCK_REASON_ENDED_WITH_APPROVED = 'ended_with_approved';

    public const USER_LIST_LIMIT = 20;

    public function __invoke(User $actor, Shift $shift): ActionResult
    {
        if (!$actor->isAbleTo(PermissionName::ShiftDelete->value, $shift->workplace_id)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        return new ActionResult(data: $this->buildImpact($shift));
    }

    public function buildImpact(Shift $shift): array
    {
        $shiftUsers = ShiftUser::query()
            ->where('shift_id', $shift->id)
            ->with('user:id,first_name,last_name,short_name')
            ->orderBy('id')
            ->get();

        $byStatus = [
            ShiftUserStatus::Pending->value => 0,
            ShiftUserStatus::Approved->value => 0,
            ShiftUserStatus::Rejected->value => 0,
        ];
        $fixedCount = 0;
        $tempCount = 0;
        foreach ($shiftUsers as $shiftUser) {
            $byStatus[$shiftUser->status] = ($byStatus[$shiftUser->status] ?? 0) + 1;
            if ($shiftUser->is_fixed) {
                $fixedCount++;
            } else {
                $tempCount++;
            }
        }

        $shiftUserIds = $shiftUsers->pluck('id');
        $checkInsTotal = CheckIn::query()->where('shift_id', $shift->id)->count();
        $checkInsCheckedOut = CheckIn::query()->where('shift_id', $shift->id)->whereNotNull('check_out_time')->count();

        $blockReason = $this->resolveBlockReason($shift, $checkInsTotal, $byStatus[ShiftUserStatus::Approved->value]);

        return [
            'shift_users' => [
                'total' => $shiftUsers->count(),
                'by_status' => $byStatus,
                'fixed' => $fixedCount,
                'temp' => $tempCount,
                'users' => $shiftUsers
                    ->take(self::USER_LIST_LIMIT)
                    ->map(
                        fn(ShiftUser $shiftUser) => [
                            'id' => $shiftUser->user_id,
                            'name' => $shiftUser->user?->name,
                            'status' => $shiftUser->status,
                            'is_fixed' => (bool) $shiftUser->is_fixed,
                        ]
                    )
                    ->values()
                    ->all(),
            ],
            'check_ins' => [
                'total' => $checkInsTotal,
                'checked_out' => $checkInsCheckedOut,
            ],
            'shift_reports' => ShiftReport::query()->whereIn('shift_user_id', $shiftUserIds)->count(),
            'shift_absent_notices' => ShiftAbsentNotice::query()->whereIn('shift_user_id', $shiftUserIds)->count(),
            'shift_plan_drafts' => $this->planDraftsQuery($shift, $shiftUserIds)->count(),
            'chat_conversations' => $this->openChatConversationsQuery($shift)->count(),
            'recruitment_notifications' => Notification::query()->recruitmentForShift($shift->id)->count(),
            'blocked' => $blockReason !== null,
            'block_reason' => $blockReason,
        ];
    }

    /**
     * Deleting is refused once real attendance data exists (any check-in) or once the shift has
     * ended with approved staff on it — both mean work-hour/salary history would be destroyed.
     */
    public function resolveBlockReason(Shift $shift, int $checkInsTotal, int $approvedCount): ?string
    {
        if ($checkInsTotal > 0) {
            return self::BLOCK_REASON_HAS_CHECK_INS;
        }

        [, $endTime] = $shift->getTimeRange();
        if ($endTime->isPast() && $approvedCount > 0) {
            return self::BLOCK_REASON_ENDED_WITH_APPROVED;
        }

        return null;
    }

    /**
     * Open chats linked to the shift. Deleting the shift closes these through the chat lifecycle
     * flow; once the FK nulls shift_id the auto-close job could never reach them.
     *
     * @return Builder<ChatConversation>
     */
    public function openChatConversationsQuery(Shift $shift): Builder
    {
        return ChatConversation::query()
            ->where('shift_id', $shift->id)
            ->where('status', ChatConversationStatus::Open->value);
    }

    /**
     * Shift Planner drafts that would be orphaned by the delete: `update` drafts whose source live
     * row is one of this shift's shift_users (wherever they are staged), and any draft staged on
     * this shift's own (workplace, date, shift type) cell. Left in place, finalize would skip
     * them silently and still report the date as committed, so they are discarded explicitly.
     *
     * @param Collection<int, int> $shiftUserIds
     * @return Builder<ShiftPlanDraft>
     */
    public function planDraftsQuery(Shift $shift, Collection $shiftUserIds): Builder
    {
        return ShiftPlanDraft::query()->where(function (Builder $query) use ($shift, $shiftUserIds): void {
            $query
                ->where(function (Builder $cell) use ($shift): void {
                    $cell
                        ->where('workplace_id', $shift->workplace_id)
                        ->where('date', $shift->date)
                        ->where('workplace_shift_id', $shift->workplace_shift_id);
                })
                ->when(
                    $shiftUserIds->isNotEmpty(),
                    fn(Builder $query) => $query->orWhereIn('shift_user_id', $shiftUserIds->all())
                );
        });
    }
}
