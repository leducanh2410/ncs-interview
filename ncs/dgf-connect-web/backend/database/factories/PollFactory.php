<?php

namespace Database\Factories;

use App\Enums\PollTargetType;
use App\Enums\PollType;
use App\Models\Poll;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Database\Eloquent\Factories\Factory;

class PollFactory extends Factory
{
    protected $model = Poll::class;

    public function definition(): array
    {
        return [
            'type' => PollType::Feedback,
            'workplace_id' => null,
            'title_vi' => $this->faker->sentence(3),
            'title_en' => $this->faker->sentence(3),
            'title_ja' => $this->faker->sentence(3),
            'description_vi' => $this->faker->optional()->paragraph(),
            'description_en' => $this->faker->optional()->paragraph(),
            'description_ja' => $this->faker->optional()->paragraph(),
            'is_active' => false,
            'is_required' => $this->faker->boolean(),
            'expires_at' => null,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [],
            'result_counters' => [],
            'created_by' => User::factory(),
        ];
    }

    public function vote(): static
    {
        return $this->state(
            fn(array $attributes) => [
                'type' => PollType::Vote,
                'options' => [],
                'result_counters' => [],
            ]
        );
    }

    public function feedback(): static
    {
        return $this->state(
            fn(array $attributes) => [
                'type' => PollType::Feedback,
                'options' => [],
                'result_counters' => [],
            ]
        );
    }

    public function active(): static
    {
        return $this->state(
            fn(array $attributes) => [
                'is_active' => true,
            ]
        );
    }

    public function global(): static
    {
        return $this->state(
            fn(array $attributes) => [
                'target_type' => PollTargetType::GLOBAL->value,
                'workplace_id' => null,
            ]
        );
    }

    public function workplace(Workplace|int|null $workplace = null): static
    {
        return $this->state(
            fn(array $attributes) => [
                'target_type' => PollTargetType::WORKPLACE->value,
                'workplace_id' => $workplace instanceof Workplace ? $workplace->id : $workplace ?? Workplace::factory(),
            ]
        );
    }

    public function specificUsers(): static
    {
        return $this->state(
            fn(array $attributes) => [
                'target_type' => PollTargetType::SPECIFIC_USERS->value,
                'workplace_id' => null,
            ]
        );
    }

    public function expired(): static
    {
        return $this->state(
            fn(array $attributes) => [
                'expires_at' => now()->subDay(),
            ]
        );
    }

    public function futureExpiry(): static
    {
        return $this->state(
            fn(array $attributes) => [
                'expires_at' => now()->addDays(7),
            ]
        );
    }

    public function multiple(): static
    {
        return $this->state(
            fn(array $attributes) => [
                'is_multiple' => true,
                'max_choice' => 3,
            ]
        );
    }

    public function withVoteOptions(array $candidateIds): static
    {
        $counters = [];
        foreach ($candidateIds as $id) {
            $counters[(string) $id] = ['yes' => 0, 'no' => 0, 'blank' => 0];
        }

        return $this->state(
            fn(array $attributes) => [
                'options' => $candidateIds,
                'result_counters' => $counters,
            ]
        );
    }

    public function withFeedbackOptions(array $options): static
    {
        $counters = [];
        foreach ($options as $option) {
            $id = $option['id'] ?? $option['id'];
            $counters[(string) $id] = 0;
        }

        return $this->state(
            fn(array $attributes) => [
                'options' => $options,
                'result_counters' => $counters,
            ]
        );
    }
}
