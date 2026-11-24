<?php

namespace App\Actions\Chat\Support;

use App\Actions\ActionResult;
use App\Actions\Chat\Broadcast\DispatchConversationCreatedAction;
use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Actions\Chat\Notification\NotifyChatRecipientsAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\ErrorCode;
use App\Events\NewChatMessage;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Assigns one or more admins to a support chat (an assigned admin = active admin participant).
 * Idempotent: an already-active admin is skipped; a previously-removed one is reactivated.
 * After assignment the support chat behaves like a normal group chat for those admins.
 */
class AssignSupportAdminsAction
{
    public function __construct(
        private readonly RecordSystemMessageAction $recordSystemMessage,
        private readonly NotifyChatRecipientsAction $notify,
        private readonly DispatchConversationCreatedAction $dispatchConversationCreated
    ) {}

    public function __invoke(?User $actor, ChatConversation $conversation, array $params): ActionResult
    {
        if (
            $conversation->type !== ChatConversationType::Support->value &&
            $conversation->type !== ChatConversationType::GuestSupport->value
        ) {
            return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);
        }

        $targetIds = collect($params['admin_user_ids'])->map(fn($id) => (int) $id)->unique()->values();

        /** @var array{0: array<int, int>, 1: array<int, ChatMessage>} $result */
        [$addedUserIds, $systemMessages] = DB::transaction(function () use ($actor, $conversation, $targetIds) {
            $existing = ChatParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->whereIn('user_id', $targetIds)
                ->get()
                ->keyBy('user_id');

            $addedUserIds = [];
            $systemMessages = [];

            foreach ($targetIds as $userId) {
                /** @var ChatParticipant|null $participant */
                $participant = $existing->get($userId);

                if (
                    $participant !== null &&
                    $participant->role === ChatParticipantRole::Admin->value &&
                    $participant->status === ChatParticipantStatus::Active->value
                ) {
                    continue; // already an active assigned admin
                }

                if ($participant === null) {
                    ChatParticipant::query()->create([
                        'conversation_id' => $conversation->id,
                        'user_id' => $userId,
                        'role' => ChatParticipantRole::Admin->value,
                        'status' => ChatParticipantStatus::Active->value,
                        'joined_at' => now(),
                    ]);
                } else {
                    $participant
                        ->forceFill([
                            'role' => ChatParticipantRole::Admin->value,
                            'status' => ChatParticipantStatus::Active->value,
                            'joined_at' => now(),
                            'removed_at' => null,
                            'removed_by_user_id' => null,
                        ])
                        ->save();
                }

                ChatAuditLog::query()->create([
                    'conversation_id' => $conversation->id,
                    'actor_user_id' => $actor?->id,
                    'action' => ChatAuditAction::SupportAdminAssigned->value,
                    'payload' => ['user_id' => $userId],
                ]);

                $assignedUser = User::query()->find($userId);
                $systemMessages[] = ($this->recordSystemMessage)(
                    $conversation,
                    ChatSystemMessageType::SupportAdminAssigned,
                    $actor,
                    ['user_id' => $userId, 'user_name' => $assignedUser?->short_name]
                );

                $addedUserIds[] = $userId;
            }

            return [$addedUserIds, $systemMessages];
        });

        // Surface the chat in each newly assigned admin's normal list, then broadcast the timeline rows.
        $payload = $this->toPayload($conversation->fresh('participants.user'));
        ($this->dispatchConversationCreated)($addedUserIds, $payload);
        foreach ($systemMessages as $systemMessage) {
            NewChatMessage::dispatch($systemMessage);
        }
        // One push per assignment batch (not per assigned admin) so participants aren't spammed
        // with a notification for every admin added in a single call.
        if ($systemMessages !== []) {
            $this->notify->forAdminsAssigned($conversation, $systemMessages[0], $actor);
        }

        return new ActionResult(
            data: [
                'conversation_id' => $conversation->id,
                'assigned_user_ids' => $addedUserIds,
            ]
        );
    }

    private function toPayload(ChatConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'status' => $conversation->status,
            'title' => $conversation->title,
            'created_by_user_id' => $conversation->created_by_user_id,
            'participants' => $conversation->participants
                ->where('status', ChatParticipantStatus::Active->value)
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
