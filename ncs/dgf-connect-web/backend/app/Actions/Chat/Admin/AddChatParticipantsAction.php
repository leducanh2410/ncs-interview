<?php

namespace App\Actions\Chat\Admin;

use App\Actions\ActionResult;
use App\Actions\Chat\Admin\Concerns\ChecksAdminParticipant;
use App\Actions\Chat\Broadcast\DispatchConversationCreatedAction;
use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Events\NewChatMessage;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\DB;

/**
 * Adds members to a group chat.
 *
 * admin_group: any user can be added freely.
 * shift_group / user_group: only users NOT already in the shift/group scope can be added,
 * as "extra participants". They are stored in extra_participant_user_ids and are the only
 * ones that can be manually removed. Synced scope members cannot be added via this action.
 *
 * Idempotent: an already-active participant is skipped; a previously-removed one is reactivated.
 */
class AddChatParticipantsAction
{
    use ChecksAdminParticipant;

    public function __construct(
        private readonly RecordSystemMessageAction $recordSystemMessage,
        private readonly DispatchConversationCreatedAction $dispatchConversationCreated
    ) {}

    public function __invoke(User $actor, ChatConversation $conversation, array $params): ActionResult
    {
        if (!$this->isActiveAdminParticipant($actor, $conversation)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        // The internal admin group is reconciled from manager.auth; hand edits would drift
        // against the next sync, so the roster and the conversation itself are read-only here.
        if ($conversation->isSystem()) {
            return new ActionResult(error: ErrorCode::ChatSystemConversationImmutable, status: 422);
        }

        $allowedTypes = [
            ChatConversationType::AdminGroup->value,
            ChatConversationType::ShiftGroup->value,
            ChatConversationType::UserGroup->value,
        ];

        if (
            !in_array($conversation->type, $allowedTypes, true) ||
            $conversation->status !== ChatConversationStatus::Open->value
        ) {
            return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);
        }

        $targetIds = collect($params['user_ids'])->map(fn($id) => (int) $id)->unique()->values();

        $isScopedType = in_array(
            $conversation->type,
            [ChatConversationType::ShiftGroup->value, ChatConversationType::UserGroup->value],
            true
        );

        if ($isScopedType) {
            $alreadyInScope = $this->findAlreadyInScopeUsers($conversation, $targetIds->all());
            if (!empty($alreadyInScope)) {
                return new ActionResult(
                    error: ErrorCode::ChatParticipantAlreadyInScope,
                    status: 422,
                    data: ['invalid_user_ids' => $alreadyInScope]
                );
            }
        }

        /** @var array{0: array<int, int>, 1: array<int, ChatMessage>} $result */
        [$addedUserIds, $systemMessages] = DB::transaction(function () use (
            $actor,
            $conversation,
            $targetIds,
            $isScopedType
        ) {
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

                $addedUser = User::query()->find($userId);

                ChatAuditLog::query()->create([
                    'conversation_id' => $conversation->id,
                    'actor_user_id' => $actor->id,
                    'action' => ChatAuditAction::ParticipantAdded->value,
                    'payload' => ['user_id' => $userId, 'role' => ChatParticipantRole::Employee->value],
                ]);

                $systemMessages[] = ($this->recordSystemMessage)(
                    $conversation,
                    ChatSystemMessageType::ParticipantAdded,
                    $actor,
                    ['user_id' => $userId, 'user_name' => $addedUser?->short_name]
                );

                $addedUserIds[] = $userId;
            }

            if ($isScopedType && !empty($addedUserIds)) {
                $current = $conversation->extra_participant_user_ids ?? [];
                $updated = array_values(array_unique(array_merge($current, $addedUserIds)));
                $conversation->forceFill(['extra_participant_user_ids' => $updated])->save();
            }

            return [$addedUserIds, $systemMessages];
        });

        $payload = $this->toPayload($conversation->fresh('participants.user'));
        ($this->dispatchConversationCreated)($addedUserIds, $payload);
        foreach ($systemMessages as $systemMessage) {
            NewChatMessage::dispatch($systemMessage);
        }

        return new ActionResult(
            data: [
                'conversation_id' => $conversation->id,
                'added_user_ids' => $addedUserIds,
            ]
        );
    }

    /** Returns user IDs that ARE already in the shift/user-group scope (cannot be added as extras). */
    private function findAlreadyInScopeUsers(ChatConversation $conversation, array $userIds): array
    {
        if ($conversation->type === ChatConversationType::ShiftGroup->value) {
            if ($conversation->shift_id === null) {
                return [];
            }

            return ShiftUser::query()
                ->where('shift_id', $conversation->shift_id)
                ->where('status', ShiftUserStatus::Approved->value)
                ->whereIn('user_id', $userIds)
                ->pluck('user_id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->all();
        }

        if ($conversation->type === ChatConversationType::UserGroup->value) {
            if ($conversation->user_group_id === null) {
                return [];
            }

            return UserGroup::query()
                ->where('group_id', $conversation->user_group_id)
                ->whereIn('user_id', $userIds)
                ->pluck('user_id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->all();
        }

        return [];
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
