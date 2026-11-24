<?php

namespace Database\Factories;

use App\Enums\ChatBannedKeywordAction;
use App\Models\ChatBannedKeyword;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatBannedKeyword>
 */
class ChatBannedKeywordFactory extends Factory
{
    protected $model = ChatBannedKeyword::class;

    public function definition(): array
    {
        return [
            'keyword' => fake()->word(),
            'locale' => null,
            'action' => ChatBannedKeywordAction::Mask->value,
            'is_active' => true,
        ];
    }

    public function block(): static
    {
        return $this->state(fn() => ['action' => ChatBannedKeywordAction::Block->value]);
    }

    public function inactive(): static
    {
        return $this->state(fn() => ['is_active' => false]);
    }
}
