<?php

namespace App\Actions\Chat\GuestSupport;

use App\Actions\ActionResult;
use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Actions\Chat\Notification\NotifyChatRecipientsAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\ErrorCode;
use App\Events\ChatConversationStateChanged;
use App\Events\NewChatMessage;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\GuestSupportSession;
use Illuminate\Support\Facades\DB;

class GuestReopenConversationAction
{
    public function __construct(
        private readonly RecordSystemMessageAction $recordSystemMessage,
        private readonly NotifyChatRecipientsAction $notify
    ) {}

    public function __invoke(GuestSupportSession $session, ChatConversation $conversation): ActionResult
    {
        if ($conversation->status === ChatConversationStatus::Open->value) {
            return new ActionResult(error: ErrorCode::ChatAlreadyOpen, status: 422);
        }

        if (!$conversation->can_employee_reopen) {
            return new ActionResult(error: ErrorCode::ChatReopenNotAllowed, status: 403);
        }

        $systemMessage = DB::transaction(function () use ($conversation) {
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
                'actor_user_id' => null,
                'action' => ChatAuditAction::ConversationReopened->value,
                'payload' => ['actor_role' => 'guest'],
            ]);

            return ($this->recordSystemMessage)($conversation, ChatSystemMessageType::ConversationReopened, null);
        });

        ChatConversationStateChanged::dispatch($conversation);
        NewChatMessage::dispatch($systemMessage);
        $this->notify->forGuestReopened($conversation, $systemMessage);

        return new ActionResult(data: ['id' => $conversation->id, 'status' => $conversation->status]);
    }
}
