<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\PermissionName;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperType;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the review screen needs to warn an admin before they approve: documents that
 * disagree with each other, and documents the residence status calls for but that are
 * not on file.
 */
class UserPaperReviewFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function testPapersResponseCarriesTheRecordedMismatches(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = User::factory()->create();
        $mismatches = [
            [
                'field' => 'name',
                'values' => [
                    ['group' => 'residence_card', 'value' => 'NGUYEN VAN A'],
                    ['group' => 'residence_card_scan', 'value' => 'TRAN THI B'],
                ],
            ],
        ];
        UserPaper::factory()->create(['user_id' => $user->id, 'document_mismatches' => $mismatches]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/users/{$user->id}/papers");

        $response->assertStatus(200);
        $this->assertSame($mismatches, $response->json('data.document_mismatches'));
    }

    public function testStudyAbroadWithoutStudentCardOrWorkPermitIsReportedAsMissing(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = $this->makeUserWithStatus(ResidenceStatus::StudyAbroad, [...self::baseDocumentTypes()]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/users/{$user->id}/papers");

        $response->assertStatus(200);
        $this->assertSame(
            [UserPaperStepKey::StudentCard->value, UserPaperStepKey::WorkPermit->value],
            $response->json('data.missing_required_steps')
        );
    }

    public function testFamilyStayIsOnlyMissingTheWorkPermit(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = $this->makeUserWithStatus(ResidenceStatus::FamilyStay, [...self::baseDocumentTypes()]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/users/{$user->id}/papers");

        $this->assertSame([UserPaperStepKey::WorkPermit->value], $response->json('data.missing_required_steps'));
    }

    public function testPermanentResidentWithTheBasicsIsNotFlagged(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = $this->makeUserWithStatus(ResidenceStatus::PermanentResident, [...self::baseDocumentTypes()]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/users/{$user->id}/papers");

        $this->assertSame([], $response->json('data.missing_required_steps'));
    }

    public function testWorkPermitOnFileClearsTheFlagForStudyAbroad(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = $this->makeUserWithStatus(ResidenceStatus::StudyAbroad, [
            ...self::baseDocumentTypes(),
            UserPaperType::StudentCardFront,
            UserPaperType::StudentCardBack,
            UserPaperType::WorkPermitStamp,
        ]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/users/{$user->id}/papers");

        $this->assertSame([], $response->json('data.missing_required_steps'));
    }

    /**
     * Papers filed in another folder do not count: only what is current is on file.
     */
    public function testAnOldWorkPermitDoesNotCountAsFiled(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = $this->makeUserWithStatus(ResidenceStatus::FamilyStay, [...self::baseDocumentTypes()]);
        UserPaperItem::factory()->create([
            'user_paper_id' => $user->paper->id,
            'type' => UserPaperType::WorkPermitStamp->value,
            'folder' => UserPaperItemFolder::Old->value,
            'approved_at' => now(),
            'rejected_at' => null,
        ]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/users/{$user->id}/papers");

        $this->assertSame([UserPaperStepKey::WorkPermit->value], $response->json('data.missing_required_steps'));
    }

    /**
     * The residence card, passport and portrait, complete. The residence card counts as
     * filed on its two printed sides — the legacy flow approved cards without the scan.
     *
     * @return UserPaperType[]
     */
    private static function baseDocumentTypes(): array
    {
        return [
            UserPaperType::ForeignerCardFront,
            UserPaperType::ForeignerCardBack,
            UserPaperType::Passport,
            UserPaperType::PortraitImage,
        ];
    }

    /**
     * A step whose set is only half on file must still be reported missing.
     */
    public function testAHalfFiledResidenceCardIsStillReportedMissing(): void
    {
        $admin = $this->createAdminWithPermission(PermissionName::UserPaperList);
        $user = $this->makeUserWithStatus(ResidenceStatus::PermanentResident, [
            UserPaperType::ForeignerCardFront,
            UserPaperType::Passport,
            UserPaperType::PortraitImage,
        ]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/users/{$user->id}/papers");

        $this->assertSame([UserPaperStepKey::ResidenceCard->value], $response->json('data.missing_required_steps'));
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

    /**
     * @param UserPaperType[] $filedTypes
     */
    private function makeUserWithStatus(ResidenceStatus $status, array $filedTypes): User
    {
        $user = User::factory()->create();
        UserInfo::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'residence_number_1' => 'AB12345678CD',
                'residence_expiry_date_1' => now()->addYear()->toDateString(),
                'residence_status_1' => $status->value,
                'residence_number_2' => null,
                'residence_expiry_date_2' => null,
                'residence_status_2' => null,
                'residence_number_3' => null,
                'residence_expiry_date_3' => null,
                'residence_status_3' => null,
            ]
        );

        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        foreach ($filedTypes as $type) {
            UserPaperItem::factory()->create([
                'user_paper_id' => $userPaper->id,
                'type' => $type->value,
                'folder' => UserPaperItemFolder::Current->value,
                'approved_at' => now(),
                'rejected_at' => null,
            ]);
        }

        return $user->refresh();
    }
}
