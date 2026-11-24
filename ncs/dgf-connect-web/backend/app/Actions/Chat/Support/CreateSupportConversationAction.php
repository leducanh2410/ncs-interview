<?php

namespace App\Actions\Chat\Support;

use App\Actions\ActionResult;
use App\Actions\Chat\Broadcast\DispatchConversationCreatedAction;
use App\Actions\Chat\Keyword\CheckMessageBlockedAction;
use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Actions\Chat\Notification\NotifyChatRecipientsAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\ErrorCode;
use App\Events\NewChatMessage;
use App\Jobs\AutoAssignSupportAdminJob;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Employee-initiated support chat: opens a support conversation with the employee as the sole
 * participant and the employee's first message as the opening message. Assignment admins triage it
 * from the unassigned support list (no admin participant yet). Employees may have many open ones.
 */
class CreateSupportConversationAction
{
    public function __construct(
        private readonly RecordSystemMessageAction $recordSystemMessage,
        private readonly NotifyChatRecipientsAction $notify,
        private readonly DispatchConversationCreatedAction $dispatchConversationCreated,
        private readonly CheckMessageBlockedAction $checkBlocked
    ) {}

    public function __invoke(User $employee, array $params): ActionResult
    {
        if (filled($params['body'] ?? null)) {
            $blockedKeywords = $this->checkBlocked->matches($params['body']);
            if ($blockedKeywords !== []) {
                return new ActionResult(
                    error: ErrorCode::ChatMessageContainsBannedKeyword,
                    status: 422,
                    data: ['keywords' => $blockedKeywords]
                );
            }
        }

        /** @var array{0: ChatConversation, 1: ChatMessage} $result */
        [$conversation, $message] = DB::transaction(function () use ($employee, $params) {
            $conversation = ChatConversation::query()->create([
                'type' => ChatConversationType::Support->value,
                'status' => ChatConversationStatus::Open->value,
                'title' => $params['title'] ?? null,
                'created_by_user_id' => $employee->id,
                'support_type' => $params['support_type'] ?? null,
            ]);

            ChatAuditLog::query()->create([
                'conversation_id' => $conversation->id,
                'actor_user_id' => $employee->id,
                'action' => ChatAuditAction::ConversationCreated->value,
                'payload' => [
                    'type' => $conversation->type,
                    'title' => $conversation->title,
                ],
            ]);

            ChatParticipant::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $employee->id,
                'role' => ChatParticipantRole::Employee->value,
                'status' => ChatParticipantStatus::Active->value,
                'joined_at' => now(),
            ]);

            // System "created" entry opens the timeline before the employee's first real message.
            ($this->recordSystemMessage)($conversation, ChatSystemMessageType::ConversationCreated, $employee);

            $message = ChatMessage::query()->create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $employee->id,
                'client_message_id' => $params['client_message_id'] ?? null,
                'message_type' => ChatMessageType::Text->value,
                'body' => $params['body'],
            ]);

            // Real messages drive the inactivity timer; system messages do not.
            $conversation
                ->forceFill([
                    'last_message_id' => $message->id,
                    'last_message_at' => $message->created_at,
                    'last_activity_at' => $message->created_at,
                ])
                ->save();

            ChatParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $employee->id)
                ->update([
                    'last_read_message_id' => $message->id,
                    'last_read_at' => $message->created_at,
                    'unread_count' => 0,
                ]);

            return [$conversation, $message];
        });

        NewChatMessage::dispatch($message);
        $this->notify->forSupportCreated($conversation, $message, $employee);
        AutoAssignSupportAdminJob::dispatch($conversation->id)->delay(now()->addMinutes(5));

        $payload = $this->toPayload($conversation->fresh('participants.user'));
        ($this->dispatchConversationCreated)([$employee->id], $payload);

        return new ActionResult(data: $payload);
    }

    private function toPayload(ChatConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'support_type' => $conversation->support_type,
            'status' => $conversation->status,
            'title' => $conversation->title,
            'created_by_user_id' => $conversation->created_by_user_id,
            'last_message_id' => $conversation->last_message_id,
            'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
            'participants' => $conversation->participants
                ->map(
                    fn(ChatParticipant $participant) => [
                        'user_id' => $participant->user_id,
                        'name' => $participant->user?->short_name,
                        'role' => $participant->role,
                        'status' => $participant->status,
                    ]
                )
                ->values()
                ->all(),
        ];
    }
}
