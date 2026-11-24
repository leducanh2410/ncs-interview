<?php

namespace Database\Factories;

use App\Enums\BankAccountStatus;
use App\Enums\BankAccountType;
use App\Enums\PayoutMethod;
use App\Models\User;
use App\Models\UserBankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserBankAccount>
 */
class UserBankAccountFactory extends Factory
{
    protected $model = UserBankAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'payout_method' => PayoutMethod::BankTransfer->value,
            'status' => BankAccountStatus::Draft->value,
            'ocr_run_count' => 0,
            'is_yucho' => false,
            'entered_manually' => false,
        ];
    }

    public function approved(): static
    {
        return $this->state(
            fn() => [
                'status' => BankAccountStatus::Approved->value,
                'bank_name' => '三菱UFJ銀行',
                'bank_code' => '0005',
                'branch_name' => '新宿支店',
                'branch_code' => '021',
                'account_type' => BankAccountType::Ordinary->value,
                'account_number' => '1234567',
                'account_holder_kana' => 'グエン バン アン',
                'submitted_at' => now(),
                'approved_at' => now(),
            ]
        );
    }

    public function sevenBank(): static
    {
        return $this->state(
            fn() => [
                'payout_method' => PayoutMethod::SevenBankAtm->value,
                'status' => BankAccountStatus::Approved->value,
                'submitted_at' => now(),
                'approved_at' => now(),
            ]
        );
    }
}
