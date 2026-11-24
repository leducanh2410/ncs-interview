<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\Nationality;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserInfo>
 */
class UserInfoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'registered_location' => fake()->city(),
            'birthday' => fake()->date(),
            'gender' => fake()->randomElement(array_column(Gender::cases(), 'value')),
            'nationality' => fake()->randomElement(array_column(Nationality::cases(), 'value')),
            'phone' => fake()->phoneNumber(),
            'emergency_contact_phone' => fake()->phoneNumber(),
            'postal_code' => fake()->postcode(),
            'prefecture' => fake()->state(),
            'address' => fake()->address(),
            'japanese_level' => null,
            'residence_number_1' => fake()->optional()->numerify('###########'),
            'residence_expiry_date_1' => fake()->optional()->date(),
            // Left null on purpose: business rules branch on the residence status (a 留学
            // file may not be approved without a student card expiry date, and pulls in
            // extra documents), so a factory that picked one at random would decide the
            // outcome of unrelated tests a fraction of the time. Tests that care set it.
            'residence_status_1' => null,
            'residence_number_2' => null,
            'residence_expiry_date_2' => null,
            'residence_status_2' => null,
            'residence_number_3' => null,
            'residence_expiry_date_3' => null,
            'residence_status_3' => null,
            'passport_number' => fake()->optional()->numerify('#########'),
            'passport_expiry_date' => fake()->optional()->date(),
        ];
    }
}
