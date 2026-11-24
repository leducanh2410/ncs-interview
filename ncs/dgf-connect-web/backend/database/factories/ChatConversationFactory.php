<?php

namespace Database\Factories;

use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Models\ChatConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatConversation>
 */
class ChatConversationFactory extends Factory
{
    protected $model = ChatConversation::class;

    public function definition(): array
    {
        return [
            'type' => ChatConversationType::AdminGroup->value,
            'status' => ChatConversationStatus::Open->value,
            'title' => fake()->sentence(3),
            'employee_can_send_message' => true,
            'employee_can_send_image' => true,
            'employee_can_send_file' => true,
            'can_employee_reopen' => true,
        ];
    }

    public function support(): static
    {
        return $this->state(fn() => ['type' => ChatConversationType::Support->value]);
    }

    public function closed(): static
    {
        return $this->state(
            fn() => [
                'status' => ChatConversationStatus::Closed->value,
                'closed_at' => now(),
            ]
        );
    }
}
