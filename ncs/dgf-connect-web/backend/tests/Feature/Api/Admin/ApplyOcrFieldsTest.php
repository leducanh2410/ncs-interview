<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\Nationality;
use App\Enums\OcrResultStatus;
use App\Enums\PermissionName;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperType;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Models\UserPaperOcrResult;
use App\Support\Residence\ResidenceSlotResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ResetsUserDocumentInfo;
use Tests\TestCase;

class ApplyOcrFieldsTest extends TestCase
{
    use RefreshDatabase;
    use ResetsUserDocumentInfo;

    public function testForbiddenWithoutPermission(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
            'ocr_result_id' => 1,
            'fields' => ['birthday'],
        ]);

        $response->assertStatus(403);
    }

    public function testRejectsUnknownOcrResult(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
            'ocr_result_id' => 999,
            'fields' => ['birthday'],
        ]);

        $response->assertStatus(422);
    }

    public function testRejectsResultBelongingToAnotherUser(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $result = $this->createCompletedResult($owner, 'residence_card', ['birthday' => '1998-04-12']);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$other->id}/papers/ocr/apply", [
            'ocr_result_id' => $result->id,
            'fields' => ['birthday'],
        ]);

        $response->assertStatus(422);
    }

    public function testRejectsInvalidatedResult(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $this->resetUserDocumentInfo($user);
        $originalBirthday = $user->refresh()->info->birthday;
        $result = $this->createCompletedResult($user, 'residence_card', ['birthday' => '1998-04-12']);
        $result->update(['invalidated_at' => now()]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
            'ocr_result_id' => $result->id,
            'fields' => ['birthday'],
        ]);

        $response->assertStatus(422);
        $this->assertSame($originalBirthday, $user->refresh()->info->birthday);
    }

    public function testRejectsFailedResult(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $result = $this->createCompletedResult($user, 'residence_card', ['birthday' => '1998-04-12']);
        $result->update(['status' => OcrResultStatus::Failed->value]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
            'ocr_result_id' => $result->id,
            'fields' => ['birthday'],
        ]);

        $response->assertStatus(422);
    }

    public function testRejectsDisallowedAndUnreadFields(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $result = $this->createCompletedResult($user, 'residence_card', [
            'name' => 'NGUYEN VAN A',
            'birthday' => '1998-04-12',
            'gender' => null,
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
            'ocr_result_id' => $result->id,
            // name is never appliable, gender was not read, passport_number belongs to another group
            'fields' => ['name', 'gender', 'passport_number', 'birthday'],
        ]);

        $response->assertStatus(422);
        $this->assertEqualsCanonicalizing(
            ['name', 'gender', 'passport_number'],
            $response->json('data.invalid_fields')
        );
    }

    public function testAppliesUnknownCardNumberToLatestSlotAndInfoColumns(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2025-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'BB22222222BB',
            'residence_expiry_date_2' => '2027-01-01',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
        ]);
        $result = $this->createCompletedResult($user, 'residence_card', [
            'birthday' => '1998-04-12',
            'prefecture' => '東京都',
            'postal_code' => '1600023',
            'residence_number' => 'CC33333333CC',
            'residence_expiry_date' => '2029-01-31',
            'residence_status' => ResidenceStatus::FamilyStay->value,
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
            'ocr_result_id' => $result->id,
            'fields' => [
                'birthday',
                'prefecture',
                'postal_code',
                'residence_number',
                'residence_expiry_date',
                'residence_status',
            ],
        ]);

        $response->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('1998-04-12', $info->birthday);
        $this->assertSame('東京都', $info->prefecture);
        $this->assertSame('1600023', $info->postal_code);
        $this->assertSame('AA11111111AA', $info->residence_number_1);
        $this->assertSame('2025-01-01', $info->residence_expiry_date_1);
        $this->assertSame('CC33333333CC', $info->residence_number_2);
        $this->assertSame('2029-01-31', $info->residence_expiry_date_2);
        $this->assertSame(ResidenceStatus::FamilyStay->value, $info->residence_status_2);
        $this->assertNull($info->residence_number_3);
        $this->assertNull($info->residence_expiry_date_3);
    }

    public function testAppliesKnownCardNumberToItsOwnSlotInsteadOfLatest(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2025-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'BB22222222BB',
            'residence_expiry_date_2' => '2027-01-01',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
        ]);
        $result = $this->createCompletedResult($user, 'residence_card', [
            'residence_number' => 'AA11111111AA',
            'residence_expiry_date' => '2026-06-30',
            'residence_status' => ResidenceStatus::FamilyStay->value,
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
                'ocr_result_id' => $result->id,
                'fields' => ['residence_number', 'residence_expiry_date', 'residence_status'],
            ])
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('AA11111111AA', $info->residence_number_1);
        $this->assertSame('2026-06-30', $info->residence_expiry_date_1);
        $this->assertSame(ResidenceStatus::FamilyStay->value, $info->residence_status_1);
        $this->assertSame('BB22222222BB', $info->residence_number_2);
        $this->assertSame('2027-01-01', $info->residence_expiry_date_2);
        $this->assertSame(ResidenceStatus::StudyAbroad->value, $info->residence_status_2);
        $this->assertNull($info->residence_number_3);
    }

    public function testAppliesCardNumberThatDiffersOnlyInCaseToItsOwnSlot(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_number_1' => 'AB12345678CD',
            'residence_expiry_date_1' => '2028-01-31',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
        ]);
        $result = $this->createCompletedResult($user, 'residence_card', [
            'residence_number' => 'ab12345678cd',
            'residence_expiry_date' => '2030-01-01',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
                'ocr_result_id' => $result->id,
                'fields' => ['residence_number', 'residence_expiry_date'],
            ])
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('ab12345678cd', $info->residence_number_1);
        $this->assertSame('2030-01-01', $info->residence_expiry_date_1);
        $this->assertNull($info->residence_number_2);
        $this->assertNull($info->residence_expiry_date_2);
        $this->assertNull($info->residence_number_3);
    }

    public function testApplyingCardHeldInSeveralSlotsCollapsesTheCopies(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2028-01-31',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'AA11111111AA',
            'residence_expiry_date_2' => '2028-01-31',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
            'residence_number_3' => 'BB22222222BB',
            'residence_expiry_date_3' => '2026-01-01',
            'residence_status_3' => ResidenceStatus::FamilyStay->value,
        ]);
        $result = $this->createCompletedResult($user, 'residence_card', [
            'residence_number' => 'AA11111111AA',
            'residence_expiry_date' => '2030-01-01',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
                'ocr_result_id' => $result->id,
                'fields' => ['residence_number', 'residence_expiry_date'],
            ])
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('AA11111111AA', $info->residence_number_1);
        $this->assertSame('2030-01-01', $info->residence_expiry_date_1);
        $this->assertSame(ResidenceStatus::StudyAbroad->value, $info->residence_status_1);
        $this->assertSame('BB22222222BB', $info->residence_number_2);
        $this->assertSame('2026-01-01', $info->residence_expiry_date_2);
        $this->assertSame(ResidenceStatus::FamilyStay->value, $info->residence_status_2);
        $this->assertNull($info->residence_number_3);
        $this->assertNull($info->residence_expiry_date_3);
        $this->assertNull($info->residence_status_3);
    }

    public function testApplyingOnlyNumberToDifferingCopiesKeepsEveryCopyUntouched(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2025-01-31',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'AA11111111AA',
            'residence_expiry_date_2' => '2028-01-31',
            'residence_status_2' => ResidenceStatus::FamilyStay->value,
        ]);
        $result = $this->createCompletedResult($user, 'residence_card', [
            'residence_number' => 'AA11111111AA',
            'residence_expiry_date' => '2025-01-31',
            'residence_status' => ResidenceStatus::StudyAbroad->value,
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
                'ocr_result_id' => $result->id,
                'fields' => ['residence_number'],
            ])
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('AA11111111AA', $info->residence_number_1);
        $this->assertSame('2025-01-31', $info->residence_expiry_date_1);
        $this->assertSame(ResidenceStatus::StudyAbroad->value, $info->residence_status_1);
        $this->assertSame('AA11111111AA', $info->residence_number_2);
        $this->assertSame('2028-01-31', $info->residence_expiry_date_2);
        $this->assertSame(ResidenceStatus::FamilyStay->value, $info->residence_status_2);
        $this->assertSame(2, ResidenceSlotResolver::latestSlot($info));
    }

    public function testApplyingNumberAndExpiryToCopiesWithDifferentStatusKeepsEachStatus(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2025-01-31',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'AA11111111AA',
            'residence_expiry_date_2' => '2028-01-31',
            'residence_status_2' => ResidenceStatus::FamilyStay->value,
        ]);
        $result = $this->createCompletedResult($user, 'residence_card', [
            'residence_number' => 'AA11111111AA',
            'residence_expiry_date' => '2029-01-31',
            'residence_status' => ResidenceStatus::StudyAbroad->value,
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
                'ocr_result_id' => $result->id,
                'fields' => ['residence_number', 'residence_expiry_date'],
            ])
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('2029-01-31', $info->residence_expiry_date_1);
        $this->assertSame(ResidenceStatus::StudyAbroad->value, $info->residence_status_1);
        $this->assertSame('AA11111111AA', $info->residence_number_2);
        $this->assertSame('2029-01-31', $info->residence_expiry_date_2);
        $this->assertSame(ResidenceStatus::FamilyStay->value, $info->residence_status_2);
        $this->assertSame(
            ResidenceStatus::FamilyStay->value,
            ResidenceSlotResolver::latestStatus($info),
            'The unselected, corrected status of the current copy must still be the current status'
        );
    }

    public function testApplyingAllCardFieldsToDifferingCopiesCollapsesThem(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2025-01-31',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'BB22222222BB',
            'residence_expiry_date_2' => '2026-01-31',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
            'residence_number_3' => 'AA11111111AA',
            'residence_expiry_date_3' => '2028-01-31',
            'residence_status_3' => ResidenceStatus::FamilyStay->value,
        ]);
        $result = $this->createCompletedResult($user, 'residence_card', [
            'residence_number' => 'AA11111111AA',
            'residence_expiry_date' => '2029-01-31',
            'residence_status' => ResidenceStatus::FamilyStay->value,
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
                'ocr_result_id' => $result->id,
                'fields' => ['residence_number', 'residence_expiry_date', 'residence_status'],
            ])
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('AA11111111AA', $info->residence_number_1);
        $this->assertSame('2029-01-31', $info->residence_expiry_date_1);
        $this->assertSame(ResidenceStatus::FamilyStay->value, $info->residence_status_1);
        $this->assertSame('BB22222222BB', $info->residence_number_2);
        $this->assertSame('2026-01-31', $info->residence_expiry_date_2);
        $this->assertNull($info->residence_number_3);
        $this->assertNull($info->residence_expiry_date_3);
        $this->assertNull($info->residence_status_3);
    }

    public function testApplyingStatusWithoutNumberUpdatesSlotHoldingTheReadNumber(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2027-01-31',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'BB22222222BB',
            'residence_expiry_date_2' => '2028-01-31',
            'residence_status_2' => ResidenceStatus::FamilyStay->value,
        ]);
        $result = $this->createCompletedResult($user, 'residence_card', [
            'residence_number' => 'AA11111111AA',
            'residence_expiry_date' => '2027-01-31',
            'residence_status' => ResidenceStatus::FamilyStay->value,
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
                'ocr_result_id' => $result->id,
                'fields' => ['residence_status'],
            ])
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('AA11111111AA', $info->residence_number_1);
        $this->assertSame(ResidenceStatus::FamilyStay->value, $info->residence_status_1);
        $this->assertSame('BB22222222BB', $info->residence_number_2);
        $this->assertSame('2028-01-31', $info->residence_expiry_date_2);
        $this->assertSame(ResidenceStatus::FamilyStay->value, $info->residence_status_2);
        $this->assertNull($info->residence_number_3);
    }

    public function testApplyingUnknownCardNumberWhenAllSlotsAreFullOverwritesLatestWithoutRotating(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2024-01-01',
            'residence_number_2' => 'BB22222222BB',
            'residence_expiry_date_2' => '2028-01-01',
            'residence_number_3' => 'CC33333333CC',
            'residence_expiry_date_3' => '2026-01-01',
        ]);
        $result = $this->createCompletedResult($user, 'residence_card', [
            'residence_number' => 'DD44444444DD',
            'residence_expiry_date' => '2031-01-01',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
                'ocr_result_id' => $result->id,
                'fields' => ['residence_number', 'residence_expiry_date'],
            ])
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('AA11111111AA', $info->residence_number_1);
        $this->assertSame('DD44444444DD', $info->residence_number_2);
        $this->assertSame('2031-01-01', $info->residence_expiry_date_2);
        $this->assertSame('CC33333333CC', $info->residence_number_3);
    }

    public function testApplyingCardNumberToEmptyInfoTakesFirstSlot(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $this->resetUserDocumentInfo($user);
        $result = $this->createCompletedResult($user, 'residence_card', [
            'residence_number' => 'AA11111111AA',
            'residence_expiry_date' => '2029-01-31',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
                'ocr_result_id' => $result->id,
                'fields' => ['residence_number', 'residence_expiry_date'],
            ])
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('AA11111111AA', $info->residence_number_1);
        $this->assertSame('2029-01-31', $info->residence_expiry_date_1);
        $this->assertNull($info->residence_number_2);
    }

    public function testApplyingExpiryWithoutNumberUpdatesLatestSlot(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2025-01-01',
            'residence_number_2' => 'BB22222222BB',
            'residence_expiry_date_2' => '2027-01-01',
        ]);
        $result = $this->createCompletedResult($user, 'residence_card', [
            'residence_number' => 'BB22222222BB',
            'residence_expiry_date' => '2029-01-31',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
                'ocr_result_id' => $result->id,
                'fields' => ['residence_expiry_date'],
            ])
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('2025-01-01', $info->residence_expiry_date_1);
        $this->assertSame('BB22222222BB', $info->residence_number_2);
        $this->assertSame('2029-01-31', $info->residence_expiry_date_2);
        $this->assertNull($info->residence_number_3);
        $this->assertNull($info->residence_expiry_date_3);
    }

    public function testAppliesKanaNamesToUserAlongsideInfoFields(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create([
            'last_name_kana' => 'キュウ',
            'first_name_kana' => 'キュウ メイ',
        ]);
        $this->resetUserDocumentInfo($user);
        $result = $this->createCompletedResult($user, 'residence_card', [
            'last_name_kana' => 'グエン',
            'first_name_kana' => 'バン ア',
            'birthday' => '1998-04-12',
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
            'ocr_result_id' => $result->id,
            'fields' => ['last_name_kana', 'first_name_kana', 'birthday'],
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertSame('グエン', $user->last_name_kana);
        $this->assertSame('バン ア', $user->first_name_kana);
        $this->assertSame('1998-04-12', $user->info->birthday);
    }

    public function testRejectsUnreadKanaName(): void
    {
        // The kana columns are NOT NULL, so a kana field the extractor returned as null
        // must be rejected instead of silently wiping the stored value
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $result = $this->createCompletedResult($user, 'residence_card', [
            'last_name_kana' => null,
            'first_name_kana' => 'バン ア',
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
            'ocr_result_id' => $result->id,
            'fields' => ['last_name_kana', 'first_name_kana'],
        ]);

        $response->assertStatus(422);
        $this->assertSame(['last_name_kana'], $response->json('data.invalid_fields'));
    }

    public function testAppliesPassportFieldsToEmptyInfo(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create();
        $this->resetUserDocumentInfo($user);
        $result = $this->createCompletedResult($user, 'passport', [
            'passport_number' => 'C1234567',
            'passport_expiry_date' => '2030-06-01',
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
            'ocr_result_id' => $result->id,
            'fields' => ['passport_number', 'passport_expiry_date'],
        ]);

        $response->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('C1234567', $info->passport_number);
        $this->assertSame('2030-06-01', $info->passport_expiry_date);
    }

    public function testResponseShowsShortenedNameForSriLankaUser(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperApprove);
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $this->resetUserDocumentInfo($user);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $result = $this->createCompletedResult($user, 'passport', [
            'passport_number' => 'C1234567',
            'passport_expiry_date' => '2030-06-01',
        ]);

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$user->id}/papers/ocr/apply", [
            'ocr_result_id' => $result->id,
            'fields' => ['passport_number', 'passport_expiry_date'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'THARINDU RAVISHANN');
    }

    private function createAdminWithPermission(PermissionName $permissionName): User
    {
        $admin = User::factory()->create();
        $permission = Permission::create([
            'name' => $permissionName->value,
            'display_name' => $permissionName->value,
        ]);
        $admin->permissions()->attach($permission);

        return $admin;
    }

    private function createCompletedResult(User $user, string $group, array $fields): UserPaperOcrResult
    {
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::ForeignerCardFront->value,
        ]);

        return UserPaperOcrResult::query()->create([
            'ocr_group' => $group,
            'item_ids' => [$item->id],
            'cache_key' => str_repeat('b', 64),
            'model' => 'test/model',
            'status' => OcrResultStatus::Completed->value,
            'fields' => $fields,
            'warnings' => [],
            'source' => 'admin',
            'completed_at' => now(),
        ]);
    }
}
