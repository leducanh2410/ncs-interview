<?php

namespace Tests\Unit\Actions\Common\BankAccount;

use App\Actions\Common\BankAccount\TransitionBankAccountStatusAction;
use App\Enums\BankAccountStatus;
use App\Models\UserBankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransitionBankAccountStatusActionTest extends TestCase
{
    use RefreshDatabase;

    public function testTransitionsWhenStatusMatches(): void
    {
        $account = UserBankAccount::factory()->create(['status' => BankAccountStatus::Draft->value]);

        $changed = (new TransitionBankAccountStatusAction())(
            $account,
            BankAccountStatus::Draft,
            BankAccountStatus::OcrProcessing,
            ['ocr_run_count' => 1]
        );

        $this->assertTrue($changed);
        $this->assertSame(BankAccountStatus::OcrProcessing, $account->status);
        $this->assertSame(1, $account->ocr_run_count);
    }

    public function testAcceptsSeveralSourceStatuses(): void
    {
        $account = UserBankAccount::factory()->create(['status' => BankAccountStatus::OcrFailed->value]);

        $changed = (new TransitionBankAccountStatusAction())(
            $account,
            [BankAccountStatus::OcrReady, BankAccountStatus::OcrFailed],
            BankAccountStatus::WaitForApproval
        );

        $this->assertTrue($changed);
        $this->assertSame(BankAccountStatus::WaitForApproval, $account->fresh()->status);
    }

    public function testDoesNothingWhenStatusDiffers(): void
    {
        $account = UserBankAccount::factory()->create(['status' => BankAccountStatus::WaitForApproval->value]);

        $changed = (new TransitionBankAccountStatusAction())(
            $account,
            BankAccountStatus::OcrProcessing,
            BankAccountStatus::OcrReady,
            ['ocr_fields' => ['bank_name' => 'x']]
        );

        $this->assertFalse($changed);
        $fresh = $account->fresh();
        $this->assertSame(BankAccountStatus::WaitForApproval, $fresh->status);
        $this->assertNull($fresh->ocr_fields);
    }
}
