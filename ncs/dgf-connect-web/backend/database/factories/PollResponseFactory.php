<?php

namespace Database\Factories;

use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PollResponseFactory extends Factory
{
    protected $model = PollResponse::class;

    public function definition(): array
    {
        return [
            'poll_id' => Poll::factory(),
            'user_id' => User::factory(),
            'answer' => [],
            'ip_address' => $this->faker->ipv4(),
            'device_info' => [
                'model' => $this->faker->optional()->word(),
                'os' => $this->faker->optional()->randomElement(['iOS', 'Android']),
                'app_version' => $this->faker->numerify('#.##.##'),
            ],
        ];
    }

    public function forPoll(Poll $poll): static
    {
        return $this->state(
            fn(array $attributes) => [
                'poll_id' => $poll->id,
            ]
        );
    }

    public function forUser(User $user): static
    {
        return $this->state(
            fn(array $attributes) => [
                'user_id' => $user->id,
            ]
        );
    }
}
