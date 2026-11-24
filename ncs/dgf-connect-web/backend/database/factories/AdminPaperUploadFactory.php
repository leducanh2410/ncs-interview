<?php

namespace Database\Factories;

use App\Enums\AdminPaperUploadStatus;
use App\Enums\UserPaperType;
use App\Models\AdminPaperUpload;
use App\Models\User;
use App\Services\External\OpenRouterOcrService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Produces a draft by default.
 *
 * The ocrReady() and submitted() states insert an intermediate status directly, which the
 * repository's test rules only permit because AdminPaperUploadLifecycleTest proves both
 * states are reachable through the real endpoints and the real job.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AdminPaperUpload>
 */
class AdminPaperUploadFactory extends Factory
{
    protected $model = AdminPaperUpload::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'paper_type' => UserPaperType::ForeignerCardScan->value,
            'file_name' => $this->faker->word() . '.jpg',
            'file_path' => 'admin-paper-uploads/' . $this->faker->uuid() . '.jpg',
            'status' => AdminPaperUploadStatus::OcrProcessing->value,
            'ocr_group' => OpenRouterOcrService::GROUP_RESIDENCE_CARD_SCAN,
            'ocr_run_count' => 1,
            'uploaded_by_id' => User::factory(),
            'submitted_at' => null,
        ];
    }

    public function ocrReady(array $fields = []): static
    {
        return $this->state(
            fn() => [
                'status' => AdminPaperUploadStatus::OcrReady->value,
                'ocr_fields' => $fields ?: self::sampleFields(),
                'ocr_warnings' => [],
                'ocr_completed_at' => now(),
            ]
        );
    }

    public function submitted(array $fields = []): static
    {
        return $this->state(function (array $attributes) use ($fields) {
            $confirmed = $fields ?: $attributes['ocr_fields'] ?? self::sampleFields();

            return [
                'status' => AdminPaperUploadStatus::Submitted->value,
                'ocr_fields' => $attributes['ocr_fields'] ?? $confirmed,
                'confirmed_fields' => $confirmed,
                'submitted_by_id' => $attributes['uploaded_by_id'] ?? User::factory(),
                'submitted_at' => now(),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function sampleFields(): array
    {
        return [
            'name' => 'NGUYEN VAN A',
            'last_name_kana' => null,
            'first_name_kana' => null,
            'birthday' => '1995-04-12',
            'gender' => 'male',
            'nationality' => 'vietnam',
            'address' => '1-2-3 Shibuya',
            'prefecture' => 'tokyo',
            'residence_number' => 'AB12345678CD',
            'residence_expiry_date' => '2030-01-31',
            'residence_status' => 'study_abroad',
            'residence_status_raw' => '留学',
        ];
    }
}
