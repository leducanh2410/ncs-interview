<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\Announcement;
use App\Models\AnnouncementTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnnouncementTranslationFactory extends Factory
{
    protected $model = AnnouncementTranslation::class;

    public function definition(): array
    {
        return [
            'announcement_id' => Announcement::factory(),
            'lang' => Locale::Japanese->value,
            'title' => $this->faker->sentence(4),
            'content' => '<p>' . $this->faker->paragraph() . '</p>',
        ];
    }
}
