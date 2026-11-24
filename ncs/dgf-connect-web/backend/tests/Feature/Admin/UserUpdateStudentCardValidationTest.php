<?php

namespace Tests\Feature\Admin;

use App\Enums\PermissionName;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperStatus;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserUpdateStudentCardValidationTest extends TestCase
{
    use RefreshDatabase;

    private function createAdminWithPermission(PermissionName $permissionName): User
    {
        $admin = User::factory()->create(['paper_status' => UserPaperStatus::Approved]);
        $permission = Permission::create([
            'name' => $permissionName->value,
            'display_name' => $permissionName->value,
        ]);
        $admin->permissions()->attach($permission);

        return $admin;
    }

    private function createTargetUser(): User
    {
        return User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval]);
    }

    private function basePayload(User $user, array $overrides = []): array
    {
        return array_merge(
            [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'first_name_kana' => $user->first_name_kana,
                'last_name_kana' => $user->last_name_kana,
                'email' => $user->email,
                'staff_no' => $user->staff_no ?? '',
            ],
            $overrides
        );
    }

    public function testStudentCardExpiryDateRequiredWhenLatestResidenceStatusIsStudyAbroad(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);
        $user = $this->createTargetUser();

        $payload = $this->basePayload($user, [
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_expiry_date_1' => '2027-01-01',
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson("/admin/users/{$user->id}", $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('data.student_card_expiry_date.0', 'The student card expiry date field is required.');
    }

    public function testStudentCardExpiryDateRequiredWhenLatestIsStudyAbroadAcrossMultipleCards(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);
        $user = $this->createTargetUser();

        $payload = $this->basePayload($user, [
            'residence_status_1' => ResidenceStatus::FamilyStay->value,
            'residence_expiry_date_1' => '2024-01-01',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
            'residence_expiry_date_2' => '2027-01-01',
            'residence_status_3' => ResidenceStatus::PermanentResident->value,
            'residence_expiry_date_3' => '2025-01-01',
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson("/admin/users/{$user->id}", $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('data.student_card_expiry_date.0', 'The student card expiry date field is required.');
    }

    public function testStudentCardExpiryDateOptionalWhenStudyAbroadIsNotLatest(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);
        $user = $this->createTargetUser();

        $payload = $this->basePayload($user, [
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_expiry_date_1' => '2023-01-01',
            'residence_status_2' => ResidenceStatus::FamilyStay->value,
            'residence_expiry_date_2' => '2027-01-01',
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson("/admin/users/{$user->id}", $payload);

        $response->assertStatus(200);
    }

    public function testStudentCardExpiryDatePassesWhenLatestIsStudyAbroadWithValidDate(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);
        $user = $this->createTargetUser();

        $payload = $this->basePayload($user, [
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_expiry_date_1' => '2027-01-01',
            'student_card_expiry_date' => '2026-12-31',
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson("/admin/users/{$user->id}", $payload);

        $response->assertStatus(200);
    }

    public function testStudentCardExpiryDateOptionalWhenLatestIsNotStudyAbroad(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);
        $user = $this->createTargetUser();

        $payload = $this->basePayload($user, [
            'residence_status_1' => ResidenceStatus::FamilyStay->value,
            'residence_expiry_date_1' => '2027-01-01',
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson("/admin/users/{$user->id}", $payload);

        $response->assertStatus(200);
    }

    public function testStudentCardExpiryDateOptionalWhenNoResidenceStatus(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);
        $user = $this->createTargetUser();

        $payload = $this->basePayload($user);

        $response = $this->actingAs($admin, 'api')->patchJson("/admin/users/{$user->id}", $payload);

        $response->assertStatus(200);
    }

    public function testStudentCardExpiryDateRequiredWhenOnlyStatusSetAndIsStudyAbroad(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserEdit);
        $user = $this->createTargetUser();

        $payload = $this->basePayload($user, [
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson("/admin/users/{$user->id}", $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('data.student_card_expiry_date.0', 'The student card expiry date field is required.');
    }
}
