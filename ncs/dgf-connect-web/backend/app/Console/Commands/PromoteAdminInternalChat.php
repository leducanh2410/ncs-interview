<?php

namespace App\Console\Commands;

use App\Actions\Chat\AdminGroup\SyncAdminInternalParticipantsAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatSystemConversationKey;
use App\Models\ChatConversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turns an existing hand-made admin group into the internal admin group, keeping its history.
 *
 * Meant for the one production chat the admins already use: rather than starting an empty group
 * beside it, point the system key at it and let the roster reconcile. The group created by the
 * migration is deleted in the same transaction, but only while it is still untouched.
 */
class PromoteAdminInternalChat extends Command
{
    protected $signature = 'chat:promote-admin-internal {conversation : ID of the admin_group conversation to promote}';

    protected $description = 'Promote an existing admin group chat to be the internal admin group (keeps its messages).';

    public function handle(SyncAdminInternalParticipantsAction $sync): int
    {
        $conversationId = (int) $this->argument('conversation');
        $conversation = ChatConversation::query()->find($conversationId);

        if ($conversation === null) {
            $this->error("Conversation {$conversationId} not found.");

            return self::FAILURE;
        }

        if ($conversation->type !== ChatConversationType::AdminGroup->value) {
            $this->error('Only an admin_group conversation can become the internal admin group.');

            return self::FAILURE;
        }

        if ($conversation->system_key === ChatSystemConversationKey::AdminInternal->value) {
            $this->info('That conversation is already the internal admin group.');

            return self::SUCCESS;
        }

        if ($conversation->system_key !== null) {
            $this->error("Conversation {$conversationId} is already the system chat '{$conversation->system_key}'.");

            return self::FAILURE;
        }

        $current = ChatConversation::query()
            ->where('system_key', ChatSystemConversationKey::AdminInternal->value)
            ->first();

        $writtenByPeople = $current?->messages()->where('message_type', '!=', ChatMessageType::System->value)->exists();

        if ($writtenByPeople) {
            $this->error(
                "The internal admin group (conversation {$current->id}) already has messages; " .
                    'promoting another chat would orphan them. Move them first or delete that chat.'
            );

            return self::FAILURE;
        }

        DB::transaction(function () use ($conversation, $current) {
            $current?->delete();

            $conversation
                ->forceFill([
                    'system_key' => ChatSystemConversationKey::AdminInternal->value,
                    'status' => ChatConversationStatus::Open->value,
                    'closed_at' => null,
                    'closed_by_user_id' => null,
                    'closed_by_role' => null,
                    'close_reason' => null,
                ])
                ->save();
        });

        $sync();

        $this->info("Conversation {$conversation->id} is now the internal admin group; roster synced.");

        return self::SUCCESS;
    }
}
