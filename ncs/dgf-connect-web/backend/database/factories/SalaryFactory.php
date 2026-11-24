<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class SalaryFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'month' => Carbon::parse($this->faker->dateTimeBetween('-1 year'))->format('Y-m'),
            'total_amount' => 0,
            'total_deductions' => 0,
            'total_fee' => 0,
            'total_paid' => 0,
            'total_remaining' => 0,
            'min_salary' => 0,
            'max_salary' => 0,
            'total_minutes' => 0,
            'is_completed' => 0,
            'is_finalized' => 0,
            'is_fully_paid' => 0,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(
            fn(array $attributes) => [
                'email_verified_at' => null,
            ]
        );
    }
}
