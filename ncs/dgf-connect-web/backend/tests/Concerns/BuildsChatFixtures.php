<?php

namespace Tests\Concerns;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;

trait BuildsChatFixtures
{
    protected function makeConversation(array $attributes = []): ChatConversation
    {
        return ChatConversation::factory()->create($attributes);
    }

    protected function addParticipant(
        ChatConversation $conversation,
        User $user,
        array $attributes = []
    ): ChatParticipant {
        return ChatParticipant::factory()->create(
            array_merge(
                [
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                ],
                $attributes
            )
        );
    }

    protected function addMessage(ChatConversation $conversation, User $sender, array $attributes = []): ChatMessage
    {
        return ChatMessage::factory()->create(
            array_merge(
                [
                    'conversation_id' => $conversation->id,
                    'sender_user_id' => $sender->id,
                ],
                $attributes
            )
        );
    }
}
