<?php

namespace App\Actions\Chat\GuestSupport;

use App\Actions\ActionResult;
use App\Actions\Chat\Broadcast\DispatchConversationCreatedAction;
use App\Actions\Chat\Keyword\CheckMessageBlockedAction;
use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Actions\Chat\Notification\NotifyChatRecipientsAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatSystemMessageType;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Events\NewChatMessage;
use App\Jobs\AutoAssignSupportAdminJob;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\GuestSupportSession;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateGuestSupportConversationAction
{
    public function __construct(
        private readonly RecordSystemMessageAction $recordSystemMessage,
        private readonly NotifyChatRecipientsAction $notify,
        private readonly DispatchConversationCreatedAction $dispatchConversationCreated,
        private readonly CheckMessageBlockedAction $checkBlocked
    ) {}

    public function __invoke(array $params): ActionResult
    {
        if (filled($params['content'] ?? null)) {
            $blockedKeywords = $this->checkBlocked->matches($params['content']);
            if ($blockedKeywords !== []) {
                return new ActionResult(
                    error: ErrorCode::ChatMessageContainsBannedKeyword,
                    status: 422,
                    data: ['keywords' => $blockedKeywords]
                );
            }
        }

        $plaintext = Str::random(64);
        $hash = hash_hmac('sha256', $plaintext, config('app.key'));

        $conflict = null;

        /** @var array{0: GuestSupportSession, 1: ChatMessage, 2: ChatConversation}|null $result */
        $result = DB::transaction(function () use ($params, $plaintext, $hash, &$conflict) {
            $existing = GuestSupportSession::query()
                ->where('device_id', $params['device_id'])
                ->whereHas('conversation', fn($q) => $q->where('status', ChatConversationStatus::Open->value))
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $conflict = $existing;

                return null;
            }
            // Create conversation first so session can reference it via FK
            $conversation = ChatConversation::query()->create([
                'type' => ChatConversationType::GuestSupport->value,
                'status' => ChatConversationStatus::Open->value,
                'created_by_user_id' => null,
                'support_type' => $params['support_type'] ?? null,
            ]);

            $session = GuestSupportSession::query()->create([
                'device_id' => $params['device_id'],
                'platform' => $params['platform'],
                'fcm_token' => $params['fcm_token'] ?? null,
                'guest_name' => $params['name'],
                'guest_email' => $params['email'],
                'session_token' => Crypt::encryptString($plaintext),
                'session_token_hash' => $hash,
                'conversation_id' => $conversation->id,
            ]);

            $conversation->forceFill(['guest_support_session_id' => $session->id])->save();

            ($this->recordSystemMessage)($conversation, ChatSystemMessageType::ConversationCreated, null);

            $message = ChatMessage::query()->create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => null,
                'guest_support_session_id' => $session->id,
                'client_message_id' => $params['client_message_id'] ?? null,
                'message_type' => ChatMessageType::Text->value,
                'body' => $params['content'],
            ]);

            $conversation
                ->forceFill([
                    'last_message_id' => $message->id,
                    'last_message_at' => $message->created_at,
                    'last_activity_at' => $message->created_at,
                ])
                ->save();

            return [$session, $message, $conversation];
        });

        if ($conflict !== null) {
            return new ActionResult(
                error: ErrorCode::GuestSessionAlreadyExists,
                status: 409,
                data: ['conversation_id' => $conflict->conversation_id]
            );
        }

        /** @var GuestSupportSession $session */
        /** @var ChatMessage $message */
        /** @var ChatConversation $conversation */
        [$session, $message, $conversation] = $result;

        NewChatMessage::dispatch($message);
        $this->notify->forGuestSupportCreated($conversation, $message);
        AutoAssignSupportAdminJob::dispatch($conversation->id)->delay(now()->addMinutes(5));

        $payload = [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'support_type' => $conversation->support_type,
            'status' => $conversation->status,
            'title' => $conversation->title,
            'created_by_user_id' => null,
            'last_message_id' => $conversation->last_message_id,
            'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
            'participants' => [],
        ];

        $adminIds = User::query()
            ->whereHasPermission(PermissionName::ChatAssign->value)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();

        ($this->dispatchConversationCreated)($adminIds, $payload);

        return new ActionResult(
            data: [
                'conversation_id' => $session->conversation_id,
                'support_type' => $conversation->support_type,
                'guest_session_token' => $plaintext,
            ]
        );
    }
}
