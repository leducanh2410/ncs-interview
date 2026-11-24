<?php

namespace App\Actions\Chat\Admin;

use App\Actions\ActionResult;
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
use App\Models\ChatParticipant;
use App\Models\Group;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateChatConversationAction
{
    public function __construct(
        private readonly RecordSystemMessageAction $recordSystemMessage,
        private readonly DispatchConversationCreatedAction $dispatchConversationCreated
    ) {}

    public function __invoke(User $creator, array $params): ActionResult
    {
        $isShiftGroup = $params['type'] === ChatConversationType::ShiftGroup->value;

        // Fast-path guard; the (type, shift_id) unique index is the real safeguard against the
        // check-then-create race between concurrent admin requests (see the QueryException catch).
        if (
            $isShiftGroup &&
            ChatConversation::query()
                ->where('type', ChatConversationType::ShiftGroup->value)
                ->where('shift_id', $params['shift_id'])
                ->exists()
        ) {
            return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);
        }

        $systemMessages = [];

        try {
            $conversation = DB::transaction(function () use ($creator, $params, &$systemMessages) {
                $type = $params['type'];
                $participantUserIds = $this->resolveParticipantUserIds($creator, $params);
                $title = $this->resolveTitle($type, $params);

                $conversation = ChatConversation::query()->create([
                    'type' => $type,
                    'status' => ChatConversationStatus::Open->value,
                    'title' => $title,
                    'created_by_user_id' => $creator->id,
                    'shift_id' => $type === ChatConversationType::ShiftGroup->value ? $params['shift_id'] : null,
                    'user_group_id' =>
                        $type === ChatConversationType::UserGroup->value ? $params['user_group_id'] : null,
                    'employee_can_send_message' => $params['employee_can_send_message'] ?? true,
                    'employee_can_send_image' => $params['employee_can_send_image'] ?? true,
                    'employee_can_send_file' => $params['employee_can_send_file'] ?? true,
                ]);

                ChatAuditLog::query()->create([
                    'conversation_id' => $conversation->id,
                    'actor_user_id' => $creator->id,
                    'action' => ChatAuditAction::ConversationCreated->value,
                    'payload' => [
                        'type' => $conversation->type,
                        'title' => $conversation->title,
                        'shift_id' => $conversation->shift_id,
                        'user_group_id' => $conversation->user_group_id,
                        'initial_participant_count' => count($participantUserIds) + 1,
                    ],
                ]);

                $this->addParticipant($conversation, $creator->id, ChatParticipantRole::Admin->value);

                foreach ($participantUserIds as $userId) {
                    $this->addParticipant($conversation, $userId, ChatParticipantRole::Employee->value);
                }

                // Open the conversation timeline. Per-member "added" entries are intentionally omitted at
                // creation (everyone joined at once); later membership changes record their own entries.
                $systemMessages[] = ($this->recordSystemMessage)(
                    $conversation,
                    ChatSystemMessageType::ConversationCreated,
                    $creator
                );
                if ($conversation->title !== null) {
                    $systemMessages[] = ($this->recordSystemMessage)(
                        $conversation,
                        ChatSystemMessageType::TitleSet,
                        $creator,
                        ['title' => $conversation->title]
                    );
                }

                return $conversation;
            });
        } catch (QueryException $e) {
            // Lost the create race against a concurrent request for the same shift group.
            if ($isShiftGroup && $e->getCode() === '23000') {
                return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);
            }
            throw $e;
        }

        foreach ($systemMessages as $systemMessage) {
            NewChatMessage::dispatch($systemMessage);
        }

        $payload = $this->toPayload($conversation->fresh('participants.user'));

        $recipientUserIds = array_map(
            fn(array $participant) => (int) $participant['user_id'],
            $payload['participants']
        );
        ($this->dispatchConversationCreated)($recipientUserIds, $payload);

        return new ActionResult(data: $payload);
    }

    /**
     * Use the explicit title when provided (after trimming); otherwise derive a sensible default
     * from the shift or user group so scoped chats show a meaningful name instead of "Untitled".
     * Manual (admin) groups keep a null title when left blank.
     */
    private function resolveTitle(string $type, array $params): ?string
    {
        $title = isset($params['title']) ? mb_trim($params['title']) : '';
        if ($title !== '') {
            return $title;
        }

        $derived = null;
        if ($type === ChatConversationType::ShiftGroup->value) {
            $derived = $this->shiftGroupDefaultTitle((int) $params['shift_id']);
        } elseif ($type === ChatConversationType::UserGroup->value) {
            $derived = $this->userGroupDefaultTitle((int) $params['user_group_id']);
        }

        // A derived title (workplace/group name + date/time) is not bound by the request's
        // max:255 rule, so cap it to the chat_conversations.title column width to avoid truncation/500.
        return $derived === null ? null : mb_substr($derived, 0, 255);
    }

    private function shiftGroupDefaultTitle(int $shiftId): ?string
    {
        $shift = Shift::query()
            ->with(['workplace:id,name', 'workplaceShift:id,start_time,end_time'])
            ->find($shiftId);
        if ($shift === null) {
            return null;
        }

        $workplaceShift = $shift->workplaceShift;
        $timeRange =
            $workplaceShift && $workplaceShift->start_time && $workplaceShift->end_time
                ? "{$workplaceShift->start_time}-{$workplaceShift->end_time}"
                : null;

        $parts = array_filter([$shift->workplace?->name, $shift->date, $timeRange]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function userGroupDefaultTitle(int $groupId): ?string
    {
        $group = Group::query()->find($groupId);
        if ($group === null) {
            return null;
        }

        // display_name is a locale-keyed map; fall back to the canonical group name.
        $displayName = $group->display_name[app()->getLocale()] ?? ($group->display_name['en'] ?? null);
        $name = is_string($displayName) && mb_trim($displayName) !== '' ? mb_trim($displayName) : $group->name;

        return $name !== null && mb_trim((string) $name) !== '' ? mb_trim((string) $name) : null;
    }

    private function resolveParticipantUserIds(User $creator, array $params): array
    {
        $type = $params['type'];

        if ($type === ChatConversationType::AdminGroup->value) {
            return collect($params['participant_user_ids'] ?? [])
                ->map(fn($userId) => (int) $userId)
                ->reject(fn(int $userId) => $userId === $creator->id)
                ->unique()
                ->values()
                ->all();
        }

        if ($type === ChatConversationType::ShiftGroup->value) {
            return ShiftUser::query()
                ->where('shift_id', $params['shift_id'])
                ->where('status', ShiftUserStatus::Approved->value)
                ->where('user_id', '!=', $creator->id)
                ->pluck('user_id')
                ->unique()
                ->values()
                ->all();
        }

        return Group::query()
            ->findOrFail($params['user_group_id'])
            ->users()
            ->where('users.id', '!=', $creator->id)
            ->pluck('users.id')
            ->unique()
            ->values()
            ->all();
    }

    private function addParticipant(ChatConversation $conversation, int $userId, string $role): void
    {
        ChatParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $userId,
            'role' => $role,
            'status' => ChatParticipantStatus::Active->value,
            'joined_at' => now(),
        ]);
    }

    private function toPayload(ChatConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'status' => $conversation->status,
            'title' => $conversation->title,
            'created_by_user_id' => $conversation->created_by_user_id,
            'shift_id' => $conversation->shift_id,
            'user_group_id' => $conversation->user_group_id,
            'permissions' => [
                'employee_can_send_message' => $conversation->employee_can_send_message,
                'employee_can_send_image' => $conversation->employee_can_send_image,
                'employee_can_send_file' => $conversation->employee_can_send_file,
            ],
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
