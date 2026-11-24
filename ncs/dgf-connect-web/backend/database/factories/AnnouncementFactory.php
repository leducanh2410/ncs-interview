<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'content' => '<p>' . $this->faker->paragraph() . '</p>',
            'image_path' => null,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn() => ['is_active' => false]);
    }

    public function scheduled(): static
    {
        return $this->state(fn() => ['start_at' => now()->addDay(), 'end_at' => now()->addDays(2)]);
    }

    public function expired(): static
    {
        return $this->state(fn() => ['start_at' => now()->subDays(2), 'end_at' => now()->subDay()]);
    }
}
