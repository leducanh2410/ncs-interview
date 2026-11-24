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

class ReopenConversationAction
{
    public function __construct(
        private readonly RecordSystemMessageAction $recordSystemMessage,
        private readonly NotifyChatRecipientsAction $notify
    ) {}

    public function __invoke(User $actor, ChatConversation $conversation): ActionResult
    {
        $self = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)
            ->where('status', ChatParticipantStatus::Active->value)
            ->first();

        if ($self === null) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $isSupportLikeConversation = in_array(
            $conversation->type,
            [ChatConversationType::Support->value, ChatConversationType::GuestSupport->value],
            true
        );

        $isAdmin =
            $self->role === ChatParticipantRole::Admin->value && $actor->hasPermission(PermissionName::ChatEdit->value);
        $isDeputy = $self->role === ChatParticipantRole::Deputy->value && !$isSupportLikeConversation;
        $isSupportOwner =
            $conversation->type === ChatConversationType::Support->value &&
            $conversation->created_by_user_id === $actor->id;

        // Admins (with ChatEdit) and deputies (group chats only, ChatView) may always reopen.
        // The support owner may reopen only if the admin left it enabled.
        if (!$isAdmin && !$isDeputy) {
            if (!$isSupportOwner) {
                return new ActionResult(error: ErrorCode::Forbidden, status: 403);
            }
            if (!$conversation->can_employee_reopen) {
                return new ActionResult(error: ErrorCode::ChatReopenNotAllowed, status: 403);
            }
        }

        if ($conversation->status === ChatConversationStatus::Open->value) {
            return new ActionResult(error: ErrorCode::ChatAlreadyOpen, status: 422);
        }

        // A shift group whose shift was deleted is closed for good, for every role: the FK nulled
        // shift_id, so its roster could no longer follow the shift and the scheduled auto-close
        // would never pick it up again.
        if ($conversation->type === ChatConversationType::ShiftGroup->value && $conversation->shift_id === null) {
            return new ActionResult(error: ErrorCode::ChatShiftDeleted, status: 422);
        }

        $actorRole = match (true) {
            $isAdmin => ChatParticipantRole::Admin->value,
            $isDeputy => ChatParticipantRole::Deputy->value,
            default => ChatParticipantRole::Employee->value,
        };

        $systemMessage = DB::transaction(function () use ($actor, $conversation, $actorRole) {
            $conversation
                ->forceFill([
                    'status' => ChatConversationStatus::Open->value,
                    'closed_at' => null,
                    'closed_by_user_id' => null,
                    'closed_by_role' => null,
                    'close_reason' => null,
                    'can_employee_reopen' => true,
                ])
                ->save();

            ChatAuditLog::query()->create([
                'conversation_id' => $conversation->id,
                'actor_user_id' => $actor->id,
                'action' => ChatAuditAction::ConversationReopened->value,
                'payload' => [
                    'actor_role' => $actorRole,
                ],
            ]);

            return ($this->recordSystemMessage)($conversation, ChatSystemMessageType::ConversationReopened, $actor);
        });

        ChatConversationStateChanged::dispatch($conversation);
        NewChatMessage::dispatch($systemMessage);
        $this->notify->forStateChange($conversation, $systemMessage, $actor, closed: false);

        return new ActionResult(
            data: [
                'id' => $conversation->id,
                'status' => $conversation->status,
            ]
        );
    }
}
