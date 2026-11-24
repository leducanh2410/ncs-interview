<?php

use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemConversationKey;
use App\Enums\PermissionName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the internal admin group and seeds it with the accounts that can sign in to the admin
 * clients today. Written against the tables rather than the sync action so that replaying this
 * migration years from now does not depend on today's application code.
 */
return new class extends Migration {
    public function up(): void
    {
        $existing = DB::table('chat_conversations')
            ->where('system_key', ChatSystemConversationKey::AdminInternal->value)
            ->first();

        if ($existing !== null) {
            return;
        }

        $now = now();

        $conversationId = DB::table('chat_conversations')->insertGetId([
            'type' => ChatConversationType::AdminGroup->value,
            'system_key' => ChatSystemConversationKey::AdminInternal->value,
            'status' => ChatConversationStatus::Open->value,
            'title' => __('chat.system_conversation.admin_internal_title', [], config('app.base_locale')),
            'created_by_user_id' => null,
            'employee_can_send_message' => true,
            'employee_can_send_image' => true,
            'employee_can_send_file' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $adminIds = DB::table('users')
            ->join('role_user', 'role_user.user_id', '=', 'users.id')
            ->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.name', PermissionName::ManagerAuth->value)
            ->where('users.is_disabled', false)
            ->distinct()
            ->pluck('users.id');

        if ($adminIds->isEmpty()) {
            return;
        }

        DB::table('chat_participants')->insert(
            $adminIds
                ->map(
                    fn($userId) => [
                        'conversation_id' => $conversationId,
                        'user_id' => $userId,
                        'role' => ChatParticipantRole::Admin->value,
                        'status' => ChatParticipantStatus::Active->value,
                        'joined_at' => $now,
                        'unread_count' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                )
                ->all()
        );
    }

    public function down(): void
    {
        $conversationId = DB::table('chat_conversations')
            ->where('system_key', ChatSystemConversationKey::AdminInternal->value)
            ->value('id');

        if ($conversationId === null) {
            return;
        }

        DB::table('chat_participants')->where('conversation_id', $conversationId)->delete();
        DB::table('chat_messages')->where('conversation_id', $conversationId)->delete();
        DB::table('chat_conversations')->where('id', $conversationId)->delete();
    }
};
