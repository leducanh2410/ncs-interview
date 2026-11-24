<?php

namespace Tests\Feature\Admin;

use App\Enums\BankAccountStatus;
use App\Enums\ErrorCode;
use App\Enums\Locale;
use App\Enums\NameCheckVerdict;
use App\Enums\NotificationType;
use App\Enums\PayoutMethod;
use App\Enums\PermissionName;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\Concerns\FakesBankAccountOcr;
use Tests\TestCase;

class BankAccountTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use FakesBankAccountOcr;
    use RefreshDatabase;

    private const URL = '/admin/bank-accounts';

    private User $admin;

    private Workplace $workplaceA;

    private Workplace $workplaceB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->fakeBankAccountProvider();
        $this->admin = User::factory()->create();
        $this->workplaceA = $this->createWorkplaceAt(35.0, 139.0, 'Shop A');
        $this->workplaceB = $this->createWorkplaceAt(35.1, 139.1, 'Shop B');
    }

    public function testListRequiresPermission(): void
    {
        $this->actingAs($this->admin, 'api')->getJson(self::URL)->assertForbidden();
    }

    public function testDecisionsRequireApprovePermission(): void
    {
        $this->grant(PermissionName::BankAccountList);
        $account = $this->submittedAccount($this->employeeAt($this->workplaceA));

        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL . "/{$account->id}/approve")
            ->assertForbidden();
        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL . "/{$account->id}/reject", ['note' => 'x'])
            ->assertForbidden();
    }

    public function testListShowsLatestRecordPerUserWithMaskedNumberAndFilters(): void
    {
        $this->grant(PermissionName::BankAccountList);
        $alice = $this->employeeAt($this->workplaceA, 'ALICE');
        $bob = $this->employeeAt($this->workplaceB, 'BOB');
        $this->submittedAccount($alice);
        $this->actingAs($bob, 'api')->postJson('/user/bank-account/seven-bank')->assertOk();

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL);
        $response->assertOk();
        $response->assertJsonPath('data.total', 2);
        $rows = collect($response->json('data.data'))->keyBy('user.id');
        $this->assertSame('****567', $rows[$alice->id]['account_number_masked']);
        $this->assertSame(NameCheckVerdict::Match->value, $rows[$alice->id]['name_check_verdict']);
        $this->assertSame(PayoutMethod::SevenBankAtm->value, $rows[$bob->id]['payout_method']);
        $this->assertSame(
            [['id' => $this->workplaceA->id, 'name' => 'Shop A']],
            $rows[$alice->id]['user']['workplaces']
        );

        $this->actingAs($this->admin, 'api')
            ->getJson(self::URL . '?workplace_id[]=' . $this->workplaceB->id)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.user.id', $bob->id);
        $this->actingAs($this->admin, 'api')
            ->getJson(self::URL . '?status[]=' . BankAccountStatus::WaitForApproval->value)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.user.id', $alice->id);
        $this->actingAs($this->admin, 'api')
            ->getJson(self::URL . '?keyword=ALICE')
            ->assertJsonPath('data.total', 1);
        $this->actingAs($this->admin, 'api')
            ->getJson(self::URL . '?has_warning=true')
            ->assertJsonPath('data.total', 0);
    }

    public function testInProgressSubmissionsAreHiddenButAnApprovedRecordStaysListed(): void
    {
        $this->grant(PermissionName::BankAccountList);
        $inProgress = $this->employeeAt($this->workplaceA, 'CARL');
        $this->actingAs($inProgress, 'api')
            ->post(
                '/user/bank-account/images',
                [
                    'files' => [UploadedFile::fake()->image('card.jpg', 800, 500)],
                ],
                ['Accept' => 'application/json']
            )
            ->assertOk();
        $renewing = $this->employeeAt($this->workplaceA, 'DANA');
        $approved = UserBankAccount::factory()
            ->approved()
            ->create(['user_id' => $renewing->id]);
        $this->actingAs($renewing, 'api')
            ->post(
                '/user/bank-account/images',
                [
                    'files' => [UploadedFile::fake()->image('card.jpg', 800, 500)],
                ],
                ['Accept' => 'application/json']
            )
            ->assertOk();

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL);

        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.data.0.id', $approved->id);
        $response->assertJsonPath('data.data.0.status', BankAccountStatus::Approved->value);
    }

    public function testWarningFilterFindsNameMismatches(): void
    {
        $this->grant(PermissionName::BankAccountList);
        $this->submittedAccount($this->employeeAt($this->workplaceA, 'ALICE'));
        $suspicious = $this->submittedAccount($this->employeeAt($this->workplaceA, 'BOB'), [
            'account_holder_kana' => 'タナカ タロウ',
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . '?has_warning=true');

        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.data.0.id', $suspicious->id);
        $response->assertJsonPath('data.data.0.name_check_verdict', NameCheckVerdict::Mismatch->value);
        $response->assertJsonPath('data.data.0.mismatch_count', 1);
    }

    public function testDetailMasksEveryAccountNumberCopyWithoutApprovePermission(): void
    {
        $this->grant(PermissionName::BankAccountList);
        $employee = $this->employeeAt($this->workplaceA);
        UserBankAccount::factory()
            ->approved()
            ->create(['user_id' => $employee->id, 'account_number' => '1112223']);
        $account = $this->submittedAccount($employee, ['account_number' => '7654321']);

        $masked = $this->actingAs($this->admin, 'api')->getJson(self::URL . "/{$account->id}");

        $masked
            ->assertOk()
            ->assertJsonPath('data.account_number', null)
            ->assertJsonPath('data.account_number_masked', '****321')
            ->assertJsonPath('data.ocr_fields.account_number', '****567')
            ->assertJsonPath('data.confirmed_fields.account_number', '****321')
            ->assertJsonPath('data.input_mismatches.0.field', 'account_number')
            ->assertJsonPath('data.input_mismatches.0.ocr_value', '****567')
            ->assertJsonPath('data.input_mismatches.0.input_value', '****321')
            ->assertJsonPath('data.images', [])
            ->assertJsonPath('data.images_restricted', true)
            ->assertJsonPath('data.history.0.images', [])
            ->assertJsonPath('data.history.0.account_number_masked', '****223')
            ->assertJsonPath('data.user.workplaces.0.name', 'Shop A');
        foreach (['1234567', '7654321', '1112223'] as $number) {
            $this->assertStringNotContainsString($number, $masked->getContent());
        }

        $this->grant(PermissionName::BankAccountApprove);
        $this->actingAs($this->admin, 'api')
            ->getJson(self::URL . "/{$account->id}")
            ->assertJsonPath('data.account_number', '7654321')
            ->assertJsonPath('data.ocr_fields.account_number', '1234567')
            ->assertJsonPath('data.confirmed_fields.account_number', '7654321')
            ->assertJsonPath('data.images_restricted', false)
            ->assertJsonCount(1, 'data.images');
    }

    public function testApproveSupersedesPreviousAndNotifiesEmployee(): void
    {
        $this->grant(PermissionName::BankAccountList);
        $this->grant(PermissionName::BankAccountApprove);
        $employee = $this->employeeAt($this->workplaceA);
        $previous = UserBankAccount::factory()
            ->approved()
            ->create(['user_id' => $employee->id]);
        $account = $this->submittedAccount($employee);

        $response = $this->actingAs($this->admin, 'api')->postJson(self::URL . "/{$account->id}/approve");

        $response->assertOk()->assertJsonPath('data.status', BankAccountStatus::Approved->value);
        $response->assertJsonPath('data.approver_id', $this->admin->id);
        $this->assertNotNull($previous->fresh()->superseded_at);
        $this->assertNull($account->fresh()->superseded_at);
        $notification = Notification::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertSame(NotificationType::BANK_ACCOUNT_APPROVED->value, $notification->type);
        $this->assertSame(count(Locale::getTranslationLocales()), $notification->translations()->count());

        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL . "/{$account->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error', ErrorCode::BankAccountInvalidState->value);

        $this->actingAs($this->admin, 'api')
            ->getJson(self::URL . "/{$account->id}")
            ->assertJsonPath('data.history.0.id', $previous->id);
    }

    public function testRejectRequiresNoteAndNotifiesWithIt(): void
    {
        $this->grant(PermissionName::BankAccountList);
        $this->grant(PermissionName::BankAccountApprove);
        $employee = $this->employeeAt($this->workplaceA);
        $account = $this->submittedAccount($employee);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL . "/{$account->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['note']]);

        $response = $this->actingAs($this->admin, 'api')->postJson(self::URL . "/{$account->id}/reject", [
            'note' => '口座名義が一致しません',
        ]);

        $response->assertOk()->assertJsonPath('data.status', BankAccountStatus::Rejected->value);
        $response->assertJsonPath('data.rejected_note', '口座名義が一致しません');
        $notification = Notification::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertSame(NotificationType::BANK_ACCOUNT_REJECTED->value, $notification->type);
        $this->assertStringContainsString('口座名義が一致しません', $notification->content);
        $this->assertStringContainsString(
            '口座名義が一致しません',
            $notification->translations()->where('lang', 'ja')->firstOrFail()->content
        );

        $this->actingAs($employee, 'api')
            ->getJson('/user/bank-account')
            ->assertJsonPath('data.current.status', BankAccountStatus::Rejected->value)
            ->assertJsonPath('data.current.rejected_note', '口座名義が一致しません');
    }

    public function testRejectWithTheLongestAllowedNoteFitsTheNotification(): void
    {
        $this->grant(PermissionName::BankAccountList);
        $this->grant(PermissionName::BankAccountApprove);
        $employee = $this->employeeAt($this->workplaceA);
        $account = $this->submittedAccount($employee);
        $note = str_repeat('名義', 250);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL . "/{$account->id}/reject", ['note' => $note])
            ->assertOk()
            ->assertJsonPath('data.status', BankAccountStatus::Rejected->value);

        $this->assertSame($note, $account->fresh()->rejected_note);
        $notification = Notification::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertLessThanOrEqual(255, mb_strlen($notification->content));
        $this->assertStringContainsString('名義名義', $notification->content);
        $this->assertStringContainsString('…', $notification->content);
        foreach ($notification->translations as $translation) {
            $this->assertLessThanOrEqual(255, mb_strlen($translation->content));
        }
    }

    public function testResubmissionAfterRejectionClearsThePreviousDecider(): void
    {
        $this->grant(PermissionName::BankAccountList);
        $this->grant(PermissionName::BankAccountApprove);
        $employee = $this->employeeAt($this->workplaceA);
        $account = $this->submittedAccount($employee);
        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL . "/{$account->id}/reject", ['note' => 'Blurry photo'])
            ->assertOk()
            ->assertJsonPath('data.approver_id', $this->admin->id);

        $resubmitted = $this->submittedAccount($employee);

        $this->assertSame($account->id, $resubmitted->id);
        $this->actingAs($this->admin, 'api')
            ->getJson(self::URL . "/{$account->id}")
            ->assertJsonPath('data.status', BankAccountStatus::WaitForApproval->value)
            ->assertJsonPath('data.approver_id', null)
            ->assertJsonPath('data.approver', null)
            ->assertJsonPath('data.approved_at', null)
            ->assertJsonPath('data.rejected_note', null);
    }

    public function testSevenBankRecordsCannotBeDecided(): void
    {
        $this->grant(PermissionName::BankAccountList);
        $this->grant(PermissionName::BankAccountApprove);
        $employee = $this->employeeAt($this->workplaceA);
        $this->actingAs($employee, 'api')->postJson('/user/bank-account/seven-bank')->assertOk();
        $account = UserBankAccount::query()->firstOrFail();

        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL . "/{$account->id}/approve")
            ->assertStatus(409);
        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL . "/{$account->id}/reject", ['note' => 'x'])
            ->assertStatus(409);
    }

    private function grant(PermissionName $permission): void
    {
        $perm = Permission::firstOrCreate(['name' => $permission->value], ['display_name' => $permission->value]);
        $this->admin->permissions()->syncWithoutDetaching([$perm->id]);
        $this->admin->unsetRelation('permissions');
    }

    private function employeeAt(Workplace $workplace, string $firstName = 'VAN AN'): User
    {
        $employee = $this->createEmployeeAt($workplace);
        $employee->update([
            'first_name' => $firstName,
            'last_name' => 'NGUYEN',
            'first_name_kana' => 'バン アン',
            'last_name_kana' => 'グエン',
        ]);
        $employee->info()->update(['phone' => '08012345678']);

        return $employee->refresh();
    }

    private function submittedAccount(User $employee, array $overrides = []): UserBankAccount
    {
        $upload = $this->actingAs($employee, 'api')->post(
            '/user/bank-account/images',
            [
                'files' => [UploadedFile::fake()->image('card.jpg', 800, 500)],
            ],
            ['Accept' => 'application/json']
        );
        $upload->assertOk();

        $this->actingAs($employee, 'api')
            ->postJson(
                '/user/bank-account/submit',
                array_merge(
                    [
                        'ocr_result_id' => $upload->json('data.current.ocr_result_id'),
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
            )
            ->assertOk();

        return UserBankAccount::query()->where('user_id', $employee->id)->orderByDesc('id')->firstOrFail();
    }
}
