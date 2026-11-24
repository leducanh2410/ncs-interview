<?php

namespace Database\Factories;

use App\Enums\FaceRecognitionProviderName;
use App\Models\User;
use App\Models\UserFace;
use App\Models\UserPaperItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserFace>
 */
class UserFaceFactory extends Factory
{
    protected $model = UserFace::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'user_paper_item_id' => UserPaperItem::factory(),
            'vector_id' => $this->faker->uuid(),
            'provider' => FaceRecognitionProviderName::Rekognition->value,
        ];
    }
}
