<?php

namespace App\Actions\Chat\AdminGroup;

use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatSystemConversationKey;
use App\Models\ChatConversation;
use Illuminate\Database\QueryException;

/**
 * Returns the one internal admin conversation, creating it when it is missing.
 *
 * The row normally comes from its migration; this action is what makes a fresh database, a
 * restored dump or a test that truncated the table self-heal instead of silently losing the
 * group. The unique index on system_key is the real singleton guarantee — two concurrent callers
 * race here, and the loser reads the winner's row back.
 */
class EnsureAdminInternalConversationAction
{
    public function __invoke(): ChatConversation
    {
        $existing = $this->find();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return ChatConversation::query()->create([
                'type' => ChatConversationType::AdminGroup->value,
                'status' => ChatConversationStatus::Open->value,
                'system_key' => ChatSystemConversationKey::AdminInternal->value,
                'title' => self::defaultTitle(),
                'created_by_user_id' => null,
                'employee_can_send_message' => true,
                'employee_can_send_image' => true,
                'employee_can_send_file' => true,
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return $this->find() ?? throw $e;
        }
    }

    public function find(): ?ChatConversation
    {
        return ChatConversation::query()->where('system_key', ChatSystemConversationKey::AdminInternal->value)->first();
    }

    /**
     * The title lives in the conversation row like any other group's, so a global admin can
     * rename it. It is written once, in the system's base locale.
     */
    public static function defaultTitle(): string
    {
        return __('chat.system_conversation.admin_internal_title', [], config('app.base_locale'));
    }
}
