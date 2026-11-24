<?php

namespace App\Actions\Manage\Shift;

use App\Actions\ActionResult;
use App\Actions\Chat\Lifecycle\AutoCloseConversationAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\ChatConversation;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hard-deletes a shift. Rows in shift_users, check_ins, shift_reports and shift_absent_notices go
 * with it through FK cascade. Before the row goes, the shift's open chats are closed through the
 * chat lifecycle flow (the FK would only null chat_conversations.shift_id, leaving a chat the
 * auto-close job can never reach) and the Shift Planner drafts that reference the shift or its
 * shift_users are discarded (the FK would only null shift_plan_drafts.shift_user_id, leaving a
 * draft finalize skips silently). Sent recruitment notifications keep a dangling shift_id in
 * their JSON data.
 *
 * The attendance guard is evaluated inside the transaction on a row-locked shift so a check-in that
 * lands between the impact preview and the confirm click still blocks the delete.
 */
class DeleteShiftAction
{
    public const CHAT_CLOSE_REASON = 'shift_deleted';

    public function __construct(
        private readonly GetShiftDeleteImpactAction $getShiftDeleteImpact,
        private readonly AutoCloseConversationAction $autoCloseConversation
    ) {}

    public function __invoke(User $actor, Shift $shift): ActionResult
    {
        if (!$actor->isAbleTo(PermissionName::ShiftDelete->value, $shift->workplace_id)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        return DB::transaction(function () use ($actor, $shift) {
            $lockedShift = Shift::query()->whereKey($shift->id)->lockForUpdate()->first();
            if ($lockedShift === null) {
                return new ActionResult(error: ErrorCode::NotFound, status: 404);
            }

            $checkInsTotal = CheckIn::query()->where('shift_id', $lockedShift->id)->count();
            $approvedCount = ShiftUser::query()
                ->where('shift_id', $lockedShift->id)
                ->where('status', ShiftUserStatus::Approved->value)
                ->count();
            $blockReason = $this->getShiftDeleteImpact->resolveBlockReason(
                $lockedShift,
                $checkInsTotal,
                $approvedCount
            );
            if ($blockReason !== null) {
                return new ActionResult(
                    error: ErrorCode::ShiftCannotDeleteHasAttendance,
                    status: 400,
                    data: ['block_reason' => $blockReason]
                );
            }

            $impact = $this->getShiftDeleteImpact->buildImpact($lockedShift);

            // Close while shift_id is still set so the close is recorded against the shift's chat
            // (audit row + system message) exactly like the scheduled auto-close would.
            $this->getShiftDeleteImpact
                ->openChatConversationsQuery($lockedShift)
                ->get()
                ->each(
                    fn(ChatConversation $conversation) => ($this->autoCloseConversation)(
                        $conversation,
                        self::CHAT_CLOSE_REASON
                    )
                );

            $shiftUserIds = ShiftUser::query()->where('shift_id', $lockedShift->id)->pluck('id');
            $this->getShiftDeleteImpact->planDraftsQuery($lockedShift, $shiftUserIds)->delete();

            $lockedShift->delete();

            Log::info('Shift deleted', [
                'shift_id' => $lockedShift->id,
                'workplace_id' => $lockedShift->workplace_id,
                'date' => $lockedShift->date,
                'deleted_by' => $actor->id,
                'shift_users' => $impact['shift_users']['total'],
                'shift_plan_drafts_discarded' => $impact['shift_plan_drafts'],
                'chat_conversations_closed' => $impact['chat_conversations'],
                'recruitment_notifications_dangling' => $impact['recruitment_notifications'],
            ]);

            return new ActionResult(data: ['id' => $lockedShift->id]);
        });
    }
}
