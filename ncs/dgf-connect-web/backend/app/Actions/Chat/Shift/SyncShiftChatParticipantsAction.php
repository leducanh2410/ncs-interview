<?php

namespace App\Actions\Chat\Shift;

use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\ShiftUserStatus;
use App\Events\NewChatMessage;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ShiftUser;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

class SyncShiftChatParticipantsAction
{
    /**
     * Shift ids queued for a single deferred sync while inside a batch().
     * Null means no batch is active and syncs run immediately.
     *
     * @var array<int, int>|null
     */
    private static ?array $deferred = null;

    public function __construct(private readonly RecordSystemMessageAction $recordSystemMessage) {}

    public function __invoke(int $shiftId): void
    {
        $conversations = ChatConversation::query()
            ->where('type', ChatConversationType::ShiftGroup->value)
            ->where('status', ChatConversationStatus::Open->value)
            ->where('shift_id', $shiftId)
            ->with('participants')
            ->get();

        if ($conversations->isEmpty()) {
            return;
        }

        $approvedUserIds = ShiftUser::query()
            ->where('shift_id', $shiftId)
            ->where('status', ShiftUserStatus::Approved->value)
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        foreach ($conversations as $conversation) {
            $systemMessages = $this->syncConversation($conversation, $approvedUserIds);
            foreach ($systemMessages as $systemMessage) {
                NewChatMessage::dispatch($systemMessage);
            }
        }
    }

    /**
     * Run a unit of work (typically a bulk shift mutation) collecting every shift touched by
     * ShiftUserObserver and syncing each affected shift chat exactly once after the work finishes,
     * instead of once per row change. Without this, a bulk update of N rows on one shift would run
     * the full roster/participant reconciliation N times (O(N^2)) and emit a storm of system
     * messages/broadcasts.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function batch(Closure $callback): mixed
    {
        $isOuter = self::$deferred === null;

        if ($isOuter) {
            self::$deferred = [];
        }

        try {
            return $callback();
        } finally {
            if ($isOuter) {
                $shiftIds = array_values(array_unique(self::$deferred));
                self::$deferred = null;

                $action = app(self::class);
                foreach ($shiftIds as $shiftId) {
                    $action($shiftId);
                }
            }
        }
    }

    /**
     * Queue a shift for deferred sync if a batch is active.
     *
     * @return bool True when deferred, false when the caller should sync now.
     */
    public static function defer(int $shiftId): bool
    {
        if (self::$deferred === null) {
            return false;
        }

        self::$deferred[] = $shiftId;

        return true;
    }

    /** @return array<int, ChatMessage> */
    private function syncConversation(ChatConversation $conversation, array $approvedUserIds): array
    {
        $extraIds = $conversation->extra_participant_user_ids ?? [];
        $desiredIds = array_values(
            array_unique(array_merge(array_map('intval', $approvedUserIds), array_map('intval', $extraIds)))
        );

        return DB::transaction(function () use ($conversation, $desiredIds) {
            $participants = $conversation->participants->keyBy('user_id');
            $systemMessages = [];

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

                $this->writeAudit(
                    $conversation,
                    ChatAuditAction::ParticipantAdded->value,
                    $userId,
                    ChatParticipantRole::Employee->value
                );
                $systemMessages[] = $this->recordParticipantSystemMessage(
                    $conversation,
                    ChatSystemMessageType::ParticipantAdded,
                    $userId
                );
            }

            foreach ($participants as $participant) {
                if (
                    !$this->isShiftRosterRole($participant->role) ||
                    $participant->status !== ChatParticipantStatus::Active->value ||
                    in_array((int) $participant->user_id, $desiredIds, true)
                ) {
                    continue;
                }

                $oldRole = $participant->role;

                $participant
                    ->forceFill([
                        'role' => ChatParticipantRole::Employee->value,
                        'status' => ChatParticipantStatus::Removed->value,
                        'removed_at' => now(),
                        'removed_by_user_id' => null,
                    ])
                    ->save();

                $this->writeAudit(
                    $conversation,
                    ChatAuditAction::ParticipantRemoved->value,
                    (int) $participant->user_id,
                    $oldRole
                );
                $systemMessages[] = $this->recordParticipantSystemMessage(
                    $conversation,
                    ChatSystemMessageType::ParticipantRemoved,
                    (int) $participant->user_id
                );
            }

            return $systemMessages;
        });
    }

    private function recordParticipantSystemMessage(
        ChatConversation $conversation,
        ChatSystemMessageType $type,
        int $userId
    ): ChatMessage {
        $user = User::query()->find($userId);

        return ($this->recordSystemMessage)($conversation, $type, null, [
            'user_id' => $userId,
            'user_name' => $user?->short_name,
        ]);
    }

    private function isShiftRosterRole(string $role): bool
    {
        return in_array($role, [ChatParticipantRole::Employee->value, ChatParticipantRole::Deputy->value], true);
    }

    private function writeAudit(ChatConversation $conversation, string $action, int $userId, string $role): void
    {
        ChatAuditLog::query()->create([
            'conversation_id' => $conversation->id,
            'actor_user_id' => null,
            'action' => $action,
            'payload' => [
                'user_id' => $userId,
                'role' => $role,
            ],
        ]);
    }
}
