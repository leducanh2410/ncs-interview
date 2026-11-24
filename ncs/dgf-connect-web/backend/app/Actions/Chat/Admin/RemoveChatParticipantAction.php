<?php

namespace App\Actions\Chat\Admin;

use App\Actions\ActionResult;
use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Events\NewChatMessage;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\DB;

/**
 * Removes a member from a group chat.
 *
 * admin_group: any employee participant can be removed except the conversation creator.
 * shift_group / user_group: only extra participants (stored in extra_participant_user_ids)
 * can be removed. Synced scope members are managed exclusively by the sync actions.
 *
 * A removed member loses access to the conversation and its message history.
 */
class RemoveChatParticipantAction
{
    public function __construct(private readonly RecordSystemMessageAction $recordSystemMessage) {}

    public function __invoke(User $actor, ChatConversation $conversation, int $userId): ActionResult
    {
        $isAdminParticipant = $conversation
            ->participants()
            ->where('user_id', $actor->id)
            ->where('role', ChatParticipantRole::Admin->value)
            ->where('status', ChatParticipantStatus::Active->value)
            ->exists();

        if (!$isAdminParticipant) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        // The internal admin group is reconciled from manager.auth; hand edits would drift
        // against the next sync, so the roster and the conversation itself are read-only here.
        if ($conversation->isSystem()) {
            return new ActionResult(error: ErrorCode::ChatSystemConversationImmutable, status: 422);
        }

        $allowedTypes = [
            ChatConversationType::AdminGroup->value,
            ChatConversationType::ShiftGroup->value,
            ChatConversationType::UserGroup->value,
        ];

        $isCreatorRemoval =
            $conversation->type === ChatConversationType::AdminGroup->value &&
            $userId === $conversation->created_by_user_id;

        if (
            !in_array($conversation->type, $allowedTypes, true) ||
            $conversation->status !== ChatConversationStatus::Open->value ||
            $isCreatorRemoval
        ) {
            return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);
        }

        $isScopedType = in_array(
            $conversation->type,
            [ChatConversationType::ShiftGroup->value, ChatConversationType::UserGroup->value],
            true
        );

        if ($isScopedType) {
            // Only genuine extras may be removed here. A user can be stale in extra_participant_user_ids
            // after joining the scope (approved shift member / group member) since the sync actions
            // don't prune the array; removing them would strip a scoped member's access until the next
            // sync. Guard against that by also rejecting anyone currently inside the scope.
            $extraIds = $conversation->extra_participant_user_ids ?? [];
            if (!in_array($userId, $extraIds, true) || $this->isInScope($conversation, $userId)) {
                return new ActionResult(error: ErrorCode::ChatParticipantNotExtra, status: 422);
            }
        }

        $earlyResult = null;
        $systemMessage = DB::transaction(function () use (
            $actor,
            $conversation,
            $userId,
            $isScopedType,
            &$earlyResult
        ) {
            $participant = ChatParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $userId)
                ->where('status', ChatParticipantStatus::Active->value)
                ->first();

            if ($participant === null) {
                $earlyResult = new ActionResult(error: ErrorCode::NotFound, status: 404);

                return null;
            }

            if ($participant->role === ChatParticipantRole::Deputy->value) {
                $earlyResult = new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);

                return null;
            }

            $participant
                ->forceFill([
                    'status' => ChatParticipantStatus::Removed->value,
                    'removed_at' => now(),
                    'removed_by_user_id' => $actor->id,
                ])
                ->save();

            if ($isScopedType) {
                $updated = array_values(
                    array_filter($conversation->extra_participant_user_ids ?? [], fn($id) => $id !== $userId)
                );
                $conversation->forceFill(['extra_participant_user_ids' => $updated])->save();
            }

            ChatAuditLog::query()->create([
                'conversation_id' => $conversation->id,
                'actor_user_id' => $actor->id,
                'action' => ChatAuditAction::ParticipantRemoved->value,
                'payload' => ['user_id' => $userId],
            ]);

            $removedUser = User::query()->find($userId);

            return ($this->recordSystemMessage)($conversation, ChatSystemMessageType::ParticipantRemoved, $actor, [
                'user_id' => $userId,
                'user_name' => $removedUser?->short_name,
            ]);
        });

        if ($earlyResult !== null) {
            return $earlyResult;
        }

        NewChatMessage::dispatch($systemMessage);

        return new ActionResult(
            data: [
                'conversation_id' => $conversation->id,
                'removed_user_id' => $userId,
            ]
        );
    }

    /** Whether the user currently belongs to the conversation's shift/group scope (so is not an extra). */
    private function isInScope(ChatConversation $conversation, int $userId): bool
    {
        if ($conversation->type === ChatConversationType::ShiftGroup->value) {
            return $conversation->shift_id !== null &&
                ShiftUser::query()
                    ->where('shift_id', $conversation->shift_id)
                    ->where('user_id', $userId)
                    ->where('status', ShiftUserStatus::Approved->value)
                    ->exists();
        }

        if ($conversation->type === ChatConversationType::UserGroup->value) {
            return $conversation->user_group_id !== null &&
                UserGroup::query()
                    ->where('group_id', $conversation->user_group_id)
                    ->where('user_id', $userId)
                    ->exists();
        }

        return false;
    }
}
