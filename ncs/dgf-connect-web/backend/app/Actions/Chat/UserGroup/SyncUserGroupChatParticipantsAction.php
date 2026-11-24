<?php

namespace App\Actions\Chat\UserGroup;

use App\Actions\Chat\Broadcast\DispatchConversationCreatedAction;
use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Events\NewChatMessage;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\DB;

class SyncUserGroupChatParticipantsAction
{
    public function __construct(
        private readonly RecordSystemMessageAction $recordSystemMessage,
        private readonly DispatchConversationCreatedAction $dispatchConversationCreated
    ) {}

    public function __invoke(int $groupId): void
    {
        $conversations = ChatConversation::query()
            ->where('type', ChatConversationType::UserGroup->value)
            ->where('status', ChatConversationStatus::Open->value)
            ->where('user_group_id', $groupId)
            ->with('participants')
            ->get();

        if ($conversations->isEmpty()) {
            return;
        }

        $groupMemberIds = UserGroup::query()->where('group_id', $groupId)->pluck('user_id')->unique()->values()->all();

        foreach ($conversations as $conversation) {
            [$systemMessages, $addedUserIds] = $this->syncConversation($conversation, $groupMemberIds);

            // Newly added members don't have this conversation in their list yet, so they aren't
            // subscribed to its private channel and would miss the participant-added system message.
            // Notify them on their user channel so the client refetches and shows the conversation.
            if (!empty($addedUserIds)) {
                ($this->dispatchConversationCreated)(
                    $addedUserIds,
                    $this->toPayload($conversation->fresh('participants.user'))
                );
            }

            foreach ($systemMessages as $msg) {
                NewChatMessage::dispatch($msg);
            }
        }
    }

    /** @return array{0: array<int, ChatMessage>, 1: array<int, int>} */
    private function syncConversation(ChatConversation $conversation, array $groupMemberIds): array
    {
        $extraIds = $conversation->extra_participant_user_ids ?? [];
        $desiredIds = array_values(array_unique(array_merge($groupMemberIds, $extraIds)));

        return DB::transaction(function () use ($conversation, $desiredIds) {
            $participants = $conversation->participants->keyBy('user_id');
            $systemMessages = [];
            $addedUserIds = [];

            foreach ($desiredIds as $userId) {
                /** @var ChatParticipant|null $participant */
                $participant = $participants->get($userId);

                if ($participant !== null && $participant->status === ChatParticipantStatus::Active->value) {
                    continue;
                }

                if ($participant === null) {
                    ChatParticipant::query()->create([
                        'conversation_id' => $conversation->id,
                        'user_id' => $userId,
                        'role' => ChatParticipantRole::Employee->value,
                        'status' => ChatParticipantStatus::Active->value,
                        'joined_at' => now(),
                    ]);
                } else {
                    $participant
                        ->forceFill([
                            'role' => ChatParticipantRole::Employee->value,
                            'status' => ChatParticipantStatus::Active->value,
                            'joined_at' => now(),
                            'removed_at' => null,
                            'removed_by_user_id' => null,
                        ])
                        ->save();
                }

                $addedUserIds[] = $userId;

                $addedUser = User::query()->find($userId);
                $systemMessages[] = ($this->recordSystemMessage)(
                    $conversation,
                    ChatSystemMessageType::ParticipantAdded,
                    null,
                    ['user_id' => $userId, 'user_name' => $addedUser?->short_name]
                );
            }

            foreach ($participants as $userId => $participant) {
                if ($participant->status !== ChatParticipantStatus::Active->value) {
                    continue;
                }

                if (in_array($userId, $desiredIds, true)) {
                    continue;
                }

                if ($participant->role === ChatParticipantRole::Admin->value) {
                    continue;
                }

                $participant
                    ->forceFill([
                        'role' => ChatParticipantRole::Employee->value,
                        'status' => ChatParticipantStatus::Removed->value,
                        'removed_at' => now(),
                        'removed_by_user_id' => null,
                    ])
                    ->save();

                $removedUser = User::query()->find($userId);
                $systemMessages[] = ($this->recordSystemMessage)(
                    $conversation,
                    ChatSystemMessageType::ParticipantRemoved,
                    null,
                    ['user_id' => $userId, 'user_name' => $removedUser?->short_name]
                );
            }

            return [$systemMessages, $addedUserIds];
        });
    }

    /**
     * Lightweight conversation summary for the conversation-created broadcast (roster dropped by
     * DispatchConversationCreatedAction; clients refetch on receipt).
     *
     * @return array<string, mixed>
     */
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
