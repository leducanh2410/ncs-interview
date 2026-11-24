<?php

namespace App\Actions\Chat\Lifecycle;

use App\Actions\ActionResult;
use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Actions\Chat\Notification\NotifyChatRecipientsAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Events\ChatConversationStateChanged;
use App\Events\NewChatMessage;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CloseConversationAction
{
    public function __construct(
        private readonly RecordSystemMessageAction $recordSystemMessage,
        private readonly NotifyChatRecipientsAction $notify
    ) {}

    public function __invoke(User $actor, ChatConversation $conversation, array $params): ActionResult
    {
        $self = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)
            ->where('status', ChatParticipantStatus::Active->value)
            ->first();

        $isSupportLikeConversation = in_array(
            $conversation->type,
            [ChatConversationType::Support->value, ChatConversationType::GuestSupport->value],
            true
        );

        // Admin participants may close any chat but must hold ChatEdit (the close route also
        // accepts ChatView so deputies can reach it); deputies may close group chats with only
        // ChatView; the owning employee may close their own support chat.
        $isAdmin =
            $self !== null &&
            $self->role === ChatParticipantRole::Admin->value &&
            $actor->hasPermission(PermissionName::ChatEdit->value);
        $isDeputy = $self !== null && $self->role === ChatParticipantRole::Deputy->value && !$isSupportLikeConversation;
        $isSupportOwner =
            $self !== null &&
            $conversation->type === ChatConversationType::Support->value &&
            $conversation->created_by_user_id === $actor->id;

        if (!$isAdmin && !$isDeputy && !$isSupportOwner) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        // The internal admin group is reconciled from manager.auth; hand edits would drift
        // against the next sync, so the group stays open for as long as it exists.
        if ($conversation->isSystem()) {
            return new ActionResult(error: ErrorCode::ChatSystemConversationImmutable, status: 422);
        }

        if ($conversation->status === ChatConversationStatus::Closed->value) {
            return new ActionResult(error: ErrorCode::ChatAlreadyClosed, status: 422);
        }

        $actorRole = match (true) {
            $isAdmin => ChatParticipantRole::Admin->value,
            $isDeputy => ChatParticipantRole::Deputy->value,
            default => ChatParticipantRole::Employee->value,
        };

        $systemMessage = DB::transaction(function () use (
            $actor,
            $conversation,
            $params,
            $isAdmin,
            $actorRole,
            $isSupportLikeConversation
        ) {
            $attributes = [
                'status' => ChatConversationStatus::Closed->value,
                'closed_at' => now(),
                'closed_by_user_id' => $actor->id,
                'closed_by_role' => $actorRole,
                'close_reason' => $params['close_reason'] ?? null,
            ];

            // Admin closing a support-like chat locks it; employee self-closing keeps it reopenable.
            if ($isSupportLikeConversation) {
                $attributes['can_employee_reopen'] = !$isAdmin;
            }

            $conversation->forceFill($attributes)->save();

            ChatAuditLog::query()->create([
                'conversation_id' => $conversation->id,
                'actor_user_id' => $actor->id,
                'action' => ChatAuditAction::ConversationClosed->value,
                'payload' => [
                    'close_reason' => $conversation->close_reason,
                    'can_employee_reopen' => $conversation->can_employee_reopen,
                    'actor_role' => $actorRole,
                ],
            ]);

            $systemMessageType = match (true) {
                $isSupportLikeConversation && $isAdmin => ChatSystemMessageType::ConversationClosedByAdmin,
                $isSupportLikeConversation => ChatSystemMessageType::ConversationClosedByEmployee,
                default => ChatSystemMessageType::ConversationClosed,
            };

            return ($this->recordSystemMessage)($conversation, $systemMessageType, $actor, [
                'close_reason' => $conversation->close_reason,
            ]);
        });

        ChatConversationStateChanged::dispatch($conversation);
        NewChatMessage::dispatch($systemMessage);
        $this->notify->forStateChange($conversation, $systemMessage, $actor, closed: true);

        return new ActionResult(data: $this->toPayload($conversation));
    }

    private function toPayload(ChatConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'status' => $conversation->status,
            'closed_at' => optional($conversation->closed_at)->toIso8601String(),
            'closed_by_user_id' => $conversation->closed_by_user_id,
            'closed_by_role' => $conversation->closed_by_role,
            'close_reason' => $conversation->close_reason,
            'can_employee_reopen' => $conversation->can_employee_reopen,
        ];
    }
}
