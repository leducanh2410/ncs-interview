<?php

namespace App\Actions\Chat\Lifecycle;

use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatSystemMessageType;
use App\Events\ChatConversationStateChanged;
use App\Events\NewChatMessage;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\DB;

/**
 * System-initiated conversation close used by the lifecycle auto-close jobs.
 *
 * Mirrors CloseConversationAction's transaction/broadcast shape but has no actor and
 * performs no access control: it is only ever called by scheduled jobs. The audit row
 * is written with a null actor and the AutoClosed action, and the timeline reuses the
 * ConversationClosed system message with an `auto_closed` payload flag so the client can
 * phrase it as an automatic close without a new translation key.
 */
class AutoCloseConversationAction
{
    public function __construct(private readonly RecordSystemMessageAction $recordSystemMessage) {}

    public function __invoke(ChatConversation $conversation, string $reasonCode): void
    {
        // Idempotent: another pass (or a manual close) may have already closed it.
        if ($conversation->status === ChatConversationStatus::Closed->value) {
            return;
        }

        $systemMessage = DB::transaction(function () use ($conversation, $reasonCode) {
            $conversation
                ->forceFill([
                    'status' => ChatConversationStatus::Closed->value,
                    'closed_at' => now(),
                    'closed_by_user_id' => null,
                    'closed_by_role' => null,
                    'close_reason' => $reasonCode,
                ])
                ->save();

            ChatAuditLog::query()->create([
                'conversation_id' => $conversation->id,
                'actor_user_id' => null,
                'action' => ChatAuditAction::AutoClosed->value,
                'payload' => [
                    'reason' => $reasonCode,
                ],
            ]);

            return ($this->recordSystemMessage)($conversation, ChatSystemMessageType::ConversationClosed, null, [
                'close_reason' => $reasonCode,
                'auto_closed' => true,
            ]);
        });

        ChatConversationStateChanged::dispatch($conversation);
        NewChatMessage::dispatch($systemMessage);
    }
}
