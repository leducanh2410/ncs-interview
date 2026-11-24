<?php

namespace Database\Factories;

use App\Enums\BankAccountImageFolder;
use App\Models\UserBankAccount;
use App\Models\UserBankAccountImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserBankAccountImage>
 */
class UserBankAccountImageFactory extends Factory
{
    protected $model = UserBankAccountImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_bank_account_id' => UserBankAccount::factory(),
            'file_name' => $this->faker->word() . '.jpg',
            'file_path' => 'users/1/bank/' . $this->faker->uuid() . '.jpg',
            'folder' => BankAccountImageFolder::Current->value,
        ];
    }
}
