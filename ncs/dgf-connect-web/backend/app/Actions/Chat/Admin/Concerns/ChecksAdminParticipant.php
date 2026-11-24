<?php

namespace App\Actions\Chat\Admin\Concerns;

use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;

trait ChecksAdminParticipant
{
    private function isActiveAdminParticipant(User $actor, ChatConversation $conversation): bool
    {
        return ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)
            ->where('role', ChatParticipantRole::Admin->value)
            ->where('status', ChatParticipantStatus::Active->value)
            ->exists();
    }
}
