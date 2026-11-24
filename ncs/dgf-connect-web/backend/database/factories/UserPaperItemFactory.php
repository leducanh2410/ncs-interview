<?php

namespace Database\Factories;

use App\Enums\UserPaperType;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserPaperItem>
 */
class UserPaperItemFactory extends Factory
{
    protected $model = UserPaperItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_paper_id' => UserPaper::factory(),
            'type' => UserPaperType::PortraitImage->value,
            'file_name' => $this->faker->word() . '.jpg',
            'file_path' => 'papers/' . $this->faker->uuid() . '.jpg',
            'deleted_from_folder' => null,
            'approved_at' => now(),
            'deleted_at' => null,
        ];
    }
}
