<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceImage;
use App\Models\WorkplaceInstruction;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class WorkplaceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            PermissionName::WorkplaceList->value,
            PermissionName::WorkplaceCreate->value,
            PermissionName::WorkplaceEdit->value,
            PermissionName::WorkplaceDelete->value,
            PermissionName::WorkplaceImageList->value,
            PermissionName::WorkplaceImageUpload->value,
            PermissionName::WorkplaceImageDelete->value,
            PermissionName::WorkplacePositionList->value,
            PermissionName::WorkplacePositionCreate->value,
            PermissionName::WorkplacePositionEdit->value,
            PermissionName::WorkplacePositionDelete->value,
            PermissionName::WorkplaceShiftList->value,
            PermissionName::WorkplaceShiftCreate->value,
            PermissionName::WorkplaceShiftEdit->value,
            PermissionName::WorkplaceShiftDelete->value,
            PermissionName::WorkplaceInstructionList->value,
            PermissionName::WorkplaceInstructionCreate->value,
            PermissionName::WorkplaceInstructionEdit->value,
            PermissionName::WorkplaceInstructionDelete->value,
            PermissionName::WorkplaceUserList->value,
            PermissionName::WorkplaceUserAdd->value,
            PermissionName::WorkplaceUserRemove->value,
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name], ['display_name' => $name]);
        }

        $this->admin = User::factory()->create();
        foreach ($permissions as $name) {
            $this->admin->permissions()->attach(Permission::where('name', $name)->first());
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function validWorkplacePayload(array $overrides = []): array
    {
        return array_merge(
            [
                'name' => 'Test Workplace',
                'address' => 'Tokyo, Japan',
                'coordinates' => '35.6895, 139.6917',
            ],
            $overrides
        );
    }

    private function validShiftPayload(array $overrides = []): array
    {
        return array_merge(
            [
                'start_time' => '09:00',
                'end_time' => '17:00',
                'check_in_round_type' => 1,
                'check_in_round_minutes' => 15,
                'check_out_round_type' => 1,
                'check_out_round_minutes' => 15,
                'date_adjustment' => 0,
                'leaders' => [],
            ],
            $overrides
        );
    }

    private function createWorkplaceShiftRecord(Workplace $workplace): WorkplaceShift
    {
        return WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
            'date_adjustment' => 0,
        ]);
    }

    private function createApprovedFutureShiftUser(User $user, Workplace $workplace): ShiftUser
    {
        $workplaceShift = $this->createWorkplaceShiftRecord($workplace);

        $position = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $date = Carbon::today()->addDays(7)->toDateString();

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);
    }

    /****************************************
     * index                                *
     ****************************************/

    public function testIndexReturnsPaginatedWorkplaces(): void
    {
        Workplace::factory()->count(3)->create();

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/workplaces');

        $response->assertOk();
        $this->assertSame(3, $response->json('data.total'));
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->getJson('/admin/workplaces')->assertUnauthorized();
    }

    /****************************************
     * store                                *
     ****************************************/

    public function testStoreCreatesWorkplace(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/workplaces', $this->validWorkplacePayload());

        $response->assertOk();
        $this->assertDatabaseHas('workplaces', ['name' => 'Test Workplace', 'is_test' => false]);
    }

    public function testStoreCreatesTestWorkplace(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/admin/workplaces',
            $this->validWorkplacePayload(['is_test' => true])
        );

        $response->assertOk();
        $this->assertDatabaseHas('workplaces', ['name' => 'Test Workplace', 'is_test' => true]);
    }

    public function testStoreCreatesYamatoWorkplace(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/admin/workplaces',
            $this->validWorkplacePayload(['is_yamato' => true])
        );

        $response->assertOk();
        $this->assertDatabaseHas('workplaces', ['name' => 'Test Workplace', 'is_yamato' => true]);
    }

    public function testStoreCreatesSelfRegisterDisabledWorkplace(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/admin/workplaces',
            $this->validWorkplacePayload(['is_self_register_disabled' => true])
        );

        $response->assertOk();
        $this->assertDatabaseHas('workplaces', [
            'name' => 'Test Workplace',
            'is_self_register_disabled' => true,
        ]);
    }

    public function testStoreDefaultsSelfRegisterDisabledToFalse(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/workplaces', $this->validWorkplacePayload());

        $response->assertOk();
        $this->assertDatabaseHas('workplaces', [
            'name' => 'Test Workplace',
            'is_self_register_disabled' => false,
        ]);
    }

    public function testUpdateTogglesSelfRegisterDisabled(): void
    {
        $workplace = Workplace::factory()->create(['is_self_register_disabled' => false]);

        $this->actingAs($this->admin, 'api')
            ->putJson(
                "/admin/workplaces/{$workplace->id}",
                $this->validWorkplacePayload(['is_self_register_disabled' => true])
            )
            ->assertOk();

        $this->assertDatabaseHas('workplaces', [
            'id' => $workplace->id,
            'is_self_register_disabled' => true,
        ]);

        $this->actingAs($this->admin, 'api')
            ->putJson(
                "/admin/workplaces/{$workplace->id}",
                $this->validWorkplacePayload(['is_self_register_disabled' => false])
            )
            ->assertOk();

        $this->assertDatabaseHas('workplaces', [
            'id' => $workplace->id,
            'is_self_register_disabled' => false,
        ]);
    }

    public function testStoreRequiresAuthentication(): void
    {
        $this->postJson('/admin/workplaces', $this->validWorkplacePayload())->assertUnauthorized();
    }

    public function testStoreRequiresPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->postJson('/admin/workplaces', $this->validWorkplacePayload())->assertForbidden();
    }

    /****************************************
     * show                                 *
     ****************************************/

    public function testShowReturnsWorkplace(): void
    {
        $workplace = Workplace::factory()->create(['name' => 'My Site']);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/workplaces/{$workplace->id}");

        $response->assertOk()->assertJsonPath('data.name', 'My Site');
    }

    /****************************************
     * update                               *
     ****************************************/

    public function testUpdateChangesWorkplaceName(): void
    {
        $workplace = Workplace::factory()->create(['name' => 'Old Name', 'is_test' => false]);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$workplace->id}",
            $this->validWorkplacePayload(['name' => 'New Name'])
        );

        $response->assertOk()->assertJsonPath('data.name', 'New Name');
        $this->assertDatabaseHas('workplaces', ['id' => $workplace->id, 'name' => 'New Name']);
    }

    public function testUpdateCanSetIsTestWhenNoMembers(): void
    {
        $workplace = Workplace::factory()->create(['is_test' => false]);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$workplace->id}",
            $this->validWorkplacePayload(['is_test' => true])
        );

        $response->assertOk();
        $this->assertDatabaseHas('workplaces', ['id' => $workplace->id, 'is_test' => true]);
    }

    public function testUpdateRejectsMakingWorkplaceTestWhenNormalMembersExist(): void
    {
        $workplace = Workplace::factory()->create(['is_test' => false]);
        $normalUser = User::factory()->create(['is_test' => false]);
        WorkplaceUser::create(['user_id' => $normalUser->id, 'workplace_id' => $workplace->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$workplace->id}",
            $this->validWorkplacePayload(['is_test' => true])
        );

        $response->assertStatus(400)->assertJsonPath('error', 'WORKPLACE_TEST_MEMBERSHIP_MISMATCH');
        $this->assertDatabaseHas('workplaces', ['id' => $workplace->id, 'is_test' => false]);
    }

    public function testUpdateAllowsMakingWorkplaceTestWhenOnlyTestMembersExist(): void
    {
        $workplace = Workplace::factory()->create(['is_test' => false]);
        $testUser = User::factory()->create(['is_test' => true]);
        WorkplaceUser::create(['user_id' => $testUser->id, 'workplace_id' => $workplace->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$workplace->id}",
            $this->validWorkplacePayload(['is_test' => true])
        );

        $response->assertOk();
        $this->assertDatabaseHas('workplaces', ['id' => $workplace->id, 'is_test' => true]);
    }

    public function testUpdateRequiresAuthentication(): void
    {
        $workplace = Workplace::factory()->create();

        $this->putJson("/admin/workplaces/{$workplace->id}", $this->validWorkplacePayload())->assertUnauthorized();
    }

    public function testUpdateRequiresPermission(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->putJson("/admin/workplaces/{$workplace->id}", $this->validWorkplacePayload())
            ->assertForbidden();
    }

    /****************************************
     * destroy                              *
     ****************************************/

    public function testDestroyDeletesWorkplace(): void
    {
        $workplace = Workplace::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/admin/workplaces/{$workplace->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('workplaces', ['id' => $workplace->id]);
    }

    public function testDestroyFailsWhenWorkplaceHasApprovedShiftUsers(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();
        $this->createApprovedFutureShiftUser($user, $workplace);

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/admin/workplaces/{$workplace->id}");

        $response->assertStatus(400)->assertJsonPath('error', 'WORKPLACE_CANNOT_DELETE_WITH_SHIFT');
        $this->assertDatabaseHas('workplaces', ['id' => $workplace->id]);
    }

    /****************************************
     * Images                               *
     ****************************************/

    public function testListImagesReturnsWorkplaceImages(): void
    {
        $workplace = Workplace::factory()->create();
        WorkplaceImage::create(['workplace_id' => $workplace->id, 'image' => 'path/to/img.jpg']);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/workplaces/{$workplace->id}/images");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function testUploadImagesStoresFiles(): void
    {
        Storage::fake();
        $workplace = Workplace::factory()->create();
        $file = UploadedFile::fake()->image('photo.jpg');

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/workplaces/{$workplace->id}/images", [
            'images' => [$file],
        ]);

        $response->assertOk();
        $this->assertSame(1, WorkplaceImage::where('workplace_id', $workplace->id)->count());
    }

    public function testDeleteImageRemovesRecord(): void
    {
        Storage::fake();
        $workplace = Workplace::factory()->create();
        $image = WorkplaceImage::create(['workplace_id' => $workplace->id, 'image' => 'path/img.jpg']);

        $response = $this->actingAs($this->admin, 'api')->deleteJson(
            "/admin/workplaces/{$workplace->id}/images/{$image->id}"
        );

        $response->assertOk();
        $this->assertDatabaseMissing('workplace_images', ['id' => $image->id]);
    }

    public function testUploadImagesRequiresPermission(): void
    {
        Storage::fake();
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->actingAs($user, 'api')
            ->postJson("/admin/workplaces/{$workplace->id}/images", ['images' => [$file]])
            ->assertForbidden();
    }

    /****************************************
     * Positions                            *
     ****************************************/

    public function testListPositionsReturnsActivePositions(): void
    {
        $workplace = Workplace::factory()->create();
        WorkplacePosition::create(['workplace_id' => $workplace->id, 'name' => 'Cashier', 'is_active' => true]);
        WorkplacePosition::create(['workplace_id' => $workplace->id, 'name' => 'Inactive', 'is_active' => false]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/workplaces/{$workplace->id}/positions");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Cashier', $names);
        $this->assertNotContains('Inactive', $names);
    }

    public function testStorePositionCreatesPosition(): void
    {
        $workplace = Workplace::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/workplaces/{$workplace->id}/positions", [
            'name' => 'Cashier',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('workplace_positions', [
            'workplace_id' => $workplace->id,
            'name' => 'Cashier',
        ]);
    }

    public function testUpdatePositionChangesName(): void
    {
        $workplace = Workplace::factory()->create();
        $position = WorkplacePosition::create(['workplace_id' => $workplace->id, 'name' => 'Old']);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$workplace->id}/positions/{$position->id}",
            ['name' => 'New']
        );

        $response->assertOk();
        $this->assertDatabaseHas('workplace_positions', ['id' => $position->id, 'name' => 'New']);
    }

    public function testDeletePositionRemovesRecord(): void
    {
        $workplace = Workplace::factory()->create();
        $position = WorkplacePosition::create(['workplace_id' => $workplace->id, 'name' => 'Staff']);

        $response = $this->actingAs($this->admin, 'api')->deleteJson(
            "/admin/workplaces/{$workplace->id}/positions/{$position->id}"
        );

        $response->assertOk();
        $this->assertDatabaseMissing('workplace_positions', ['id' => $position->id]);
    }

    public function testReorderPositionsUpdatesOrder(): void
    {
        $workplace = Workplace::factory()->create();
        $p1 = WorkplacePosition::create(['workplace_id' => $workplace->id, 'name' => 'A', 'sort_order' => 2]);
        $p2 = WorkplacePosition::create(['workplace_id' => $workplace->id, 'name' => 'B', 'sort_order' => 1]);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$workplace->id}/positions/reorder",
            [
                'positions_order' => [$p1->id, $p2->id],
            ]
        );

        $response->assertOk();
        $this->assertSame(1, (int) WorkplacePosition::find($p1->id)->sort_order);
        $this->assertSame(2, (int) WorkplacePosition::find($p2->id)->sort_order);
    }

    public function testStorePositionRequiresPermission(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson("/admin/workplaces/{$workplace->id}/positions", ['name' => 'Cashier'])
            ->assertForbidden();
    }

    /****************************************
     * Shifts                               *
     ****************************************/

    public function testListShiftsReturnsActiveShifts(): void
    {
        $workplace = Workplace::factory()->create();
        $this->createWorkplaceShiftRecord($workplace);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/workplaces/{$workplace->id}/shifts");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function testStoreShiftCreatesShift(): void
    {
        $workplace = Workplace::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/admin/workplaces/{$workplace->id}/shifts",
            $this->validShiftPayload()
        );

        $response->assertOk();
        $this->assertSame(1, WorkplaceShift::where('workplace_id', $workplace->id)->count());
    }

    public function testStoreShiftRejectsEqualBreakTimesOnAShiftShorterThanADay(): void
    {
        $workplace = Workplace::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/admin/workplaces/{$workplace->id}/shifts",
            $this->validShiftPayload(['start_break_time' => '12:00', 'end_break_time' => '12:00'])
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('data.end_break_time', ['The end break time field must be a date before end time.']);
        $this->assertSame(0, WorkplaceShift::where('workplace_id', $workplace->id)->count());
    }

    public function testUpdateShiftChangesTime(): void
    {
        $workplace = Workplace::factory()->create();
        $shift = $this->createWorkplaceShiftRecord($workplace);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$workplace->id}/shifts/{$shift->id}",
            $this->validShiftPayload(['start_time' => '10:00'])
        );

        $response->assertOk();
        $this->assertSame('10:00', WorkplaceShift::find($shift->id)->start_time);
    }

    public function testDeleteShiftRemovesRecord(): void
    {
        $workplace = Workplace::factory()->create();
        $shift = $this->createWorkplaceShiftRecord($workplace);

        $response = $this->actingAs($this->admin, 'api')->deleteJson(
            "/admin/workplaces/{$workplace->id}/shifts/{$shift->id}"
        );

        $response->assertOk();
        $this->assertDatabaseMissing('workplace_shifts', ['id' => $shift->id]);
    }

    public function testStoreShiftRequiresPermission(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson("/admin/workplaces/{$workplace->id}/shifts", $this->validShiftPayload())
            ->assertForbidden();
    }

    /****************************************
     * Instructions                         *
     ****************************************/

    public function testListInstructionsReturnsAll(): void
    {
        $workplace = Workplace::factory()->create();
        WorkplaceInstruction::create([
            'workplace_id' => $workplace->id,
            'type' => 'link',
            'title' => 'Guide',
            'content' => 'https://example.com',
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/workplaces/{$workplace->id}/instructions");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function testStoreInstructionCreatesLinkInstruction(): void
    {
        $workplace = Workplace::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/workplaces/{$workplace->id}/instructions", [
            'type' => 'link',
            'title' => 'Guide',
            'content' => 'https://example.com',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('workplace_instructions', [
            'workplace_id' => $workplace->id,
            'title' => 'Guide',
        ]);
    }

    public function testUpdateInstructionChangesTitle(): void
    {
        $workplace = Workplace::factory()->create();
        $instruction = WorkplaceInstruction::create([
            'workplace_id' => $workplace->id,
            'type' => 'link',
            'title' => 'Old',
            'content' => 'https://example.com',
        ]);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$workplace->id}/instructions/{$instruction->id}",
            [
                'type' => 'link',
                'title' => 'New',
                'content' => 'https://example.com',
            ]
        );

        $response->assertOk();
        $this->assertDatabaseHas('workplace_instructions', ['id' => $instruction->id, 'title' => 'New']);
    }

    public function testDeleteInstructionRemovesRecord(): void
    {
        $workplace = Workplace::factory()->create();
        $instruction = WorkplaceInstruction::create([
            'workplace_id' => $workplace->id,
            'type' => 'link',
            'title' => 'Guide',
            'content' => 'https://example.com',
        ]);

        $response = $this->actingAs($this->admin, 'api')->deleteJson(
            "/admin/workplaces/{$workplace->id}/instructions/{$instruction->id}"
        );

        $response->assertOk();
        $this->assertDatabaseMissing('workplace_instructions', ['id' => $instruction->id]);
    }

    public function testStoreInstructionRequiresPermission(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson("/admin/workplaces/{$workplace->id}/instructions", [
                'type' => 'link',
                'title' => 'Guide',
                'content' => 'https://example.com',
            ])
            ->assertForbidden();
    }

    /****************************************
     * Users                                *
     ****************************************/

    public function testListUsersReturnsMembersOfWorkplace(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();
        WorkplaceUser::create(['user_id' => $user->id, 'workplace_id' => $workplace->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/workplaces/{$workplace->id}/users");

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($user->id, $ids);
    }

    public function testAddUsersAttachesUserToWorkplace(): void
    {
        $workplace = Workplace::factory()->create(['is_test' => false]);
        $user = User::factory()->create(['is_test' => false]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/workplaces/{$workplace->id}/users", [
            'user_ids' => [$user->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('workplace_users', [
            'workplace_id' => $workplace->id,
            'user_id' => $user->id,
        ]);
    }

    public function testAddUsersAttachesUserToSelfRegisterDisabledWorkplace(): void
    {
        $workplace = Workplace::factory()->create(['is_self_register_disabled' => true]);
        $user = User::factory()->create(['is_test' => false]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/workplaces/{$workplace->id}/users", [
            'user_ids' => [$user->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('workplace_users', [
            'workplace_id' => $workplace->id,
            'user_id' => $user->id,
        ]);
    }

    public function testAddUsersRejectsNormalUserOnTestWorkplace(): void
    {
        $workplace = Workplace::factory()->create(['is_test' => true]);
        $user = User::factory()->create(['is_test' => false]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/workplaces/{$workplace->id}/users", [
            'user_ids' => [$user->id],
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'WORKPLACE_TEST_MEMBERSHIP_MISMATCH');
        $this->assertDatabaseMissing('workplace_users', [
            'workplace_id' => $workplace->id,
            'user_id' => $user->id,
        ]);
    }

    public function testAddUsersRejectsTestUserOnNormalWorkplace(): void
    {
        $workplace = Workplace::factory()->create(['is_test' => false]);
        $user = User::factory()->create(['is_test' => true]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/workplaces/{$workplace->id}/users", [
            'user_ids' => [$user->id],
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'WORKPLACE_TEST_MEMBERSHIP_MISMATCH');
    }

    public function testAddUsersRequiresAuthentication(): void
    {
        $workplace = Workplace::factory()->create();

        $this->postJson("/admin/workplaces/{$workplace->id}/users", ['user_ids' => [1]])->assertUnauthorized();
    }

    public function testAddUsersRequiresPermission(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson("/admin/workplaces/{$workplace->id}/users", ['user_ids' => [$user->id]])
            ->assertForbidden();
    }

    public function testDetachUserRemovesMemberWithNoFutureShifts(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();
        WorkplaceUser::create(['user_id' => $user->id, 'workplace_id' => $workplace->id]);

        $response = $this->actingAs($this->admin, 'api')->deleteJson(
            "/admin/workplaces/{$workplace->id}/users/{$user->id}"
        );

        $response->assertOk();
        $this->assertDatabaseMissing('workplace_users', [
            'workplace_id' => $workplace->id,
            'user_id' => $user->id,
        ]);
    }

    public function testDetachUserFailsWhenUserHasFutureApprovedShift(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();
        WorkplaceUser::create(['user_id' => $user->id, 'workplace_id' => $workplace->id]);
        $this->createApprovedFutureShiftUser($user, $workplace);

        $response = $this->actingAs($this->admin, 'api')->deleteJson(
            "/admin/workplaces/{$workplace->id}/users/{$user->id}"
        );

        $response->assertStatus(400)->assertJsonPath('error', 'WORKPLACE_CANNOT_REMOVE_USER_WITH_SHIFT');
        $this->assertDatabaseHas('workplace_users', [
            'workplace_id' => $workplace->id,
            'user_id' => $user->id,
        ]);
    }

    public function testAddUsersIsIdempotentForAlreadyAttachedUsers(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();
        WorkplaceUser::create(['user_id' => $user->id, 'workplace_id' => $workplace->id]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/workplaces/{$workplace->id}/users", [
            'user_ids' => [$user->id],
        ]);

        $response->assertOk();
        $this->assertSame(
            1,
            WorkplaceUser::where('workplace_id', $workplace->id)->where('user_id', $user->id)->count()
        );
    }

    public function testAddUsersAttachesOnlyTheUsersNotAlreadyInTheWorkplace(): void
    {
        $workplace = Workplace::factory()->create();
        $existing = User::factory()->create();
        $newUser = User::factory()->create();
        WorkplaceUser::create(['user_id' => $existing->id, 'workplace_id' => $workplace->id]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/workplaces/{$workplace->id}/users", [
            'user_ids' => [$existing->id, $newUser->id],
        ]);

        $response->assertOk();
        $this->assertSame(2, WorkplaceUser::where('workplace_id', $workplace->id)->count());
    }

    /****************************************
     * index filters                        *
     ****************************************/

    public function testIndexFiltersWorkplacesByPartialName(): void
    {
        $matching = Workplace::factory()->create(['name' => 'Osaka Warehouse']);
        $other = Workplace::factory()->create(['name' => 'Tokyo Office']);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/workplaces?name=Warehouse');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function testIndexFiltersWorkplacesById(): void
    {
        $wanted = Workplace::factory()->create();
        $other = Workplace::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/workplaces?id={$wanted->id}");

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertSame([$wanted->id], $ids);
        $this->assertNotContains($other->id, $ids);
    }

    /****************************************
     * destroy: file cleanup                *
     ****************************************/

    public function testDestroyDeletesStoredImagesAndImageInstructions(): void
    {
        Storage::fake();
        Storage::put('workplaces/photo.jpg', 'photo');
        Storage::put('workplaces/instruction.jpg', 'instruction');

        $workplace = Workplace::factory()->create();
        WorkplaceImage::create(['workplace_id' => $workplace->id, 'image' => 'workplaces/photo.jpg']);
        WorkplaceInstruction::create([
            'workplace_id' => $workplace->id,
            'type' => 'image',
            'title' => 'Map',
            'content' => 'workplaces/instruction.jpg',
        ]);
        WorkplaceInstruction::create([
            'workplace_id' => $workplace->id,
            'type' => 'link',
            'title' => 'Guide',
            'content' => 'https://example.com',
        ]);

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/admin/workplaces/{$workplace->id}");

        $response->assertOk();
        Storage::assertMissing('workplaces/photo.jpg');
        Storage::assertMissing('workplaces/instruction.jpg');
        $this->assertDatabaseMissing('workplaces', ['id' => $workplace->id]);
    }

    public function testDestroyFailsWhenTheWorkplaceIsStillReferencedByAShift(): void
    {
        $workplace = Workplace::factory()->create();
        $workplaceShift = $this->createWorkplaceShiftRecord($workplace);
        $date = Carbon::today()->addDays(7)->toDateString();

        // A shift with no assigned users passes the "has shift users" guard, but the
        // restrict-on-delete foreign key still blocks the delete.
        Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->admin->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/admin/workplaces/{$workplace->id}");

        $response->assertStatus(400)->assertJsonPath('error', 'E_BAD_REQUEST');
        $this->assertDatabaseHas('workplaces', ['id' => $workplace->id]);
    }

    /****************************************
     * uploadImages failure                 *
     ****************************************/

    public function testUploadImagesReturnsUnprocessableEntityWhenStoringFails(): void
    {
        Storage::fake();
        $workplace = Workplace::factory()->create();

        // Swap Laravel's image manager binding for a stub that blows up the way a
        // corrupt upload would.
        $this->app->instance(
            'image',
            new class {
                public function fromUpload(mixed $input): never
                {
                    throw new RuntimeException('cannot read image');
                }
            }
        );

        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/workplaces/{$workplace->id}/images", [
            'images' => [UploadedFile::fake()->image('photo.jpg')],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, WorkplaceImage::where('workplace_id', $workplace->id)->count());
    }

    /****************************************
     * Soft-delete fallbacks                *
     ****************************************/

    public function testDeletePositionDeactivatesItWhenItIsStillReferencedByAShift(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();
        $this->createApprovedFutureShiftUser($user, $workplace);
        $position = WorkplacePosition::where('workplace_id', $workplace->id)->firstOrFail();

        $response = $this->actingAs($this->admin, 'api')->deleteJson(
            "/admin/workplaces/{$workplace->id}/positions/{$position->id}"
        );

        $response->assertOk();
        $this->assertDatabaseHas('workplace_positions', ['id' => $position->id, 'is_active' => false]);
    }

    public function testDeleteShiftDeactivatesItWhenItIsStillReferencedByAShift(): void
    {
        $workplace = Workplace::factory()->create();
        $user = User::factory()->create();
        $this->createApprovedFutureShiftUser($user, $workplace);
        $workplaceShift = WorkplaceShift::where('workplace_id', $workplace->id)->firstOrFail();

        $response = $this->actingAs($this->admin, 'api')->deleteJson(
            "/admin/workplaces/{$workplace->id}/shifts/{$workplaceShift->id}"
        );

        $response->assertOk();
        $this->assertDatabaseHas('workplace_shifts', ['id' => $workplaceShift->id, 'is_active' => false]);
    }

    /****************************************
     * Shift time validation                *
     ****************************************/

    public function testStoreShiftRejectsBreakStartingAfterTheShiftEnds(): void
    {
        $workplace = Workplace::factory()->create();

        $this->actingAs($this->admin, 'api')
            ->postJson(
                "/admin/workplaces/{$workplace->id}/shifts",
                $this->validShiftPayload([
                    'start_break_time' => '18:00',
                    'end_break_time' => '18:30',
                ])
            )
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['start_break_time']]);
    }

    public function testStoreShiftRejectsBreakEndingAfterTheShiftEnds(): void
    {
        $workplace = Workplace::factory()->create();

        $this->actingAs($this->admin, 'api')
            ->postJson(
                "/admin/workplaces/{$workplace->id}/shifts",
                $this->validShiftPayload([
                    'start_break_time' => '16:30',
                    'end_break_time' => '17:30',
                ])
            )
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['end_break_time']]);
    }

    public function testStoreShiftRequiresBothBreakTimesTogether(): void
    {
        $workplace = Workplace::factory()->create();

        $this->actingAs($this->admin, 'api')
            ->postJson(
                "/admin/workplaces/{$workplace->id}/shifts",
                $this->validShiftPayload([
                    'start_break_time' => '12:00',
                ])
            )
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['end_break_time']]);
    }

    public function testStoreShiftAcceptsABreakInsideTheShift(): void
    {
        $workplace = Workplace::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/admin/workplaces/{$workplace->id}/shifts",
            $this->validShiftPayload([
                'start_break_time' => '12:00',
                'end_break_time' => '13:00',
            ])
        );

        $response->assertOk();
        $this->assertDatabaseHas('workplace_shifts', [
            'workplace_id' => $workplace->id,
            'break_duration' => 60,
        ]);
    }

    /****************************************
     * Image instructions                   *
     ****************************************/

    public function testStoreInstructionRequiresAFileForImageType(): void
    {
        $workplace = Workplace::factory()->create();

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/workplaces/{$workplace->id}/instructions", [
                'type' => 'image',
                'title' => 'Map',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['file']]);
    }

    public function testStoreInstructionUploadsTheImageAndStoresItsPath(): void
    {
        Storage::fake();
        $workplace = Workplace::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->post("/admin/workplaces/{$workplace->id}/instructions", [
            'type' => 'image',
            'title' => 'Map',
            'file' => UploadedFile::fake()->image('map.jpg'),
        ]);

        $response->assertOk();
        $instruction = WorkplaceInstruction::where('workplace_id', $workplace->id)->firstOrFail();
        $this->assertStringStartsWith("workplaces/{$workplace->id}/", $instruction->content);
        Storage::assertExists($instruction->content);
    }

    public function testStoreInstructionRejectsANonUrlContentForLinkType(): void
    {
        $workplace = Workplace::factory()->create();

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/workplaces/{$workplace->id}/instructions", [
                'type' => 'link',
                'title' => 'Guide',
                'content' => 'not-a-url',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['content']]);
    }

    public function testUpdateInstructionReplacesTheOldImageFile(): void
    {
        Storage::fake();
        $workplace = Workplace::factory()->create();
        Storage::put('workplaces/old.jpg', 'old');
        $instruction = WorkplaceInstruction::create([
            'workplace_id' => $workplace->id,
            'type' => 'image',
            'title' => 'Map',
            'content' => 'workplaces/old.jpg',
        ]);

        $response = $this->actingAs($this->admin, 'api')->post(
            "/admin/workplaces/{$workplace->id}/instructions/{$instruction->id}",
            [
                '_method' => 'PUT',
                'type' => 'image',
                'title' => 'New map',
                'file' => UploadedFile::fake()->image('new.jpg'),
            ]
        );

        $response->assertOk();
        $instruction->refresh();
        $this->assertSame('New map', $instruction->title);
        $this->assertNotSame('workplaces/old.jpg', $instruction->content);
        Storage::assertMissing('workplaces/old.jpg');
        Storage::assertExists($instruction->content);
    }

    public function testUpdateInstructionKeepsTheExistingImageWhenNoFileIsUploaded(): void
    {
        Storage::fake();
        $workplace = Workplace::factory()->create();
        Storage::put('workplaces/keep.jpg', 'keep');
        $instruction = WorkplaceInstruction::create([
            'workplace_id' => $workplace->id,
            'type' => 'image',
            'title' => 'Map',
            'content' => 'workplaces/keep.jpg',
        ]);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$workplace->id}/instructions/{$instruction->id}",
            [
                'type' => 'image',
                'title' => 'Renamed map',
            ]
        );

        $response->assertOk();
        $instruction->refresh();
        $this->assertSame('Renamed map', $instruction->title);
        $this->assertSame('workplaces/keep.jpg', $instruction->content);
        Storage::assertExists('workplaces/keep.jpg');
    }

    public function testUpdateInstructionFromImageToLinkDeletesTheStoredImage(): void
    {
        Storage::fake();
        $workplace = Workplace::factory()->create();
        Storage::put('workplaces/old.jpg', 'old');
        $instruction = WorkplaceInstruction::create([
            'workplace_id' => $workplace->id,
            'type' => 'image',
            'title' => 'Map',
            'content' => 'workplaces/old.jpg',
        ]);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$workplace->id}/instructions/{$instruction->id}",
            [
                'type' => 'link',
                'title' => 'Guide',
                'content' => 'https://example.com',
            ]
        );

        $response->assertOk();
        $instruction->refresh();
        $this->assertSame('link', $instruction->type);
        $this->assertSame('https://example.com', $instruction->content);
        Storage::assertMissing('workplaces/old.jpg');
    }

    public function testDeleteInstructionRemovesTheStoredImage(): void
    {
        Storage::fake();
        $workplace = Workplace::factory()->create();
        Storage::put('workplaces/map.jpg', 'map');
        $instruction = WorkplaceInstruction::create([
            'workplace_id' => $workplace->id,
            'type' => 'image',
            'title' => 'Map',
            'content' => 'workplaces/map.jpg',
        ]);

        $response = $this->actingAs($this->admin, 'api')->deleteJson(
            "/admin/workplaces/{$workplace->id}/instructions/{$instruction->id}"
        );

        $response->assertOk();
        Storage::assertMissing('workplaces/map.jpg');
        $this->assertDatabaseMissing('workplace_instructions', ['id' => $instruction->id]);
    }
}
