<?php

namespace App\Actions\Chat\AdminGroup;

use App\Actions\Chat\Broadcast\DispatchConversationCreatedAction;
use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemMessageType;
use App\Enums\PermissionName;
use App\Events\NewChatMessage;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles the internal admin group's roster against the accounts that can sign in to the
 * admin clients (a role carrying manager.auth, account not disabled).
 *
 * Full reconciliation rather than incremental add/remove: every trigger — a role attached, a
 * permission moved on a role, an account disabled, the nightly job — asks the same question and
 * converges on the same answer, so a missed event or a raw pivot write self-heals on the next run.
 *
 * Membership mirrors ResolveWebAdminAccessAction: only roles grant admin access, a direct
 * permission_user grant is deliberately ignored.
 */
class SyncAdminInternalParticipantsAction
{
    /**
     * True while a batch() is open: syncs are collapsed into one run after the batch finishes.
     */
    private static bool $deferred = false;

    private static bool $pending = false;

    public function __construct(
        private readonly EnsureAdminInternalConversationAction $ensureConversation,
        private readonly RecordSystemMessageAction $recordSystemMessage,
        private readonly DispatchConversationCreatedAction $dispatchConversationCreated
    ) {}

    public function __invoke(): void
    {
        $conversation = ($this->ensureConversation)();

        [$systemMessages, $addedUserIds] = $this->syncConversation($conversation, $this->adminUserIds());

        foreach ($systemMessages as $systemMessage) {
            NewChatMessage::dispatch($systemMessage);
        }

        if ($addedUserIds !== []) {
            // A newly promoted admin has no conversation list entry for this group yet; the same
            // broadcast a freshly created chat sends makes their client pick it up without a reload.
            ($this->dispatchConversationCreated)($addedUserIds, [
                'id' => $conversation->id,
                'type' => $conversation->type,
                'status' => $conversation->status,
                'system_key' => $conversation->system_key,
                'title' => $conversation->title,
                'created_by_user_id' => $conversation->created_by_user_id,
                'participants' => [],
            ]);
        }
    }

    /**
     * Collapse every sync triggered inside $callback into a single run afterwards. A bulk role
     * import would otherwise reconcile the whole roster once per row and emit a storm of system
     * messages and broadcasts.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function batch(Closure $callback): mixed
    {
        $isOuter = !self::$deferred;

        if ($isOuter) {
            self::$deferred = true;
            self::$pending = false;
        }

        try {
            return $callback();
        } finally {
            if ($isOuter) {
                $shouldSync = self::$pending;
                self::$deferred = false;
                self::$pending = false;

                if ($shouldSync) {
                    app(self::class)();
                }
            }
        }
    }

    /**
     * @return bool True when the sync was queued for the end of the batch, false when the caller
     *              should run it now.
     */
    public static function defer(): bool
    {
        if (!self::$deferred) {
            return false;
        }

        self::$pending = true;

        return true;
    }

    /**
     * @return array<int, int>
     */
    public function adminUserIds(): array
    {
        return User::query()
            ->where('is_disabled', false)
            ->whereHas(
                'roles.permissions',
                fn(Builder $query) => $query->where('name', PermissionName::ManagerAuth->value)
            )
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $desiredIds
     * @return array{0: array<int, ChatMessage>, 1: array<int, int>}
     */
    private function syncConversation(ChatConversation $conversation, array $desiredIds): array
    {
        return DB::transaction(function () use ($conversation, $desiredIds) {
            ChatConversation::query()->whereKey($conversation->id)->lockForUpdate()->first();

            $participants = ChatParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->get()
                ->keyBy('user_id');
            $systemMessages = [];
            $addedUserIds = [];

            foreach ($desiredIds as $userId) {
                /** @var ChatParticipant|null $participant */
                $participant = $participants->get($userId);

                if ($participant !== null && $participant->status === ChatParticipantStatus::Active->value) {
                    if ($participant->role !== ChatParticipantRole::Admin->value) {
                        $participant->forceFill(['role' => ChatParticipantRole::Admin->value])->save();
                    }

                    continue;
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
                    // Rejoining keeps the old read marker and unread counter: the history the
                    // account already saw stays seen.
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

                $addedUserIds[] = $userId;
                $this->writeAudit($conversation, ChatAuditAction::ParticipantAdded->value, $userId);
                $systemMessages[] = $this->recordParticipantSystemMessage(
                    $conversation,
                    ChatSystemMessageType::ParticipantAdded,
                    $userId
                );
            }

            foreach ($participants as $participant) {
                if (
                    $participant->status !== ChatParticipantStatus::Active->value ||
                    in_array((int) $participant->user_id, $desiredIds, true)
                ) {
                    continue;
                }

                // Losing admin access ends membership but never the history: the participant row
                // and every message it produced stay, only access is revoked.
                $participant
                    ->forceFill([
                        'status' => ChatParticipantStatus::Removed->value,
                        'removed_at' => now(),
                        'removed_by_user_id' => null,
                    ])
                    ->save();

                $this->writeAudit(
                    $conversation,
                    ChatAuditAction::ParticipantRemoved->value,
                    (int) $participant->user_id
                );
                $systemMessages[] = $this->recordParticipantSystemMessage(
                    $conversation,
                    ChatSystemMessageType::ParticipantRemoved,
                    (int) $participant->user_id
                );
            }

            return [$systemMessages, $addedUserIds];
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

    private function writeAudit(ChatConversation $conversation, string $action, int $userId): void
    {
        ChatAuditLog::query()->create([
            'conversation_id' => $conversation->id,
            'actor_user_id' => null,
            'action' => $action,
            'payload' => [
                'user_id' => $userId,
                'role' => ChatParticipantRole::Admin->value,
                'source' => 'admin_internal_sync',
            ],
        ]);
    }
}
