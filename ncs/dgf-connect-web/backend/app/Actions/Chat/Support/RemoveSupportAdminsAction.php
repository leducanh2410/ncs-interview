<?php

namespace App\Actions\Chat\Support;

use App\Actions\ActionResult;
use App\Actions\Chat\Message\RecordSystemMessageAction;
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
 * Removes assigned admins from a support chat. The owning employee is never removed.
 * A removed admin loses access to the conversation and its messages (participant-status gated).
 */
class RemoveSupportAdminsAction
{
    public function __construct(private readonly RecordSystemMessageAction $recordSystemMessage) {}

    public function __invoke(User $actor, ChatConversation $conversation, array $params): ActionResult
    {
        if (
            $conversation->type !== ChatConversationType::Support->value &&
            $conversation->type !== ChatConversationType::GuestSupport->value
        ) {
            return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);
        }

        $targetIds = collect($params['admin_user_ids'])
            ->map(fn($id) => (int) $id)
            ->unique()
            ->reject(fn(int $id) => $id === $conversation->created_by_user_id)
            ->values();

        /** @var array{0: array<int, int>, 1: array<int, ChatMessage>} $result */
        [$removedUserIds, $systemMessages] = DB::transaction(function () use ($actor, $conversation, $targetIds) {
            $participants = ChatParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->whereIn('user_id', $targetIds)
                ->where('role', ChatParticipantRole::Admin->value)
                ->where('status', ChatParticipantStatus::Active->value)
                ->get();

            $removedUserIds = [];
            $systemMessages = [];

            foreach ($participants as $participant) {
                $participant
                    ->forceFill([
                        'status' => ChatParticipantStatus::Removed->value,
                        'removed_at' => now(),
                        'removed_by_user_id' => $actor->id,
                    ])
                    ->save();

                ChatAuditLog::query()->create([
                    'conversation_id' => $conversation->id,
                    'actor_user_id' => $actor->id,
                    'action' => ChatAuditAction::SupportAdminRemoved->value,
                    'payload' => ['user_id' => $participant->user_id],
                ]);

                $removedUser = User::query()->find($participant->user_id);
                $systemMessages[] = ($this->recordSystemMessage)(
                    $conversation,
                    ChatSystemMessageType::SupportAdminRemoved,
                    $actor,
                    ['user_id' => $participant->user_id, 'user_name' => $removedUser?->short_name]
                );

                $removedUserIds[] = $participant->user_id;
            }

            return [$removedUserIds, $systemMessages];
        });

        foreach ($systemMessages as $systemMessage) {
            NewChatMessage::dispatch($systemMessage);
        }

        return new ActionResult(
            data: [
                'conversation_id' => $conversation->id,
                'removed_user_ids' => $removedUserIds,
            ]
        );
    }
}
