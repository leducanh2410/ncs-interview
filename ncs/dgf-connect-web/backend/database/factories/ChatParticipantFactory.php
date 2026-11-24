<?php

namespace Database\Factories;

use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatParticipant>
 */
class ChatParticipantFactory extends Factory
{
    protected $model = ChatParticipant::class;

    public function definition(): array
    {
        return [
            'conversation_id' => ChatConversation::factory(),
            'user_id' => User::factory(),
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
            'joined_at' => now(),
            'unread_count' => 0,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn() => ['role' => ChatParticipantRole::Admin->value]);
    }

    public function removed(): static
    {
        return $this->state(
            fn() => [
                'status' => ChatParticipantStatus::Removed->value,
                'removed_at' => now(),
            ]
        );
    }
}
