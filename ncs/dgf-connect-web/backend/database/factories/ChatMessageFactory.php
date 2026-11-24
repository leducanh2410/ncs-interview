<?php

namespace Database\Factories;

use App\Enums\ChatMessageType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    public function definition(): array
    {
        return [
            'conversation_id' => ChatConversation::factory(),
            'sender_user_id' => User::factory(),
            'message_type' => ChatMessageType::Text->value,
            'body' => fake()->sentence(),
        ];
    }
}
