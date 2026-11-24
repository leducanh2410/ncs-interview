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
use App\Events\NewChatMessage;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Assigns or revokes the deputy role for a group chat participant. Deputies are group-chat
 * roles only; support chats keep their existing admin/employee lifecycle. Only admins may
 * assign. Admins themselves cannot be given the deputy role.
 */
class AssignChatParticipantRoleAction
{
    public function __construct(private readonly RecordSystemMessageAction $recordSystemMessage) {}

    public function __invoke(User $actor, ChatConversation $conversation, int $targetUserId, string $role): ActionResult
    {
        if (!$this->supportsDeputyRole($conversation)) {
            return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);
        }

        if ($conversation->status === ChatConversationStatus::Closed->value) {
            return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);
        }

        if (!$this->isActiveAdminParticipant($actor, $conversation)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        // The internal admin group is reconciled from manager.auth; hand edits would drift
        // against the next sync, so the roster and the conversation itself are read-only here.
        if ($conversation->isSystem()) {
            return new ActionResult(error: ErrorCode::ChatSystemConversationImmutable, status: 422);
        }

        $target = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $targetUserId)
            ->where('status', ChatParticipantStatus::Active->value)
            ->first();

        if ($target === null) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        if ($target->role === ChatParticipantRole::Admin->value) {
            return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);
        }

        if ($target->role === $role) {
            return new ActionResult(data: $this->toParticipantPayload($target));
        }

        $oldRole = $target->role;

        $systemMessage = DB::transaction(function () use (
            $actor,
            $conversation,
            $target,
            $targetUserId,
            $role,
            $oldRole
        ) {
            $target->forceFill(['role' => $role])->save();

            ChatAuditLog::query()->create([
                'conversation_id' => $conversation->id,
                'actor_user_id' => $actor->id,
                'action' => ChatAuditAction::ParticipantRoleChanged->value,
                'payload' => [
                    'user_id' => $targetUserId,
                    'old_role' => $oldRole,
                    'new_role' => $role,
                ],
            ]);

            $targetUser = User::query()->find($targetUserId);

            return ($this->recordSystemMessage)($conversation, ChatSystemMessageType::ParticipantRoleChanged, $actor, [
                'user_id' => $targetUserId,
                'user_name' => $targetUser?->short_name,
                'new_role' => $role,
            ]);
        });

        NewChatMessage::dispatch($systemMessage);

        return new ActionResult(data: $this->toParticipantPayload($target->fresh()));
    }

    private function supportsDeputyRole(ChatConversation $conversation): bool
    {
        return in_array(
            $conversation->type,
            [
                ChatConversationType::AdminGroup->value,
                ChatConversationType::ShiftGroup->value,
                ChatConversationType::UserGroup->value,
            ],
            true
        );
    }

    private function isActiveAdminParticipant(User $actor, ChatConversation $conversation): bool
    {
        return $conversation
            ->participants()
            ->where('user_id', $actor->id)
            ->where('role', ChatParticipantRole::Admin->value)
            ->where('status', ChatParticipantStatus::Active->value)
            ->exists();
    }

    private function toParticipantPayload(?ChatParticipant $participant): ?array
    {
        if ($participant === null) {
            return null;
        }

        $participant->loadMissing(['user:id,first_name,last_name,staff_no,profile_picture,short_name']);

        return [
            'user_id' => $participant->user_id,
            'name' => $participant->user?->short_name,
            'staff_no' => $participant->user?->staff_no,
            'profile_picture' => $participant->user?->profile_picture,
            'role' => $participant->role,
            'joined_at' => optional($participant->joined_at)->toIso8601String(),
        ];
    }
}
