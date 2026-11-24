<?php

namespace Tests\Feature\User\BankAccount;

use App\Actions\Common\BankAccount\RunBankAccountOcrAction;
use App\Enums\BankAccountImageFolder;
use App\Enums\BankAccountStatus;
use App\Enums\ErrorCode;
use App\Enums\NameCheckVerdict;
use App\Enums\OcrResultStatus;
use App\Enums\PayoutMethod;
use App\Jobs\ExtractBankAccountOcrJob;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\UserBankAccountOcrResult;
use App\Models\UserInfo;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\Concerns\FakesBankAccountOcr;
use Tests\TestCase;
use Throwable;

class BankAccountLifecycleTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use FakesBankAccountOcr;
    use RefreshDatabase;

    private const URL = '/user/bank-account';

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->fakeBankAccountProvider();

        $workplace = Workplace::create([
            'name' => 'Shop',
            'address' => 'Addr',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->employee = $this->createEmployeeAt($workplace);
        $this->employee->update([
            'first_name' => 'VAN AN',
            'last_name' => 'NGUYEN',
            'first_name_kana' => 'バン アン',
            'last_name_kana' => 'グエン',
        ]);
        UserInfo::query()
            ->where('user_id', $this->employee->id)
            ->update(['phone' => '08012345678']);
        $this->employee->refresh();
    }

    public function testEmptyStateHasNoRecords(): void
    {
        $this->apiGet(self::URL)
            ->assertOk()
            ->assertJsonPath('data.current', null)
            ->assertJsonPath('data.effective', null);
    }

    public function testChoosingSevenBankIsApprovedImmediately(): void
    {
        $response = $this->apiPost(self::URL . '/seven-bank');

        $response->assertOk();
        $response->assertJsonPath('data.current.payout_method', PayoutMethod::SevenBankAtm->value);
        $response->assertJsonPath('data.current.status', BankAccountStatus::Approved->value);
        $response->assertJsonPath('data.effective.id', $response->json('data.current.id'));
        $response->assertJsonPath('data.current.can_reupload', false);
    }

    public function testSevenBankRequiresAPhoneNumber(): void
    {
        UserInfo::query()
            ->where('user_id', $this->employee->id)
            ->update(['phone' => null]);
        $this->employee->refresh();

        $this->apiPost(self::URL . '/seven-bank')
            ->assertStatus(422)
            ->assertJsonPath('error', ErrorCode::BankAccountPhoneRequired->value);
        $this->assertSame(0, UserBankAccount::query()->count());
    }

    public function testBankTransferHappyPath(): void
    {
        $upload = $this->upload();
        $upload->assertOk();
        $upload->assertJsonPath('data.current.status', BankAccountStatus::OcrReady->value);
        $upload->assertJsonPath('data.current.ocr_run_count', 1);
        $upload->assertJsonPath('data.current.ocr_fields.account_number', '1234567');
        $upload->assertJsonPath('data.current.ocr_fields.holder_name_kana', 'グエン バン アン');
        $upload->assertJsonCount(1, 'data.current.images');
        Storage::disk('local')->assertExists($upload->json('data.current.images.0.file_path'));

        $submit = $this->submit(['ocr_result_id' => $upload->json('data.current.ocr_result_id')]);
        $submit->assertOk();
        $submit->assertJsonPath('data.current.status', BankAccountStatus::WaitForApproval->value);
        $submit->assertJsonPath('data.current.name_check.verdict', NameCheckVerdict::Match->value);
        $submit->assertJsonPath('data.current.account_number', null);
        $submit->assertJsonPath('data.current.account_number_masked', '****567');
        $submit->assertJsonPath('data.current.entered_manually', false);
        $submit->assertJsonPath('data.current.input_mismatches', []);
        $submit->assertJsonPath('data.effective', null);

        $account = UserBankAccount::query()->firstOrFail();
        $this->assertSame('1234567', $account->account_number);
        $this->assertNotSame('1234567', $account->getRawOriginal('account_number'));
        $this->assertSame('ordinary', $account->account_type->value);
        $this->assertArrayNotHasKey('ai', $account->name_check['kana']);
    }

    public function testOwnerSeesUnmaskedNumberOnlyWhileConfirming(): void
    {
        $upload = $this->upload();
        $this->submit(['ocr_result_id' => $upload->json('data.current.ocr_result_id'), 'account_number' => '7654321']);

        $fresh = $this->apiGet(self::URL);
        $fresh->assertJsonPath('data.current.account_number', null);
        $fresh->assertJsonPath('data.current.account_number_masked', '****321');
        $fresh->assertJsonPath('data.current.images', []);
        $fresh->assertJsonPath('data.current.images_restricted', true);
    }

    public function testOwnerSeesOnlyMaskedCopiesOnceSubmitted(): void
    {
        $this->approvedTransfer();

        $response = $this->apiGet(self::URL);

        $response->assertJsonPath('data.effective.account_number', null);
        $response->assertJsonPath('data.effective.ocr_fields.account_number', '****567');
        $response->assertJsonPath('data.effective.confirmed_fields.account_number', '****567');
        $response->assertJsonPath('data.effective.images', []);
        $response->assertJsonPath('data.effective.images_restricted', true);
        $this->assertStringNotContainsString('1234567', $response->getContent());
    }

    public function testAccountNumberCopiesAreEncryptedAtRest(): void
    {
        $this->bankOcrFields = ['is_yucho' => true, 'yucho_symbol' => '10140', 'yucho_number' => '87654321'];
        $upload = $this->upload();

        $this->submit([
            'ocr_result_id' => $upload->json('data.current.ocr_result_id'),
            'account_number' => '7654321',
            'is_yucho' => true,
            'yucho_symbol' => '10140',
            'yucho_number' => '12345678',
        ])->assertOk();

        $account = UserBankAccount::query()->firstOrFail();
        $this->assertSame('7654321', $account->confirmed_fields['account_number']);
        $this->assertSame('1234567', $account->ocr_fields['account_number']);
        $this->assertSame('12345678', $account->yucho_number);
        $rawAccount = (array) DB::table('user_bank_accounts')->where('id', $account->id)->first();
        $rawResults = DB::table('user_bank_account_ocr_results')->pluck('fields')->implode(' ');
        foreach (['1234567', '7654321', '12345678', '87654321'] as $number) {
            $this->assertStringNotContainsString($number, implode(' ', array_filter($rawAccount, 'is_string')));
            $this->assertStringNotContainsString($number, $rawResults);
        }
        $this->assertContains('account_number', array_column($account->input_mismatches, 'field'));
    }

    public function testTransientOcrFailureIsRetriedWithoutFailingTheAccount(): void
    {
        Queue::fake();
        $upload = $this->upload();
        $resultId = $upload->json('data.current.ocr_result_id');
        $this->providerFails = true;

        $firstAttempt = (new ExtractBankAccountOcrJob($resultId))->withFakeQueueInteractions();
        $thrown = false;
        try {
            $firstAttempt->handle(app(RunBankAccountOcrAction::class));
        } catch (Throwable) {
            $thrown = true;
        }

        $this->assertTrue($thrown);

        $this->apiGet(self::URL)->assertJsonPath('data.current.status', BankAccountStatus::OcrProcessing->value);

        $this->providerFails = false;
        $secondAttempt = (new ExtractBankAccountOcrJob($resultId))->withFakeQueueInteractions();
        $secondAttempt->job->attempts = 2;
        $secondAttempt->handle(app(RunBankAccountOcrAction::class));

        $this->apiGet(self::URL)
            ->assertJsonPath('data.current.status', BankAccountStatus::OcrReady->value)
            ->assertJsonPath('data.current.ocr_fields.account_number', '1234567');
        $this->assertSame(
            OcrResultStatus::Completed->value,
            UserBankAccountOcrResult::query()->findOrFail($resultId)->status
        );
    }

    public function testTypedValuesThatDifferFromThePhotoAreRecorded(): void
    {
        $upload = $this->upload();

        $submit = $this->submit([
            'ocr_result_id' => $upload->json('data.current.ocr_result_id'),
            'account_number' => '7654321',
            'account_holder_kana' => 'タナカ タロウ',
        ]);

        $submit->assertOk();
        $submit->assertJsonPath('data.current.name_check.verdict', NameCheckVerdict::Mismatch->value);
        $account = UserBankAccount::query()->firstOrFail();
        $this->assertSame(['account_number', 'account_holder_kana'], array_column($account->input_mismatches, 'field'));
        $this->assertTrue($account->name_check['ai']['available']);
        $this->assertSame('uncertain', $account->name_check['ai']['verdict']);
    }

    public function testAiJudgementFailureDoesNotBlockSubmission(): void
    {
        $upload = $this->upload();
        $this->providerFails = true;

        $submit = $this->submit([
            'ocr_result_id' => $upload->json('data.current.ocr_result_id'),
            'account_holder_kana' => 'タナカ タロウ',
        ]);

        $submit->assertOk()->assertJsonPath('data.current.status', BankAccountStatus::WaitForApproval->value);
        $this->assertFalse(UserBankAccount::query()->firstOrFail()->name_check['ai']['available']);
    }

    public function testNonOrdinaryAccountTypeIsRejected(): void
    {
        $this->bankOcrFields = ['account_type' => 'time_deposit'];
        $upload = $this->upload();
        $upload->assertJsonPath('data.current.ocr_fields.account_type', 'time_deposit');

        $this->submit(['ocr_result_id' => $upload->json('data.current.ocr_result_id')])
            ->assertStatus(422)
            ->assertJsonPath('error', ErrorCode::BankAccountTypeNotAllowed->value);
        $this->assertSame(BankAccountStatus::OcrReady, UserBankAccount::query()->firstOrFail()->status);
    }

    public function testSubmitValidatesFields(): void
    {
        $upload = $this->upload();

        $this->submit([
            'ocr_result_id' => $upload->json('data.current.ocr_result_id'),
            'account_number' => '12345678',
            'account_holder_kana' => 'nguyen',
            'branch_name' => null,
            'branch_code' => null,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['account_number', 'account_holder_kana', 'branch_name']]);
    }

    public function testYuchoAccountsNeedSymbolAndNumberInsteadOfBranch(): void
    {
        $this->bankOcrFields = [
            'bank_name' => 'ゆうちょ銀行',
            'is_yucho' => true,
            'yucho_symbol' => '10230',
            'yucho_number' => '12345671',
            'branch_name' => null,
            'branch_code' => null,
            'account_number' => null,
        ];
        $upload = $this->upload();

        $this->submit([
            'ocr_result_id' => $upload->json('data.current.ocr_result_id'),
            'bank_name' => 'ゆうちょ銀行',
            'branch_name' => null,
            'branch_code' => null,
            'is_yucho' => true,
            'yucho_symbol' => null,
            'yucho_number' => '12345671',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['yucho_symbol']]);

        $this->submit([
            'ocr_result_id' => $upload->json('data.current.ocr_result_id'),
            'bank_name' => 'ゆうちょ銀行',
            'branch_name' => null,
            'branch_code' => null,
            'account_number' => '1234567',
            'is_yucho' => true,
            'yucho_symbol' => '10230',
            'yucho_number' => '12345671',
        ])
            ->assertOk()
            ->assertJsonPath('data.current.yucho_symbol', '10230');
    }

    public function testStaleOcrResultIdIsRejected(): void
    {
        $upload = $this->upload();

        $this->submit(['ocr_result_id' => $upload->json('data.current.ocr_result_id') + 100])
            ->assertStatus(409)
            ->assertJsonPath('error', ErrorCode::BankAccountChanged->value);
    }

    public function testSubmitWithoutUploadIsNotConfirmable(): void
    {
        $this->submit([])->assertStatus(409)->assertJsonPath('error', ErrorCode::BankAccountNotConfirmable->value);
    }

    public function testUploadIsLockedWhileOcrRunsAndWhileWaitingForApproval(): void
    {
        Queue::fake();
        $first = $this->upload();
        $first->assertOk()->assertJsonPath('data.current.status', BankAccountStatus::OcrProcessing->value);
        Queue::assertPushed(ExtractBankAccountOcrJob::class, 1);

        $this->upload()->assertStatus(409)->assertJsonPath('error', ErrorCode::BankAccountLocked->value);
        $this->apiPost(self::URL . '/seven-bank')
            ->assertStatus(409)
            ->assertJsonPath('error', ErrorCode::BankAccountLocked->value);
        $this->apiDelete(self::URL . '/draft')->assertStatus(409);

        $this->runBankOcrJob($first->json('data.current.ocr_result_id'));
        $this->apiGet(self::URL)->assertJsonPath('data.current.status', BankAccountStatus::OcrReady->value);

        $this->submit(['ocr_result_id' => $first->json('data.current.ocr_result_id')])->assertOk();
        $this->upload()->assertStatus(409)->assertJsonPath('error', ErrorCode::BankAccountLocked->value);
        $this->submit(['ocr_result_id' => $first->json('data.current.ocr_result_id')])->assertStatus(409);
        $this->assertSame(1, UserBankAccount::query()->count());
    }

    public function testRetakeInvalidatesThePreviousRunAndMovesOldImages(): void
    {
        $first = $this->upload();
        $firstImage = $first->json('data.current.images.0.file_path');
        $firstResultId = $first->json('data.current.ocr_result_id');

        $second = $this->upload();

        $second->assertOk()->assertJsonPath('data.current.ocr_run_count', 2);
        $this->assertNotSame($firstResultId, $second->json('data.current.ocr_result_id'));
        $this->assertNotNull($this->latestBankOcrResult($second->json('data.current.id')));
        $account = UserBankAccount::query()->firstOrFail();
        $this->assertSame(1, $account->images()->where('folder', BankAccountImageFolder::Current->value)->count());
        $this->assertSame(
            BankAccountImageFolder::Old,
            $account->images()->where('file_path', $firstImage)->firstOrFail()->folder
        );
        $this->assertNotNull($account->ocrResults()->whereKey($firstResultId)->firstOrFail()->invalidated_at);
    }

    public function testOcrBudgetIsExhaustedAfterThreeRunsAndManualEntryStillWorks(): void
    {
        Queue::fake();
        $this->providerFails = true;
        $last = null;
        for ($i = 0; $i < 3; $i++) {
            $last = $this->upload();
            $last->assertOk()->assertJsonPath('data.current.status', BankAccountStatus::OcrProcessing->value);
            $this->runBankOcrJob($last->json('data.current.ocr_result_id'));
            $last = $this->apiGet(self::URL);
            $last->assertJsonPath('data.current.status', BankAccountStatus::OcrFailed->value);
        }
        $last->assertJsonPath('data.current.can_reupload', false);
        $this->assertNotNull($this->latestBankOcrResult($last->json('data.current.id'))->error);

        $this->upload()->assertStatus(422)->assertJsonPath('error', ErrorCode::BankAccountOcrBudgetSpent->value);

        $this->providerFails = false;
        $submit = $this->submit(['ocr_result_id' => $last->json('data.current.ocr_result_id')]);
        $submit->assertOk();
        $submit->assertJsonPath('data.current.entered_manually', true);
        $submit->assertJsonPath('data.current.input_mismatches', []);
    }

    public function testLateOcrCompletionDoesNotRevertAManualSubmission(): void
    {
        Queue::fake();
        $upload = $this->upload();
        $ocrResultId = $upload->json('data.current.ocr_result_id');
        $account = UserBankAccount::query()->firstOrFail();
        $account->forceFill(['status' => BankAccountStatus::OcrFailed->value])->save();

        $this->submit(['ocr_result_id' => $ocrResultId])->assertOk();
        $this->runBankOcrJob($ocrResultId);

        $account->refresh();
        $this->assertSame(BankAccountStatus::WaitForApproval, $account->status);
        $this->assertNull($account->ocr_fields);
    }

    public function testJobForAnInvalidatedResultIsANoOp(): void
    {
        Queue::fake();
        $first = $this->upload();
        $staleResultId = $first->json('data.current.ocr_result_id');
        $this->providerFails = true;
        $this->runBankOcrJob($staleResultId);
        $this->apiGet(self::URL)->assertJsonPath('data.current.status', BankAccountStatus::OcrFailed->value);

        $this->providerFails = false;
        $second = $this->upload();
        $second->assertOk()->assertJsonPath('data.current.status', BankAccountStatus::OcrProcessing->value);
        $this->runBankOcrJob($staleResultId);

        $account = UserBankAccount::query()->firstOrFail();
        $this->assertSame(BankAccountStatus::OcrProcessing, $account->status);
        $this->assertSame($second->json('data.current.ocr_result_id'), $account->ocr_result_id);
    }

    public function testDiscardingADraftRemovesRowAndFiles(): void
    {
        $upload = $this->upload();
        $path = $upload->json('data.current.images.0.file_path');

        $this->apiDelete(self::URL . '/draft')
            ->assertOk()
            ->assertJsonPath('data.current', null);

        $this->assertSame(0, UserBankAccount::query()->count());
        Storage::disk('local')->assertMissing($path);
    }

    public function testChangeAfterApprovalKeepsTheApprovedRecordEffective(): void
    {
        $approved = $this->approvedTransfer();

        $upload = $this->upload();
        $upload->assertOk();
        $this->assertNotSame($approved->id, $upload->json('data.current.id'));
        $upload->assertJsonPath('data.effective.id', $approved->id);
        $upload->assertJsonPath('data.current.ocr_run_count', 1);

        $this->submit(['ocr_result_id' => $upload->json('data.current.ocr_result_id')])
            ->assertOk()
            ->assertJsonPath('data.effective.id', $approved->id);
        $this->assertNull($approved->fresh()->superseded_at);
    }

    public function testSwitchingToSevenBankSupersedesTheApprovedTransferImmediately(): void
    {
        $approved = $this->approvedTransfer();

        $response = $this->apiPost(self::URL . '/seven-bank');

        $response->assertOk()->assertJsonPath('data.effective.payout_method', PayoutMethod::SevenBankAtm->value);
        $this->assertNotNull($approved->fresh()->superseded_at);
    }

    public function testResubmissionAfterRejectionReusesTheRowAndResetsTheBudget(): void
    {
        $first = $this->upload();
        $this->submit(['ocr_result_id' => $first->json('data.current.ocr_result_id')])->assertOk();
        $account = UserBankAccount::query()->firstOrFail();
        $account
            ->forceFill([
                'status' => BankAccountStatus::Rejected->value,
                'rejected_note' => 'Name mismatch',
                'ocr_run_count' => 3,
            ])
            ->save();

        $retry = $this->upload();

        $retry->assertOk();
        $retry->assertJsonPath('data.current.id', $account->id);
        $retry->assertJsonPath('data.current.ocr_run_count', 1);
        $retry->assertJsonPath('data.current.rejected_note', null);
        $this->assertSame(1, UserBankAccount::query()->count());
        $this->assertSame(1, $account->images()->where('folder', BankAccountImageFolder::Old->value)->count());
    }

    public function testStorageFailureRollsBackTheUpload(): void
    {
        $this->app->bind(\App\Actions\Common\BankAccount\TransitionBankAccountStatusAction::class, function () {
            return new class extends \App\Actions\Common\BankAccount\TransitionBankAccountStatusAction {
                public function __invoke($bankAccount, $from, $to, array $extra = []): bool
                {
                    throw new \RuntimeException('disk full');
                }
            };
        });

        $this->upload()->assertStatus(500);

        $this->assertSame(0, UserBankAccount::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('users'));
    }

    private function upload(): TestResponse
    {
        return $this->actingAs($this->employee, 'api')->post(
            self::URL . '/images',
            [
                'files' => [UploadedFile::fake()->image('card.jpg', 1000, 600)],
            ],
            ['Accept' => 'application/json']
        );
    }

    private function submit(array $overrides): TestResponse
    {
        return $this->actingAs($this->employee, 'api')->postJson(
            self::URL . '/submit',
            array_merge(
                [
                    'bank_name' => '三菱UFJ銀行',
                    'bank_code' => '0005',
                    'branch_name' => '新宿支店',
                    'branch_code' => '021',
                    'account_number' => '1234567',
                    'account_holder_kana' => 'グエン バン アン',
                    'is_yucho' => false,
                ],
                $overrides
            )
        );
    }

    private function approvedTransfer(): UserBankAccount
    {
        $upload = $this->upload();
        $this->submit(['ocr_result_id' => $upload->json('data.current.ocr_result_id')])->assertOk();
        $account = UserBankAccount::query()->firstOrFail();
        $account->forceFill(['status' => BankAccountStatus::Approved->value, 'approved_at' => now()])->save();

        return $account;
    }

    private function apiGet(string $uri): TestResponse
    {
        return $this->actingAs($this->employee, 'api')->json('GET', $uri);
    }

    private function apiPost(string $uri, array $data = []): TestResponse
    {
        return $this->actingAs($this->employee, 'api')->json('POST', $uri, $data);
    }

    private function apiDelete(string $uri): TestResponse
    {
        return $this->actingAs($this->employee, 'api')->json('DELETE', $uri);
    }
}
