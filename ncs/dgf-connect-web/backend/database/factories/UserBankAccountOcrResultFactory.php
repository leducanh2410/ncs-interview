<?php

namespace Database\Factories;

use App\Enums\OcrResultStatus;
use App\Models\UserBankAccount;
use App\Models\UserBankAccountOcrResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserBankAccountOcrResult>
 */
class UserBankAccountOcrResultFactory extends Factory
{
    protected $model = UserBankAccountOcrResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_bank_account_id' => UserBankAccount::factory(),
            'image_ids' => [],
            'status' => OcrResultStatus::Processing->value,
        ];
    }
}
