<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ErrorCode;
use App\Enums\Nationality;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Enums\ResidenceStatus;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Models\Permission;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\TempUser;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserImage;
use App\Models\UserLockHistory;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Models\UserPaperStep;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = $this->createUserWithPermissions([
            PermissionName::UserList,
            PermissionName::UserCreate,
            PermissionName::UserEdit,
            PermissionName::UserDisable,
            PermissionName::UserEnable,
            PermissionName::UserRestore,
            PermissionName::UserResetPassword,
            PermissionName::UserAssignRole,
            PermissionName::UserImageList,
            PermissionName::UserImageUpload,
            PermissionName::UserImageDelete,
            PermissionName::UserDeviceList,
            PermissionName::UserDeviceDelete,
            PermissionName::UserPaperList,
            PermissionName::UserPaperApprove,
            PermissionName::UserSync,
            PermissionName::UserConfigList,
            PermissionName::UserConfigEdit,
            PermissionName::UserCreateEmployee,
            PermissionName::UserWorkplaceList,
            PermissionName::UserWorkplaceAdd,
            PermissionName::UserWorkplaceRemove,
        ]);

        $this->employee = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
        ]);
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function testIndexRequiresAuthentication(): void
    {
        $this->getJson('/admin/users')->assertStatus(401);
    }

    public function testIndexRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->getJson('/admin/users')->assertStatus(403);
    }

    public function testIndexReturnsPaginatedUsers(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/users');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['data', 'current_page', 'last_page', 'per_page', 'total']]);
    }

    public function testIndexFiltersByName(): void
    {
        $target = User::factory()->create([
            'first_name' => 'FINDME',
            'paper_status' => UserPaperStatus::Approved->value,
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/users?name=FINDME');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($target->id));
    }

    public function testIndexFiltersByWorkplaceIds(): void
    {
        [$workplaceA, $workplaceB] = $this->makeWorkplacePair();

        $inA = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $inB = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $inA->workplaces()->sync([$workplaceA->id]);
        $inB->workplaces()->sync([$workplaceB->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/users?workplace_ids[]={$workplaceA->id}");

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($inA->id));
        $this->assertFalse($ids->contains($inB->id));
    }

    /**
     * The reason workplace_ids[] exists: a union of workplaces, which the single
     * workplace_id filter cannot express. A regression to where('workplace_id', $ids[0])
     * would still pass testIndexFiltersByWorkplaceIds, so assert both are returned.
     */
    public function testIndexFiltersByMultipleWorkplaceIds(): void
    {
        [$workplaceA, $workplaceB] = $this->makeWorkplacePair();
        $workplaceC = Workplace::create([
            'name' => 'Workplace C',
            'address' => 'C',
            'latitude' => 35.2,
            'longitude' => 139.2,
        ]);

        $inA = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $inB = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $inC = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $inA->workplaces()->sync([$workplaceA->id]);
        $inB->workplaces()->sync([$workplaceB->id]);
        $inC->workplaces()->sync([$workplaceC->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/admin/users?workplace_ids[]={$workplaceA->id}&workplace_ids[]={$workplaceB->id}"
        );

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($inA->id));
        $this->assertTrue($ids->contains($inB->id));
        $this->assertFalse($ids->contains($inC->id));
    }

    /**
     * Workplaces are hard-deleted while the frontend caches workplace_access, so a
     * stale id must narrow the result set instead of 422-ing the whole employee list.
     */
    public function testIndexIgnoresStaleWorkplaceIdInsteadOfRejecting(): void
    {
        [$workplaceA, $workplaceB] = $this->makeWorkplacePair();

        $inA = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $inB = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $inA->workplaces()->sync([$workplaceA->id]);
        $inB->workplaces()->sync([$workplaceB->id]);

        $staleId = Workplace::query()->max('id') + 1000;

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/admin/users?workplace_ids[]={$workplaceA->id}&workplace_ids[]={$staleId}"
        );

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($inA->id));
        $this->assertFalse($ids->contains($inB->id));
    }

    public function testIndexFiltersByPermissionHeldThroughARole(): void
    {
        $manager = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $manager->roles()->sync([$this->roleGrantingWebAdminLogin('floor-manager')->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/users?permission=' . PermissionName::ManagerAuth->value
        );

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($manager->id));
        $this->assertFalse($ids->contains($this->employee->id));
    }

    public function testIndexFiltersByPermissionHeldDirectlyOnTheAccount(): void
    {
        $manager = $this->createUserWithPermissions([PermissionName::ManagerAuth]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/users?permission=' . PermissionName::ManagerAuth->value
        );

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($manager->id));
        $this->assertFalse($ids->contains($this->employee->id));
    }

    public function testIndexFiltersByEveryPermissionGiven(): void
    {
        $both = $this->createUserWithPermissions([PermissionName::ManagerAuth, PermissionName::ChatAssign]);
        $onlyOne = $this->createUserWithPermissions([PermissionName::ManagerAuth]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/users?permission[]=' .
                PermissionName::ManagerAuth->value .
                '&permission[]=' .
                PermissionName::ChatAssign->value
        );

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($both->id));
        $this->assertFalse($ids->contains($onlyOne->id));
    }

    public function testIndexRejectsAPermissionNameThatDoesNotExist(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/admin/users?permission=manage.auth')
            ->assertStatus(422)
            ->assertJsonPath('error', ErrorCode::UnprocessableEntity->value)
            ->assertJsonStructure(['data' => ['permission.0']]);
    }

    /**
     * @return array{0: Workplace, 1: Workplace}
     */
    private function makeWorkplacePair(): array
    {
        return [
            Workplace::create([
                'name' => 'Workplace A',
                'address' => 'A',
                'latitude' => 35.0,
                'longitude' => 139.0,
            ]),
            Workplace::create([
                'name' => 'Workplace B',
                'address' => 'B',
                'latitude' => 35.1,
                'longitude' => 139.1,
            ]),
        ];
    }

    public function testIndexFiltersByShortNameMatchesReversedFallbackName(): void
    {
        $target = User::factory()->create([
            'first_name' => 'Deshan',
            'last_name' => 'Lokuruge',
            'paper_status' => UserPaperStatus::Approved->value,
        ]);
        $target->info->update(['nationality' => Nationality::SriLanka->value]);

        // Raw CONCAT(last_name, first_name) is "Lokuruge Deshan"; the displayed short_name is the
        // reversed-fallback "DESHAN LOKURUGE" — searching what's on screen must match too.
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/users?name=DESHAN LOKURUGE');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($target->id));
    }

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    public function testShowRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson("/admin/users/{$this->employee->id}")
            ->assertStatus(403);
    }

    public function testShowReturnsUser(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/users/{$this->employee->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $this->employee->id);
    }

    public function testShowReturns404ForUnknownUser(): void
    {
        $this->actingAs($this->admin, 'api')->getJson('/admin/users/99999999')->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    public function testUpdateRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->patchJson("/admin/users/{$this->employee->id}", [])
            ->assertStatus(403);
    }

    public function testUpdateUserSuccess(): void
    {
        $response = $this->actingAs($this->admin, 'api')->patchJson("/admin/users/{$this->employee->id}", [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'first_name_kana' => 'UpdatedKana',
            'last_name_kana' => 'NameKana',
            'email' => $this->employee->email,
            'staff_no' => $this->employee->staff_no,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $this->employee->id,
            'first_name' => 'UPDATED',
        ]);
    }

    public function testUpdateRejectsSameResidenceNumberInTwoSlots(): void
    {
        foreach (['BB22222222BB', 'bb22222222bb'] as $duplicate) {
            $this->actingAs($this->admin, 'api')
                ->patchJson("/admin/users/{$this->employee->id}", [
                    ...$this->employeeUpdatePayload(),
                    'residence_number_1' => 'BB22222222BB',
                    'residence_number_2' => $duplicate,
                    'residence_number_3' => null,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['residence_number_1', 'residence_number_2'], 'data');
        }
    }

    public function testUpdateAllowsMovingResidenceNumberToAnotherSlot(): void
    {
        $this->employee->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_number_2' => 'BB22222222BB',
            'residence_number_3' => null,
        ]);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/admin/users/{$this->employee->id}", [
                ...$this->employeeUpdatePayload(),
                'residence_number_1' => 'BB22222222BB',
                'residence_number_2' => null,
                'residence_number_3' => null,
            ])
            ->assertStatus(200);

        $info = $this->employee->info->refresh();
        $this->assertSame('BB22222222BB', $info->residence_number_1);
        $this->assertNull($info->residence_number_2);
    }

    public function testUpdateValidationFailsOnMissingRequiredFields(): void
    {
        $this->actingAs($this->admin, 'api')
            ->patchJson("/admin/users/{$this->employee->id}", [])
            ->assertStatus(422);
    }

    public function testUpdateFromFormOpenedBeforeApprovalKeepsAssignedStaffNo(): void
    {
        Mail::fake();
        $this->fakeCrossStaffApi();

        $this->employee->update(['paper_status' => UserPaperStatus::WaitForApproval->value, 'staff_no' => null]);
        $paper = UserPaper::factory()->create(['user_id' => $this->employee->id]);
        $item = UserPaperItem::factory()->create(['user_paper_id' => $paper->id, 'approved_at' => null]);

        // What the second admin's form holds: loaded while the file still awaited review
        $staleForm = $this->updatePayload($this->employee, ['first_name' => 'Edited', 'staff_no' => '']);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/papers/approve", [
                'items' => [['id' => $item->id, 'approved' => true]],
            ])
            ->assertStatus(200);
        $assignedStaffNo = $this->employee->refresh()->staff_no;
        $this->assertNotNull($assignedStaffNo);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/admin/users/{$this->employee->id}", $staleForm)
            ->assertStatus(200);

        $this->employee->refresh();
        $this->assertSame($assignedStaffNo, $this->employee->staff_no);
        $this->assertSame('EDITED', $this->employee->first_name);
        $this->assertSame(UserPaperStatus::Approved->value, $this->employee->paper_status);
    }

    public function testUpdateWithoutStaffNoKeepsExistingOne(): void
    {
        $this->employee->update(['staff_no' => '100200']);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/admin/users/{$this->employee->id}", $this->updatePayload($this->employee))
            ->assertStatus(200);

        $this->assertSame('100200', $this->employee->refresh()->staff_no);
        $this->assertSame(
            0,
            Notification::query()
                ->where('user_id', $this->employee->id)
                ->where('type', NotificationType::STAFF_NO_ASSIGNED->value)
                ->count()
        );
    }

    public function testUpdateCanChangeExistingStaffNo(): void
    {
        $this->employee->update(['staff_no' => '100200']);

        $this->actingAs($this->admin, 'api')
            ->patchJson(
                "/admin/users/{$this->employee->id}",
                $this->updatePayload($this->employee, ['staff_no' => '100300'])
            )
            ->assertStatus(200);

        $this->assertSame('100300', $this->employee->refresh()->staff_no);
    }

    public function testUpdateRejectsStaffNoHeldByAnotherEmployee(): void
    {
        User::factory()->create(['staff_no' => '100500']);
        $this->employee->update(['staff_no' => '100200']);

        $response = $this->actingAs($this->admin, 'api')->patchJson(
            "/admin/users/{$this->employee->id}",
            $this->updatePayload($this->employee, ['staff_no' => '100500'])
        );

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('data.staff_no'));
        $this->assertSame('100200', $this->employee->refresh()->staff_no);
    }

    public function testUpdateAcceptsEmployeesOwnStaffNo(): void
    {
        $this->employee->update(['staff_no' => '100200']);

        $this->actingAs($this->admin, 'api')
            ->patchJson(
                "/admin/users/{$this->employee->id}",
                $this->updatePayload($this->employee, ['staff_no' => '100200'])
            )
            ->assertStatus(200);

        $this->assertSame('100200', $this->employee->refresh()->staff_no);
    }

    public function testUpdateAssignsStaffNoToEmployeeWithoutOne(): void
    {
        $this->employee->update(['staff_no' => null]);

        $this->actingAs($this->admin, 'api')
            ->patchJson(
                "/admin/users/{$this->employee->id}",
                $this->updatePayload($this->employee, ['staff_no' => '100400'])
            )
            ->assertStatus(200);

        $this->assertSame('100400', $this->employee->refresh()->staff_no);
        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $this->employee->id)
                ->where('type', NotificationType::STAFF_NO_ASSIGNED->value)
                ->count()
        );
    }

    // -------------------------------------------------------------------------
    // Create manager
    // -------------------------------------------------------------------------

    public function testCreateManagerRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->postJson('/admin/users', [])->assertStatus(403);
    }

    public function testCreateManagerRejectsExistingEmail(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/users', [
                'first_name' => 'Test',
                'last_name' => 'Admin',
                'first_name_kana' => 'TestKana',
                'last_name_kana' => 'AdminKana',
                'email' => $this->employee->email,
                'password' => 'password123',
                'role' => RoleName::Admin->value,
            ])
            ->assertStatus(422);
    }

    public function testCreateManagerSuccess(): void
    {
        Role::firstOrCreate(['name' => RoleName::Admin->value], ['display_name' => 'Admin']);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/users', [
            'first_name' => 'New',
            'last_name' => 'Manager',
            'first_name_kana' => 'NewKana',
            'last_name_kana' => 'ManagerKana',
            'email' => 'new.manager@example.com',
            'password' => 'password123',
            'role' => RoleName::Admin->value,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'new.manager@example.com']);
    }

    // -------------------------------------------------------------------------
    // Disable / Enable
    // -------------------------------------------------------------------------

    public function testDisableUserRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson("/admin/users/{$this->employee->id}/disable", ['reason' => 'test'])
            ->assertStatus(403);
    }

    public function testDisableUserSuccess(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/users/{$this->employee->id}/disable", [
            'reason' => 'Policy violation',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'is_disabled' => true]);
    }

    public function testDisableUserRequiresReason(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/disable", [])
            ->assertStatus(422);
    }

    public function testCannotDisableSelf(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->admin->id}/disable", ['reason' => 'test'])
            ->assertStatus(400);
    }

    public function testCannotDisableGlobalAdmin(): void
    {
        $globalAdminRole = Role::firstOrCreate(
            ['name' => RoleName::GlobalAdmin->value],
            ['display_name' => 'Global Admin']
        );
        $target = User::factory()->create();
        $target->addRole($globalAdminRole);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$target->id}/disable", ['reason' => 'test'])
            ->assertStatus(400);
    }

    public function testEnableUserRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", ['reason' => 'test'])
            ->assertStatus(403);
    }

    public function testEnableUserSuccess(): void
    {
        $this->employee->update(['is_disabled' => true]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/users/{$this->employee->id}/enable", [
            'reason' => 'Suspension lifted',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'is_disabled' => false]);
    }

    public function testEnableUserRequiresReason(): void
    {
        $this->employee->update(['is_disabled' => true]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", [])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['reason']]);

        $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'is_disabled' => true]);
        $this->assertDatabaseMissing('user_lock_histories', [
            'user_id' => $this->employee->id,
            'is_locked' => false,
        ]);
    }

    public function testEnableUserRejectsTooLongReason(): void
    {
        $this->employee->update(['is_disabled' => true]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", ['reason' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['reason']]);
    }

    public function testLockThenUnlockRecordsBothReasonsInHistory(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/disable", ['reason' => 'Policy violation'])
            ->assertStatus(200);

        $unlockAdmin = $this->createUserWithPermissions([PermissionName::UserEnable, PermissionName::UserList]);
        $this->actingAs($unlockAdmin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", ['reason' => 'Appeal accepted'])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_lock_histories', [
            'user_id' => $this->employee->id,
            'is_locked' => true,
            'reason' => 'Policy violation',
            'action_by' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('user_lock_histories', [
            'user_id' => $this->employee->id,
            'is_locked' => false,
            'reason' => 'Appeal accepted',
            'action_by' => $unlockAdmin->id,
        ]);

        $histories = $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/lock-histories")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(2, $histories);
        $this->assertSame('Appeal accepted', $histories[0]['reason']);
        $this->assertSame($unlockAdmin->id, $histories[0]['action_by']['id']);
        $this->assertSame('Policy violation', $histories[1]['reason']);
        $this->assertSame($this->admin->id, $histories[1]['action_by']['id']);
    }

    public function testRelockAfterUnlockAppendsToExistingHistory(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/disable", ['reason' => 'First lock'])
            ->assertStatus(200);
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", ['reason' => 'First unlock'])
            ->assertStatus(200);
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/disable", ['reason' => 'Second lock'])
            ->assertStatus(200);

        $reasons = $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/lock-histories")
            ->assertStatus(200)
            ->json('data.*.reason');

        $this->assertSame(['Second lock', 'First unlock', 'First lock'], $reasons);
        $this->assertTrue($this->employee->refresh()->is_disabled);
    }

    public function testEnableUserRejectsDeletedAccountWithoutRecordingHistory(): void
    {
        $this->actingAs($this->employee, 'api')->deleteJson('/user/profile/delete')->assertStatus(204);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", ['reason' => 'Rehired'])
            ->assertStatus(400);

        $this->assertDatabaseMissing('user_lock_histories', [
            'user_id' => $this->employee->id,
            'is_locked' => false,
        ]);
    }

    public function testEnableAlreadyActiveUserIsRejectedAndWritesNoHistory(): void
    {
        $this->assertFalse($this->employee->refresh()->is_disabled);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", ['reason' => 'Nothing to unlock'])
            ->assertStatus(400)
            ->assertJsonPath('error', ErrorCode::UserAlreadyEnabled->value);

        $this->assertDatabaseCount('user_lock_histories', 0);
    }

    public function testDisableAlreadyLockedUserIsRejectedAndWritesNoSecondHistory(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/disable", ['reason' => 'First lock'])
            ->assertStatus(200);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/disable", ['reason' => 'Second lock'])
            ->assertStatus(400)
            ->assertJsonPath('error', ErrorCode::UserAlreadyDisabled->value);

        $this->assertDatabaseCount('user_lock_histories', 1);
        $this->assertDatabaseMissing('user_lock_histories', ['reason' => 'Second lock']);
        $this->assertTrue($this->employee->refresh()->is_disabled);
    }

    public function testDoubleUnlockLeavesASingleHistoryEntry(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/disable", ['reason' => 'Policy violation'])
            ->assertStatus(200);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", ['reason' => 'Appeal accepted'])
            ->assertStatus(200);
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", ['reason' => 'Appeal accepted'])
            ->assertStatus(400)
            ->assertJsonPath('error', ErrorCode::UserAlreadyEnabled->value);

        $reasons = $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/lock-histories")
            ->assertStatus(200)
            ->json('data.*.reason');

        $this->assertSame(['Appeal accepted', 'Policy violation'], $reasons);
    }

    public function testLockHistoriesAreReturnedNewestFirstEvenWhenBackdated(): void
    {
        $olderId = UserLockHistory::query()->create([
            'user_id' => $this->employee->id,
            'is_locked' => true,
            'reason' => 'Older event, inserted last',
            'action_by' => $this->admin->id,
            'action_at' => '2020-01-01 08:00:00',
        ])->id;
        $newerId = UserLockHistory::query()->create([
            'user_id' => $this->employee->id,
            'is_locked' => false,
            'reason' => 'Newer event, inserted first',
            'action_by' => $this->admin->id,
            'action_at' => '2030-01-01 08:00:00',
        ])->id;
        $this->assertGreaterThan($olderId, $newerId);

        $reasons = $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/lock-histories")
            ->assertStatus(200)
            ->json('data.*.reason');

        $this->assertSame(['Newer event, inserted first', 'Older event, inserted last'], $reasons);
    }

    public function testLockHistoriesWithinTheSameSecondFallBackToInsertionOrder(): void
    {
        foreach (['First', 'Second', 'Third'] as $label) {
            UserLockHistory::query()->create([
                'user_id' => $this->employee->id,
                'is_locked' => $label === 'Second',
                'reason' => $label,
                'action_by' => $this->admin->id,
                'action_at' => '2026-03-04 05:06:07',
            ]);
        }

        $reasons = $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/lock-histories")
            ->assertStatus(200)
            ->json('data.*.reason');

        $this->assertSame(['Third', 'Second', 'First'], $reasons);
    }

    public function testLockHistoriesSerializeIsLockedAsABoolean(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/disable", ['reason' => 'Policy violation'])
            ->assertStatus(200);
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", ['reason' => 'Appeal accepted'])
            ->assertStatus(200);

        $histories = $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/lock-histories")
            ->assertStatus(200)
            ->json('data');

        $this->assertIsBool($histories[0]['is_locked']);
        $this->assertIsBool($histories[1]['is_locked']);
        $this->assertFalse($histories[0]['is_locked']);
        $this->assertTrue($histories[1]['is_locked']);
    }

    public function testLockHistoriesOnlyExposeTheActingAdminFieldsTheScreenNeeds(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/disable", ['reason' => 'Policy violation'])
            ->assertStatus(200);

        $actionBy = $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/lock-histories")
            ->assertStatus(200)
            ->json('data.0.action_by');

        $this->assertSame(
            ['id', 'name', 'profile_picture', 'staff_no'],
            collect($actionBy)->keys()->sort()->values()->all()
        );
        $this->assertSame($this->admin->id, $actionBy['id']);
    }

    public function testLockHistoriesKeepReasonNullForRowsWrittenBeforeUnlockReasonsExisted(): void
    {
        UserLockHistory::query()->create([
            'user_id' => $this->employee->id,
            'is_locked' => false,
            'action_by' => $this->admin->id,
        ]);

        $histories = $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/lock-histories")
            ->assertStatus(200)
            ->json('data');

        $this->assertNull($histories[0]['reason']);
    }

    public function testEnableUserRejectedWithOnlyDisablePermission(): void
    {
        $this->employee->update(['is_disabled' => true]);
        $lockOnlyAdmin = $this->createUserWithPermissions([PermissionName::UserDisable]);

        $this->actingAs($lockOnlyAdmin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", ['reason' => 'test'])
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'is_disabled' => true]);
    }

    public function testEnableUserSucceedsWithOnlyEnablePermission(): void
    {
        $this->employee->update(['is_disabled' => true]);
        $unlockOnlyAdmin = $this->createUserWithPermissions([PermissionName::UserEnable]);

        $this->actingAs($unlockOnlyAdmin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", ['reason' => 'test'])
            ->assertStatus(200);

        $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'is_disabled' => false]);
    }

    public function testDisableUserRejectedWithOnlyEnablePermission(): void
    {
        $unlockOnlyAdmin = $this->createUserWithPermissions([PermissionName::UserEnable]);

        $this->actingAs($unlockOnlyAdmin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/disable", ['reason' => 'test'])
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'is_disabled' => false]);
    }

    public function testEnableUserRejectsDeletedAccount(): void
    {
        // Reach the deleted state through the real self-deletion flow.
        $this->actingAs($this->employee, 'api')->deleteJson('/user/profile/delete')->assertStatus(204);

        $deletedEmail = $this->employee->fresh()->email;
        $this->assertNotNull(User::originalEmailFromDeleted($deletedEmail));

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/enable", ['reason' => 'test'])
            ->assertStatus(400)
            ->assertJsonPath('error', ErrorCode::UserCannotEnableDeleted->value);

        // The account must stay deleted: still disabled, email still mangled.
        $this->assertDatabaseHas('users', [
            'id' => $this->employee->id,
            'is_disabled' => true,
            'email' => $deletedEmail,
        ]);
        $this->assertDatabaseMissing('user_lock_histories', [
            'user_id' => $this->employee->id,
            'is_locked' => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // Roles
    // -------------------------------------------------------------------------

    public function testGetRolesRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson("/admin/users/{$this->employee->id}/roles")
            ->assertStatus(403);
    }

    public function testGetRolesReturnsArray(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/users/{$this->employee->id}/roles");

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'));
    }

    public function testAssignRoleRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson("/admin/users/{$this->employee->id}/roles", ['role_name' => RoleName::Admin->value])
            ->assertStatus(403);
    }

    public function testAssignRoleSuccess(): void
    {
        $role = Role::firstOrCreate(['name' => RoleName::Admin->value], ['display_name' => 'Admin']);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/users/{$this->employee->id}/roles", [
            'role_name' => $role->name,
        ]);

        $response->assertStatus(200);
        $this->assertTrue($this->employee->fresh()->hasRole(RoleName::Admin->value));
    }

    public function testAssignRoleReturnsShortenedNameForSriLankaEmployee(): void
    {
        $role = Role::firstOrCreate(['name' => RoleName::Admin->value], ['display_name' => 'Admin']);
        $sriLankaEmployee = $this->sriLankaEmployee();

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/users/{$sriLankaEmployee->id}/roles", [
            'role_name' => $role->name,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'THARINDU RAVISHANN');
    }

    public function testCannotAssignRoleToSelf(): void
    {
        Role::firstOrCreate(['name' => RoleName::Admin->value], ['display_name' => 'Admin']);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->admin->id}/roles", ['role_name' => RoleName::Admin->value])
            ->assertStatus(403);
    }

    public function testRemoveRoleRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->deleteJson("/admin/users/{$this->employee->id}/roles", ['role_name' => RoleName::Admin->value])
            ->assertStatus(403);
    }

    public function testRemoveRoleSuccess(): void
    {
        $role = Role::firstOrCreate(['name' => RoleName::Admin->value], ['display_name' => 'Admin']);
        $this->employee->addRole($role);

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/admin/users/{$this->employee->id}/roles", [
            'role_name' => RoleName::Admin->value,
        ]);

        $response->assertStatus(200);
        $this->assertFalse($this->employee->fresh()->hasRole(RoleName::Admin->value));
    }

    public function testRemoveRoleReturnsShortenedNameForSriLankaEmployee(): void
    {
        $role = Role::firstOrCreate(['name' => RoleName::Admin->value], ['display_name' => 'Admin']);
        $sriLankaEmployee = $this->sriLankaEmployee();
        $sriLankaEmployee->addRole($role);

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/admin/users/{$sriLankaEmployee->id}/roles", [
            'role_name' => RoleName::Admin->value,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'THARINDU RAVISHANN');
    }

    // -------------------------------------------------------------------------
    // Reset password
    // -------------------------------------------------------------------------

    public function testResetPasswordRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson("/admin/users/{$this->employee->id}/reset-password", [
                'password' => 'newpassword',
                'password_confirmation' => 'newpassword',
            ])
            ->assertStatus(403);
    }

    public function testResetPasswordSuccess(): void
    {
        Mail::fake();

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/reset-password", [
                'password' => 'newpassword',
                'password_confirmation' => 'newpassword',
            ])
            ->assertStatus(200);
    }

    public function testCannotResetOwnPassword(): void
    {
        Mail::fake();

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->admin->id}/reset-password", [
                'password' => 'newpassword',
                'password_confirmation' => 'newpassword',
            ])
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // User workplaces
    // -------------------------------------------------------------------------

    public function testGetUserWorkplacesRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson("/admin/users/{$this->employee->id}/workplaces")
            ->assertStatus(403);
    }

    public function testGetUserWorkplacesReturnsArray(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/users/{$this->employee->id}/workplaces");

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'));
    }

    public function testAddUserIntoWorkplacesRequiresPermission(): void
    {
        $user = User::factory()->create();
        $workplace = Workplace::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson("/admin/users/{$this->employee->id}/workplaces", [
                'workplace_ids' => [$workplace->id],
            ])
            ->assertStatus(403);
    }

    public function testAddUserIntoWorkplacesSuccess(): void
    {
        $workplace = Workplace::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/users/{$this->employee->id}/workplaces", [
            'workplace_ids' => [$workplace->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('workplace_users', [
            'user_id' => $this->employee->id,
            'workplace_id' => $workplace->id,
        ]);
    }

    public function testDeleteUserWorkplaceRequiresPermission(): void
    {
        $user = User::factory()->create();
        $workplace = Workplace::factory()->create();

        $this->actingAs($user, 'api')
            ->deleteJson("/admin/users/{$this->employee->id}/workplaces/{$workplace->id}")
            ->assertStatus(403);
    }

    public function testDeleteUserWorkplaceSuccess(): void
    {
        $workplace = Workplace::factory()->create();
        $this->employee->workplaces()->attach($workplace->id);

        $response = $this->actingAs($this->admin, 'api')->deleteJson(
            "/admin/users/{$this->employee->id}/workplaces/{$workplace->id}"
        );

        $response->assertStatus(200);
        $this->assertDatabaseMissing('workplace_users', [
            'user_id' => $this->employee->id,
            'workplace_id' => $workplace->id,
        ]);
    }

    public function testDeleteUserWorkplaceBlockedWhenFutureShiftExists(): void
    {
        $workplace = Workplace::factory()->create();
        $this->employee->workplaces()->attach($workplace->id);

        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->admin->id,
            'date' => now()->addDay()->format('Y-m-d'),
            'adjusted_date' => now()->addDay()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $position = WorkplacePosition::create(['workplace_id' => $workplace->id, 'name' => 'Staff']);

        ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/admin/users/{$this->employee->id}/workplaces/{$workplace->id}")
            ->assertStatus(400);

        $this->assertDatabaseHas('workplace_users', [
            'user_id' => $this->employee->id,
            'workplace_id' => $workplace->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // User images
    // -------------------------------------------------------------------------

    public function testGetImagesRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson("/admin/users/{$this->employee->id}/images")
            ->assertStatus(403);
    }

    public function testGetImagesReturnsArray(): void
    {
        UserImage::create(['user_id' => $this->employee->id, 'path' => 'users/1/photo.jpg']);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/users/{$this->employee->id}/images");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function testUploadImageRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson("/admin/users/{$this->employee->id}/images", [
                'file' => UploadedFile::fake()->image('photo.jpg'),
            ])
            ->assertStatus(403);
    }

    public function testUploadImageSuccess(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/users/{$this->employee->id}/images", [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('user_images', ['user_id' => $this->employee->id]);
    }

    public function testDeleteImageRequiresPermission(): void
    {
        $user = User::factory()->create();
        $image = UserImage::create(['user_id' => $this->employee->id, 'path' => 'users/1/photo.jpg']);

        $this->actingAs($user, 'api')
            ->deleteJson("/admin/users/{$this->employee->id}/images/{$image->id}")
            ->assertStatus(403);
    }

    public function testDeleteImageSuccess(): void
    {
        $image = UserImage::create(['user_id' => $this->employee->id, 'path' => 'users/1/photo.jpg']);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/admin/users/{$this->employee->id}/images/{$image->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('user_images', ['id' => $image->id]);
    }

    // -------------------------------------------------------------------------
    // User devices
    // -------------------------------------------------------------------------

    public function testGetDevicesRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson("/admin/users/{$this->employee->id}/devices")
            ->assertStatus(403);
    }

    public function testGetDevicesReturnsArray(): void
    {
        UserDevice::create([
            'user_id' => $this->employee->id,
            'device_id' => 'dev-001',
            'name' => 'iPhone',
            'platform' => 'ios',
            'fcm_token' => 'tok',
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/users/{$this->employee->id}/devices");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function testDeleteDeviceRequiresPermission(): void
    {
        $user = User::factory()->create();
        $device = UserDevice::create([
            'user_id' => $this->employee->id,
            'device_id' => 'dev-001',
            'name' => 'iPhone',
            'platform' => 'ios',
            'fcm_token' => 'tok',
        ]);

        $this->actingAs($user, 'api')
            ->deleteJson("/admin/users/{$this->employee->id}/devices/{$device->id}")
            ->assertStatus(403);
    }

    public function testDeleteDeviceSuccess(): void
    {
        $device = UserDevice::create([
            'user_id' => $this->employee->id,
            'device_id' => 'dev-001',
            'name' => 'iPhone',
            'platform' => 'ios',
            'fcm_token' => 'tok',
        ]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/admin/users/{$this->employee->id}/devices/{$device->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('user_devices', ['id' => $device->id]);
    }

    // -------------------------------------------------------------------------
    // Lock histories
    // -------------------------------------------------------------------------

    public function testGetLockHistoriesRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson("/admin/users/{$this->employee->id}/lock-histories")
            ->assertStatus(403);
    }

    public function testGetLockHistoriesReturnsArray(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/users/{$this->employee->id}/lock-histories");

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'));
    }

    // -------------------------------------------------------------------------
    // Papers
    // -------------------------------------------------------------------------

    public function testGetPapersRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson("/admin/users/{$this->employee->id}/papers")
            ->assertStatus(403);
    }

    public function testGetPapersCreatesAndReturnsUserPaper(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/users/{$this->employee->id}/papers");

        $response->assertStatus(200);
        $this->assertDatabaseHas('user_papers', ['user_id' => $this->employee->id]);
    }

    public function testApprovePapersRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson("/admin/users/{$this->employee->id}/papers/approve", ['items' => []])
            ->assertStatus(403);
    }

    public function testApprovePapersValidatesItems(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/papers/approve", [])
            ->assertStatus(422);
    }

    public function testApprovePapersSuccess(): void
    {
        Mail::fake();
        $this->fakeCrossStaffApi();

        $this->employee->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $paper = UserPaper::factory()->create(['user_id' => $this->employee->id]);
        $item = UserPaperItem::factory()->create(['user_paper_id' => $paper->id, 'approved_at' => null]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/papers/approve", [
                'items' => [['id' => $item->id, 'approved' => true]],
            ])
            ->assertStatus(200);
    }

    /**
     * The mismatch verdict describes the submission being decided, so deciding it has to
     * clear the verdict — otherwise the admin list keeps a red badge on a file that has
     * already been dealt with, with nothing able to clear it until the next renewal.
     */
    public function testApprovingClearsTheDocumentMismatchVerdict(): void
    {
        Mail::fake();
        $this->fakeCrossStaffApi();

        $this->employee->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $paper = UserPaper::factory()->create([
            'user_id' => $this->employee->id,
            'document_mismatches' => [['field' => 'name', 'values' => [['group' => 'residence_card', 'value' => 'A']]]],
        ]);
        $item = UserPaperItem::factory()->create(['user_paper_id' => $paper->id, 'approved_at' => null]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/papers/approve", [
                'items' => [['id' => $item->id, 'approved' => true]],
            ])
            ->assertStatus(200);

        $this->assertNull($paper->refresh()->document_mismatches);
    }

    /**
     * 留学 may only work while enrolled, and the shift checks read the student card expiry
     * date to enforce that. The date is entered on the employee detail, so an approval that
     * cannot find it for a card already on file is refused and the admin is told where to fix it.
     */
    public function testApprovePapersRefusesAStudentCardOnFileWithNoExpiryDate(): void
    {
        Mail::fake();
        $this->fakeCrossStaffApi();

        $this->employee->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $this->makeStudyAbroad(null);
        $paper = UserPaper::factory()->create(['user_id' => $this->employee->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'type' => UserPaperType::StudentCardFront->value,
            'approved_at' => null,
        ]);
        $this->confirmStudentCardStep($paper);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/admin/users/{$this->employee->id}/papers/approve",
            ['items' => [['id' => $item->id, 'approved' => true]]]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::UserPaperStudentCardExpiryMissing->value);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $this->employee->refresh()->paper_status);
        $this->assertNull($item->refresh()->approved_at);

        // Filling the date in on the employee detail is all that stands in the way
        $this->employee->info->update(['student_card_expiry_date' => '2027-03-31']);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/papers/approve", [
                'items' => [['id' => $item->id, 'approved' => true]],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::Approved->value, $this->employee->refresh()->paper_status);
    }

    /**
     * The 留学 employee approved before the student card was mandatory: no card, no date.
     * That file owes a document, not a date, and the admin may still decide it — the
     * missing-documents warning is what chases the card.
     */
    public function testApprovePapersAllowsAStudyAbroadFileWithNoStudentCardAtAll(): void
    {
        Mail::fake();
        $this->fakeCrossStaffApi();

        $this->employee->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $this->makeStudyAbroad(null);
        $paper = UserPaper::factory()->create(['user_id' => $this->employee->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'type' => UserPaperType::Passport->value,
            'approved_at' => null,
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/papers/approve", [
                'items' => [['id' => $item->id, 'approved' => true]],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::Approved->value, $this->employee->refresh()->paper_status);
    }

    /**
     * The papers screen has to warn before the admin opens the approve modal: the fix lives
     * on another tab, and a submit refused at the last moment says so too late.
     */
    public function testGetPapersFlagsAStudentCardOnFileWithNoExpiryDate(): void
    {
        $this->makeStudyAbroad(null);
        $paper = UserPaper::factory()->create(['user_id' => $this->employee->id]);

        // No card on file yet: the file owes a document, which the missing-documents list says
        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/papers")
            ->assertStatus(200)
            ->assertJsonPath('data.student_card_expiry_missing', false);

        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'type' => UserPaperType::StudentCardFront->value,
            'approved_at' => null,
        ]);

        // Uploaded but not confirmed yet: the form that asks for the date has not been
        // reached, so the file still owes the document rather than the date
        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/papers")
            ->assertStatus(200)
            ->assertJsonPath('data.student_card_expiry_missing', false);

        $step = $this->confirmStudentCardStep($paper);

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/papers")
            ->assertStatus(200)
            ->assertJsonPath('data.student_card_expiry_missing', true);

        $this->employee->info->update(['student_card_expiry_date' => '2027-03-31']);

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}/papers")
            ->assertStatus(200)
            ->assertJsonPath('data.student_card_expiry_missing', false);
    }

    /**
     * The employee this is really about: approved, working today, file complete — and no
     * way for anyone to tell whether their enrolment has lapsed. Nothing puts them in the
     * review queue and nothing is absent, so without a flag of their own an admin would
     * have to open the papers tab of every 留学 employee to find them.
     */
    public function testTheEmployeeListFlagsAnApprovedFileWithNoStudentCardExpiryDate(): void
    {
        $this->employee->update(['paper_status' => UserPaperStatus::Approved->value]);
        $this->makeStudyAbroad(null);
        $paper = UserPaper::factory()->create(['user_id' => $this->employee->id]);
        $this->approveEveryStudyAbroadDocument($paper);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/users');

        $response->assertStatus(200);
        $row = collect($response->json('data.data'))->firstWhere('id', $this->employee->id);
        $this->assertTrue($row['has_missing_student_card_expiry']);
        // The file holds every document it owes, so the other warning stays quiet
        $this->assertFalse($row['has_missing_required_documents']);

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.has_missing_student_card_expiry', true);
    }

    /**
     * The file an employee leaves behind when they become an admin.
     *
     * Promotion is a two-step move through the roles screen — the `user` role comes off and
     * an admin role goes on, because the two are mutually exclusive — and the 留学 paper row
     * survives it untouched. The date only ever mattered because the roster reads it before
     * putting someone on a shift; an account that signs in to the admin is not on the roster,
     * so the badge is pointing at a gap nobody can be asked to close.
     *
     * Promoted into a role of the company's own making rather than the seeded `admin`,
     * because admin access is whatever role carries `manager.auth` — the check must not be
     * reading role names. Same rule the missing-documents and mismatch badges draw.
     *
     * @see \App\Actions\Admin\User\ResolveWebAdminAccessAction
     */
    public function testTheEmployeeListStopsFlaggingAnEmployeePromotedToWebAdmin(): void
    {
        $this->makeStudyAbroad(null);
        $paper = UserPaper::factory()->create(['user_id' => $this->employee->id]);
        $this->approveEveryStudyAbroadDocument($paper);
        $this->employee->addRole(Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']));

        // A colleague with an identical file who stays an employee: the badge is theirs to keep
        $stillAnEmployee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $stillAnEmployee->info->update([
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_expiry_date_1' => now()->addYears(2)->toDateString(),
            'student_card_expiry_date' => null,
        ]);
        $this->approveEveryStudyAbroadDocument(UserPaper::factory()->create(['user_id' => $stillAnEmployee->id]));

        $this->assertTrue($this->listRow($this->employee)['has_missing_student_card_expiry']);

        $this->promoteToWebAdmin($this->employee, $this->roleGrantingWebAdminLogin('leader'));

        $this->assertFalse($this->listRow($this->employee)['has_missing_student_card_expiry']);
        $this->assertTrue($this->listRow($stillAnEmployee)['has_missing_student_card_expiry']);

        // The detail endpoint marks the same tab before it is opened, so silencing the list
        // alone would only move the badge one screen over
        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.has_missing_student_card_expiry', false);
    }

    /**
     * The papers tab is the third surface the warning reaches, and the only one carrying the
     * button that acts on it — silencing the list and the detail screen alone would have
     * moved the alert one tab deeper instead of removing it.
     *
     * The file underneath is untouched: the date is still absent on the row, so a demotion
     * back to employee finds the same 留学 file the promotion left behind.
     */
    public function testThePapersTabStopsWarningAboutAnEmployeePromotedToWebAdmin(): void
    {
        $this->makeStudyAbroad(null);
        $this->approveEveryStudyAbroadDocument(UserPaper::factory()->create(['user_id' => $this->employee->id]));
        $this->employee->addRole(Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']));

        $this->assertTrue($this->papersTab($this->employee)['student_card_expiry_missing']);

        $this->promoteToWebAdmin($this->employee, $this->roleGrantingWebAdminLogin('leader'));

        $this->assertFalse($this->papersTab($this->employee)['student_card_expiry_missing']);
        $this->assertNull($this->employee->info->refresh()->student_card_expiry_date);
    }

    /**
     * The button behind the warning has to agree with it. Sending an admin account back to
     * pending would close check-in, shift registration and salary through
     * CheckUserPaperStatus for someone with no employee app to answer the request in — and
     * nobody could put it back, because ApprovePapersAction only takes `wait_for_approval`.
     */
    public function testCompletionCannotBeRequestedForTheDateFromAnEmployeePromotedToWebAdmin(): void
    {
        $this->makeStudyAbroad(null);
        $this->approveEveryStudyAbroadDocument(UserPaper::factory()->create(['user_id' => $this->employee->id]));
        $this->employee->addRole(Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']));

        // The same file on an account that stays an employee, so the 422 below is shown to
        // come from the promotion and not from anything else about the file
        $stillAnEmployee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $stillAnEmployee->info->update([
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_expiry_date_1' => now()->addYears(2)->toDateString(),
            'student_card_expiry_date' => null,
        ]);
        $this->approveEveryStudyAbroadDocument(UserPaper::factory()->create(['user_id' => $stillAnEmployee->id]));

        $this->promoteToWebAdmin($this->employee, $this->roleGrantingWebAdminLogin('leader'));

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/papers/request-completion")
            ->assertStatus(422)
            ->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $this->assertSame(UserPaperStatus::Approved->value, $this->employee->refresh()->paper_status);
        $this->assertDatabaseMissing('user_paper_steps', [
            'user_paper_id' => $this->employee->paper()->value('id'),
            'step_key' => UserPaperStepKey::StudentCard->value,
            'status' => UserPaperStepStatus::NeedsReupload->value,
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$stillAnEmployee->id}/papers/request-completion")
            ->assertStatus(200)
            ->assertJsonPath('data.reopened_steps', [UserPaperStepKey::StudentCard->value]);
        $this->assertSame(UserPaperStatus::Pending->value, $stillAnEmployee->refresh()->paper_status);
    }

    /**
     * The missing-documents warning is the other half of the same promotion.
     *
     * Silencing only the student card left the yellow alert and its `Request document
     * completion` button on the papers tab of a promoted admin, while the guard behind the
     * button refuses every web admin — so the one thing the alert told the admin to do could
     * only ever answer 422. The file here owes a document rather than a date, with the
     * student card expiry filled in, so nothing but the missing-documents flag can be what
     * the assertions below are reading.
     */
    public function testThePapersTabStopsAskingAnEmployeePromotedToWebAdminForAMissingDocument(): void
    {
        $this->makeStudyAbroad('2027-03-31');
        $this->approveEveryStudyAbroadDocument(UserPaper::factory()->create(['user_id' => $this->employee->id]), [
            UserPaperType::WorkPermitStamp,
        ]);
        $this->employee->addRole(Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']));

        // The same file on an account that stays an employee: the request is theirs to answer
        $stillAnEmployee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $stillAnEmployee->info->update([
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_expiry_date_1' => now()->addYears(2)->toDateString(),
            'student_card_expiry_date' => '2027-03-31',
        ]);
        $this->approveEveryStudyAbroadDocument(UserPaper::factory()->create(['user_id' => $stillAnEmployee->id]), [
            UserPaperType::WorkPermitStamp,
        ]);

        $this->assertTrue($this->listRow($this->employee)['has_missing_required_documents']);
        $this->assertSame(
            [UserPaperStepKey::WorkPermit->value],
            $this->papersTab($this->employee)['missing_required_steps']
        );

        $this->promoteToWebAdmin($this->employee, $this->roleGrantingWebAdminLogin('leader'));

        $this->assertFalse($this->listRow($this->employee)['has_missing_required_documents']);
        $this->assertSame([], $this->papersTab($this->employee)['missing_required_steps']);
        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$this->employee->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.has_missing_required_documents', false);

        // The guard the silenced alert now agrees with, and the colleague it still lets through
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/papers/request-completion")
            ->assertStatus(422);
        $this->assertSame(UserPaperStatus::Approved->value, $this->employee->refresh()->paper_status);

        $this->assertTrue($this->listRow($stillAnEmployee)['has_missing_required_documents']);
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$stillAnEmployee->id}/papers/request-completion")
            ->assertStatus(200)
            ->assertJsonPath('data.reopened_steps', [UserPaperStepKey::WorkPermit->value]);
    }

    /**
     * The promotion that lands mid-review: the employee filed everything, the file is waiting
     * for a decision, and the roles screen makes them an admin before anyone gets to it.
     *
     * The approve modal reads the papers tab, which stays quiet for an admin account, so the
     * submit button is live — the hold in ApprovePapersAction has to draw the same line or
     * the admin is left with a file nothing warned about that cannot be approved either. The
     * date is what the hold exists to obtain and nothing will read it now: an admin account
     * is never put on a shift, and a demotion back to employee brings the badge back with the
     * file untouched.
     */
    public function testAFileWaitingForReviewIsApprovedAfterThePromotionToWebAdmin(): void
    {
        Mail::fake();
        $this->fakeCrossStaffApi();

        $this->employee->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $this->makeStudyAbroad(null);
        $paper = UserPaper::factory()->create(['user_id' => $this->employee->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'type' => UserPaperType::StudentCardFront->value,
            'approved_at' => null,
        ]);
        $this->confirmStudentCardStep($paper);
        $this->employee->addRole(Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']));

        // While they are still an employee the modal warns and the submit is refused
        $this->assertTrue($this->papersTab($this->employee)['student_card_expiry_missing']);
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/papers/approve", [
                'items' => [['id' => $item->id, 'approved' => true]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', ErrorCode::UserPaperStudentCardExpiryMissing->value);

        $this->promoteToWebAdmin($this->employee, $this->roleGrantingWebAdminLogin('leader'));

        $this->assertFalse($this->papersTab($this->employee)['student_card_expiry_missing']);
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/papers/approve", [
                'items' => [['id' => $item->id, 'approved' => true]],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::Approved->value, $this->employee->refresh()->paper_status);
        $this->assertNull($this->employee->info->refresh()->student_card_expiry_date);
    }

    public function testTheEmployeeListLeavesEveryOtherFileAlone(): void
    {
        $this->employee->update(['paper_status' => UserPaperStatus::Approved->value]);
        $this->makeStudyAbroad('2027-03-31');
        $paper = UserPaper::factory()->create(['user_id' => $this->employee->id]);
        $this->approveEveryStudyAbroadDocument($paper);

        // A manager, created approved and with no document file at all, must not be read as
        // a 留学 employee whose card lost its date
        $manager = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $manager->info->update(['residence_status_1' => ResidenceStatus::StudyAbroad->value]);

        $rows = collect($this->actingAs($this->admin, 'api')->getJson('/admin/users')->json('data.data'));

        $this->assertFalse($rows->firstWhere('id', $this->employee->id)['has_missing_student_card_expiry']);
        $this->assertFalse($rows->firstWhere('id', $manager->id)['has_missing_student_card_expiry']);
    }

    /**
     * Under review the approve modal is what stops the admin, so the list badge would only
     * repeat a decision that is already being made.
     */
    public function testTheEmployeeListDoesNotFlagAFileStillUnderReview(): void
    {
        $this->employee->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $this->makeStudyAbroad(null);
        $paper = UserPaper::factory()->create(['user_id' => $this->employee->id]);
        $this->approveEveryStudyAbroadDocument($paper);

        $rows = collect($this->actingAs($this->admin, 'api')->getJson('/admin/users')->json('data.data'));

        $this->assertFalse($rows->firstWhere('id', $this->employee->id)['has_missing_student_card_expiry']);
    }

    /**
     * @return array<string, mixed> the papers tab of the detail screen
     */
    private function papersTab(User $user): array
    {
        return $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$user->id}/papers")
            ->assertStatus(200)
            ->json('data');
    }

    /**
     * What both admin clients ask for at login, granted the way production grants it: on a
     * role, never on the user directly. Named by the company rather than seeded, so the
     * check cannot be reading role names.
     */
    private function roleGrantingWebAdminLogin(string $name): Role
    {
        $role = Role::firstOrCreate(['name' => $name], ['display_name' => ucfirst($name)]);
        $role
            ->permissions()
            ->syncWithoutDetaching(
                Permission::query()->firstOrCreate(
                    ['name' => PermissionName::ManagerAuth->value],
                    ['display_name' => PermissionName::ManagerAuth->value]
                )->id
            );

        return $role;
    }

    /**
     * The roles screen's two-step promotion, through the endpoints the screen calls: the
     * `user` role comes off first because AssignRolesAction forbids holding both.
     */
    private function promoteToWebAdmin(User $user, Role $adminRole): void
    {
        $this->actingAs($this->admin, 'api')
            ->deleteJson("/admin/users/{$user->id}/roles", ['role_name' => RoleName::User->value])
            ->assertStatus(200);
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$user->id}/roles", ['role_name' => $adminRole->name])
            ->assertStatus(200);
    }

    /**
     * @return array<string, mixed> the employee's row of the list endpoint
     */
    private function listRow(User $user): array
    {
        return collect(
            $this->actingAs($this->admin, 'api')->getJson('/admin/users')->assertStatus(200)->json('data.data')
        )->firstWhere('id', $user->id);
    }

    /**
     * Every document 留学 requires, approved and in the current folder — the file of an
     * employee who is working today and owes nothing. `$except` leaves one out, for the
     * employee approved before that document became mandatory.
     *
     * @param array<int, UserPaperType> $except
     */
    private function approveEveryStudyAbroadDocument(UserPaper $paper, array $except = []): void
    {
        $types = [
            UserPaperType::ForeignerCardFront,
            UserPaperType::ForeignerCardBack,
            UserPaperType::Passport,
            UserPaperType::PortraitImage,
            UserPaperType::StudentCardFront,
            UserPaperType::StudentCardBack,
            UserPaperType::WorkPermitStamp,
        ];
        $types = array_filter($types, static fn(UserPaperType $type) => !in_array($type, $except, true));

        foreach ($types as $type) {
            UserPaperItem::factory()->create([
                'user_paper_id' => $paper->id,
                'type' => $type->value,
                'approved_at' => now(),
                'rejected_at' => null,
                'folder' => UserPaperItemFolder::Current->value,
            ]);
        }
    }

    /**
     * The state a confirmed student card leaves behind. DocumentStepSubmitTest reaches it
     * through the real upload → OCR → submit endpoints.
     */
    private function confirmStudentCardStep(UserPaper $paper): UserPaperStep
    {
        return UserPaperStep::create([
            'user_paper_id' => $paper->id,
            'step_key' => UserPaperStepKey::StudentCard->value,
            'status' => UserPaperStepStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);
    }

    private function makeStudyAbroad(?string $studentCardExpiryDate): void
    {
        $this->employee->info->update([
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_expiry_date_1' => now()->addYears(2)->toDateString(),
            'student_card_expiry_date' => $studentCardExpiryDate,
        ]);
    }

    public function testApprovePapersRejectsUserNotWaitingForApproval(): void
    {
        Mail::fake();
        $this->fakeCrossStaffApi();

        $paper = UserPaper::factory()->create(['user_id' => $this->employee->id]);
        $item = UserPaperItem::factory()->create(['user_paper_id' => $paper->id, 'approved_at' => null]);

        $notDecidableStatuses = [
            UserPaperStatus::Pending->value,
            UserPaperStatus::Approved->value,
            UserPaperStatus::Rejected->value,
        ];

        foreach ($notDecidableStatuses as $status) {
            $this->employee->update(['paper_status' => $status]);

            $this->actingAs($this->admin, 'api')
                ->postJson("/admin/users/{$this->employee->id}/papers/approve", [
                    'items' => [['id' => $item->id, 'approved' => true]],
                ])
                ->assertStatus(422);

            $this->assertSame($status, $this->employee->refresh()->paper_status);
            $this->assertNull($item->refresh()->approved_at);
        }
    }

    // -------------------------------------------------------------------------
    // Configs
    // -------------------------------------------------------------------------

    public function testGetUserConfigsRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson("/admin/users/{$this->employee->id}/configs")
            ->assertStatus(403);
    }

    public function testGetUserConfigsReturnsData(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/users/{$this->employee->id}/configs");

        $response->assertStatus(200);
    }

    public function testUpdateUserConfigsRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->putJson("/admin/users/{$this->employee->id}/configs", [
                'max_hour_per_week' => 40,
                'max_shift_per_day' => 2,
            ])
            ->assertStatus(403);
    }

    public function testUpdateUserConfigsSuccess(): void
    {
        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/users/{$this->employee->id}/configs", [
            'max_hour_per_week' => 40,
            'max_shift_per_day' => 2,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('user_configs', [
            'user_id' => $this->employee->id,
            'max_hour_per_week' => 40,
            'max_shift_per_day' => 2,
        ]);
    }

    // -------------------------------------------------------------------------
    // Sync to/from CrossStaff
    // -------------------------------------------------------------------------

    public function testSyncToCrossStaffRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson("/admin/users/{$this->employee->id}/to-cross-staff")
            ->assertStatus(403);
    }

    public function testSyncToCrossStaffUpdatesRemoteUserWhenAlreadyThere(): void
    {
        $this->fakeCrossStaffApi($this->crossStaffFoundFixture());
        $this->employee->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2026-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'BB22222222BB',
            'residence_expiry_date_2' => '2029-06-30',
            'residence_status_2' => ResidenceStatus::PermanentResident->value,
            'residence_number_3' => 'CC33333333CC',
            'residence_expiry_date_3' => '2028-01-01',
            'residence_status_3' => ResidenceStatus::FamilyStay->value,
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/to-cross-staff")
            ->assertStatus(200);

        $this->assertSame('EXT123456789', $this->employee->fresh()->cross_staff_id);
        Http::assertSent(
            fn($request) => str_contains($request->url(), 'updMemberExternal') &&
                $request['customAttribute2'] === 'BB22222222BB' &&
                $request['customAttribute3'] === '2029/06/30' &&
                $request['customAttribute4'] === mb_convert_encoding('永住者', 'Windows-31J', 'UTF-8')
        );
    }

    public function testSyncToCrossStaffCreatesRemoteUserWhenMissing(): void
    {
        $this->fakeCrossStaffApi();
        $this->employee->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2026-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'BB22222222BB',
            'residence_expiry_date_2' => '2029-06-30',
            'residence_status_2' => ResidenceStatus::PermanentResident->value,
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/to-cross-staff")
            ->assertStatus(200);

        Http::assertSent(
            fn($request) => str_contains($request->url(), 'insMemberExternal') &&
                $request['customAttribute2'] === 'BB22222222BB' &&
                $request['customAttribute3'] === '2029/06/30' &&
                $request['customAttribute4'] === mb_convert_encoding('永住者', 'Windows-31J', 'UTF-8')
        );
    }

    public function testSyncToCrossStaffPushesStaffNoThatCrossStaffRefusedAtApproval(): void
    {
        Mail::fake();
        $remote = $this->crossStaffFoundFixture();
        $remote['GetMemberListExternal']['ResultSet']['Result']['StaffNo'] = '';
        Http::fake([
            'getMemberListExternal*' => Http::response($remote),
            // The approval's push is refused (the number is held by another member), the retry goes through
            'updMemberExternal*' => Http::sequence()
                ->push([
                    'UpdMemberExternal' => [
                        'ResultSet' => [
                            'Error' => [
                                'code' => '4200200004',
                                'mes' => '指定されたスタッフ番号は既に登録されています',
                            ],
                        ],
                    ],
                ])
                ->push(['UpdMemberExternal' => ['ResultSet' => ['Error' => null]]]),
        ]);

        $this->employee->update([
            'paper_status' => UserPaperStatus::WaitForApproval->value,
            'staff_no' => null,
            'cross_staff_id' => (string) $this->employee->id,
        ]);
        $paper = UserPaper::factory()->create(['user_id' => $this->employee->id]);
        $item = UserPaperItem::factory()->create(['user_paper_id' => $paper->id, 'approved_at' => null]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/papers/approve", [
                'items' => [['id' => $item->id, 'approved' => true]],
            ])
            ->assertStatus(200);
        $staffNo = $this->employee->refresh()->staff_no;
        $this->assertNotNull($staffNo);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/to-cross-staff")
            ->assertStatus(200);

        $pushes = Http::recorded(fn($request) => str_contains($request->url(), 'updMemberExternal'))->values();
        $this->assertCount(2, $pushes);
        [$retry] = $pushes[1];
        $this->assertSame((string) $staffNo, (string) $retry['staffNo']);
        $this->assertFalse(isset($retry['staff_no']));
    }

    public function testSyncToCrossStaffLeavesStaffNoAlreadyOnCrossStaff(): void
    {
        $this->fakeCrossStaffApi($this->crossStaffFoundFixture());
        $this->employee->update(['staff_no' => '100200']);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/to-cross-staff")
            ->assertStatus(200);

        Http::assertSent(
            fn($request) => str_contains($request->url(), 'updMemberExternal') && !isset($request['staffNo'])
        );
    }

    public function testSyncFromCrossStaffRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson("/admin/users/{$this->employee->id}/from-cross-staff")
            ->assertStatus(403);
    }

    public function testSyncFromCrossStaffCopiesRemoteDataOntoUser(): void
    {
        $this->fakeCrossStaffApi($this->crossStaffFoundFixture());

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/from-cross-staff")
            ->assertStatus(200);

        $fresh = $this->employee->fresh();
        $this->assertSame('test1@example.com', $fresh->email);
        $this->assertSame('EXT123456789', $fresh->cross_staff_id);
        $this->assertSame('09012345678', $fresh->info->phone);
    }

    /**
     * A passport expiry the employee never entered comes back from CrossStaff as an empty
     * string. Now that the column is a real DATE, MySQL in strict mode would refuse that
     * value and roll back the whole sync, residence data included.
     */
    public function testSyncFromCrossStaffStoresBlankPassportExpiryAsNull(): void
    {
        $this->employee->info->update([
            'passport_expiry_date' => '2026-06-30',
            'residence_expiry_date_1' => null,
        ]);
        $this->fakeCrossStaffApi($this->crossStaffFoundFixture(['パスポート期限' => '', '在留期限' => '2027-12-31']));

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/from-cross-staff")
            ->assertStatus(200);

        $info = $this->employee->fresh()->info;
        $this->assertNull($info->passport_expiry_date);
        $this->assertSame('2027-12-31', $info->residence_expiry_date_1);
        $this->assertSame('09012345678', $info->phone);
    }

    /**
     * Some systems use an all-zero date for "not entered". It parses (to a negative year),
     * but the DATE column does not accept the result, so it has to be dropped the same way
     * a blank value is instead of failing the sync.
     */
    public function testSyncFromCrossStaffDropsOutOfRangeDates(): void
    {
        $this->fakeCrossStaffApi(
            $this->crossStaffFoundFixture(['パスポート期限' => '0000-00-00', '在留期限' => '2027-12-31'])
        );

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/from-cross-staff")
            ->assertStatus(200);

        $info = $this->employee->fresh()->info;
        $this->assertNull($info->passport_expiry_date);
        $this->assertSame('2027-12-31', $info->residence_expiry_date_1);
    }

    public function testSyncFromCrossStaffReturns404WhenRemoteUserMissing(): void
    {
        $this->fakeCrossStaffApi();

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/from-cross-staff")
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Restore deleted user
    // -------------------------------------------------------------------------

    public function testRestoreDeletedUserRequiresPermission(): void
    {
        $user = User::factory()->create();
        $deleted = User::factory()->create(['email' => 'del1234567_original@example.com']);

        $this->actingAs($user, 'api')
            ->postJson("/admin/users/{$deleted->id}/restore")
            ->assertStatus(403);
    }

    public function testRestoreDeletedUserSuccess(): void
    {
        $deleted = User::factory()->create([
            'email' => 'del1234567_toberestored@example.com',
            'is_disabled' => true,
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/users/{$deleted->id}/restore");

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $deleted->id,
            'email' => 'toberestored@example.com',
            'is_disabled' => false,
        ]);
    }

    public function testRestoreDeletedUserReturnsShortenedNameForSriLankaEmployee(): void
    {
        $deleted = $this->sriLankaEmployee([
            'email' => 'del1234567_srilanka@example.com',
            'is_disabled' => true,
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/users/{$deleted->id}/restore");

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'THARINDU RAVISHANN');
    }

    public function testRestoreDeletedUserFailsWhenEmailNotDeletedFormat(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$this->employee->id}/restore")
            ->assertStatus(400);
    }

    // -------------------------------------------------------------------------
    // Employee creation flow
    // -------------------------------------------------------------------------

    public function testInitEmployeeRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson('/admin/users/employees/init', ['email' => 'newemp@example.com'])
            ->assertStatus(403);
    }

    public function testInitEmployeeSuccess(): void
    {
        $this->fakeCrossStaffApi();

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/users/employees/init', [
            'email' => 'newemp@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['key', 'email']]);
        $response->assertJsonPath('data.email', 'newemp@example.com');
        $response->assertJsonPath('data.user', null);

        Http::assertSent(
            fn($request) => str_contains($request->url(), 'getMemberListExternal') &&
                str_contains($request->url(), urlencode('newemp@example.com'))
        );
    }

    public function testInitEmployeePrefillsDataFromCrossStaff(): void
    {
        $this->fakeCrossStaffApi($this->crossStaffFoundFixture());

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/users/employees/init', [
            'email' => 'newemp@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.user.staff_no', 'STAFF001');
    }

    public function testInitEmployeeSucceedsWhenCrossStaffIsDown(): void
    {
        Http::fake([
            'getMemberListExternal*' => Http::response('', 500),
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/users/employees/init', [
            'email' => 'newemp@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.user', null);
    }

    public function testInitEmployeeSucceedsWhenCrossStaffThrows(): void
    {
        Http::fake([
            'getMemberListExternal*' => fn() => throw new RuntimeException('CrossStaff exploded'),
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/users/employees/init', [
            'email' => 'newemp@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.user', null);
    }

    public function testInitEmployeeRejectsDuplicateEmail(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/users/employees/init', ['email' => $this->employee->email])
            ->assertStatus(422);
    }

    public function testSetEmployeePasswordRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson('/admin/users/employees/set-password', [
                'key' => 'invalid',
                'password' => 'secret',
                'password_confirmation' => 'secret',
            ])
            ->assertStatus(403);
    }

    public function testSetEmployeePasswordFailsWithInvalidKey(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/users/employees/set-password', [
                'key' => 'nonexistent-key',
                'password' => 'secret',
                'password_confirmation' => 'secret',
            ])
            ->assertStatus(400);
    }

    public function testSetEmployeePasswordSuccess(): void
    {
        $key = 'emp_testkey123';
        Cache::put($key, ['email' => 'newemp@example.com', 'is_verified' => true, 'user' => null], now()->addDay());

        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/users/employees/set-password', [
                'key' => $key,
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ])
            ->assertStatus(200);
    }

    public function testRegisterEmployeeRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->postJson('/admin/users/employees/register', [])->assertStatus(403);
    }

    public function testRegisterEmployeeFailsWithInvalidCacheKey(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/users/employees/register', [
                'key' => 'nonexistent-key',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'first_name_kana' => 'John',
                'last_name_kana' => 'Doe',
                'plain_password' => 'secret123',
            ])
            ->assertStatus(400);
    }

    public function testRegisterEmployeeFailsWhenTempUserMissing(): void
    {
        $key = 'emp_regtest';
        Cache::put($key, ['email' => 'reg@example.com', 'is_verified' => true, 'user' => null], now()->addDay());

        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/users/employees/register', [
                'key' => $key,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'first_name_kana' => 'John',
                'last_name_kana' => 'Doe',
                'plain_password' => 'secret123',
            ])
            ->assertStatus(400);
    }

    public function testRegisterEmployeeSuccess(): void
    {
        Mail::fake();

        Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);

        $key = 'emp_regtest_ok';
        $email = 'newemployee@example.com';
        $plainPassword = 'secret123';

        Cache::put($key, ['email' => $email, 'is_verified' => true, 'user' => null], now()->addDay());
        TempUser::create(['email' => $email, 'password' => Hash::make($plainPassword)]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/users/employees/register', [
            'key' => $key,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'first_name_kana' => 'John',
            'last_name_kana' => 'Doe',
            'plain_password' => $plainPassword,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => $email]);
        $this->assertDatabaseMissing('temp_users', ['email' => $email]);
    }

    public function testRegisterEmployeeReportsDuplicateResidenceNumber(): void
    {
        $this->employee->info()->update(['residence_number_1' => 'AB1234567890']);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/admin/users/employees/register',
            $this->seedEmployeeRegistration(['residence_number_1' => 'AB1234567890'])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::RegisterResidenceNumberAlreadyExists->value);
    }

    public function testRegisterEmployeeReportsDuplicatePassportNumber(): void
    {
        $this->employee->info()->update(['passport_number' => 'P1234567']);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/admin/users/employees/register',
            $this->seedEmployeeRegistration(['passport_number' => 'P1234567'])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::RegisterPassportNumberAlreadyExists->value);
    }

    public function testRegisterEmployeeRethrowsUnrelatedValidationErrors(): void
    {
        $payload = $this->seedEmployeeRegistration();
        unset($payload['first_name']);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/users/employees/register', $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $response->assertJsonStructure(['data' => ['first_name']]);
    }

    /**
     * @param  array<string, string>  $customAttributes  Custom attribute values to override, keyed by NAME.
     */
    private function crossStaffFoundFixture(array $customAttributes = []): array
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/cross_staff_user_found.json')), true);

        foreach ($fixture['GetMemberListExternal']['ResultSet']['Result']['CustomAttribute'] as &$attribute) {
            if (array_key_exists($attribute['NAME'], $customAttributes)) {
                $attribute['VALUE'] = $customAttributes[$attribute['NAME']];
            }
        }

        return $fixture;
    }

    private function sriLankaEmployee(array $attributes = []): User
    {
        $user = User::factory()->create(
            array_merge(
                [
                    'first_name' => 'Ragalage Tharindu Ravishann',
                    'last_name' => 'Karnasooriya',
                    'paper_status' => UserPaperStatus::Approved->value,
                ],
                $attributes
            )
        );
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        return $user;
    }

    private function updatePayload(User $user, array $overrides = []): array
    {
        return array_merge(
            [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'first_name_kana' => $user->first_name_kana,
                'last_name_kana' => $user->last_name_kana,
                'email' => $user->email,
            ],
            $overrides
        );
    }

    private function fakeCrossStaffApi(?array $searchResponse = null): void
    {
        Http::fake([
            'getMemberListExternal*' => Http::response(
                $searchResponse ?? [
                    'GetMemberListExternal' => [
                        'ResultSet' => [
                            'TotalResult' => 0,
                            'Error' => null,
                        ],
                    ],
                ]
            ),
            'insMemberExternal*' => Http::response([
                'InsMemberExternal' => [
                    'ResultSet' => [
                        'Error' => null,
                    ],
                ],
            ]),
            'updMemberExternal*' => Http::response([
                'UpdMemberExternal' => [
                    'ResultSet' => [
                        'Error' => null,
                    ],
                ],
            ]),
        ]);
    }

    private function seedEmployeeRegistration(array $overrides = []): array
    {
        $key = 'emp_' . Str::random();
        $email = 'dup@example.com';

        Cache::put($key, ['email' => $email, 'is_verified' => true, 'user' => null], now()->addDay());
        TempUser::create(['email' => $email, 'password' => Hash::make('secret123')]);

        return array_merge(
            [
                'key' => $key,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'first_name_kana' => 'John',
                'last_name_kana' => 'Doe',
                'plain_password' => 'secret123',
            ],
            $overrides
        );
    }

    private function employeeUpdatePayload(): array
    {
        return [
            'first_name' => $this->employee->first_name,
            'last_name' => $this->employee->last_name,
            'first_name_kana' => $this->employee->first_name_kana,
            'last_name_kana' => $this->employee->last_name_kana,
            'email' => $this->employee->email,
            'staff_no' => $this->employee->staff_no,
        ];
    }
}
