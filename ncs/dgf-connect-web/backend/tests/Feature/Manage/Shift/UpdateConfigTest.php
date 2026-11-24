<?php

namespace Tests\Feature\Manage\Shift;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UpdateConfigTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-08 10:00:00');

        $this->manager = User::factory()->create();
        $permission = Permission::create([
            'name' => PermissionName::ShiftEdit->value,
            'display_name' => 'Shift Edit',
        ]);
        $this->manager->givePermission($permission, null);

        $workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->shift = Shift::query()->create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testUpdateConfigSucceeds(): void
    {
        $response = $this->actingAs($this->manager, 'api')->patchJson("/manage/shifts/{$this->shift->id}/config", [
            'fixed_total' => 5,
            'temp_total' => 3,
            'max_registered_temp' => 4,
            'break_minutes' => 45,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('shifts', [
            'id' => $this->shift->id,
            'fixed_total' => 5,
            'temp_total' => 3,
            'max_registered_temp' => 4,
            'break_minutes' => 45,
        ]);
    }

    public function testUpdateConfigTurnsOnOvertimeByDuration(): void
    {
        $response = $this->actingAs($this->manager, 'api')->patchJson("/manage/shifts/{$this->shift->id}/config", [
            'fixed_total' => 5,
            'temp_total' => 3,
            'is_overtime_by_duration' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.is_overtime_by_duration', true);
        $this->assertDatabaseHas('shifts', [
            'id' => $this->shift->id,
            'is_overtime_by_duration' => true,
        ]);
    }

    public function testUpdateConfigTurnsOffOvertimeByDurationWhenFlagIsOmitted(): void
    {
        $this->actingAs($this->manager, 'api')
            ->patchJson("/manage/shifts/{$this->shift->id}/config", [
                'fixed_total' => 5,
                'temp_total' => 3,
                'is_overtime_by_duration' => true,
            ])
            ->assertOk();

        $response = $this->actingAs($this->manager, 'api')->patchJson("/manage/shifts/{$this->shift->id}/config", [
            'fixed_total' => 5,
            'temp_total' => 3,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('shifts', [
            'id' => $this->shift->id,
            'is_overtime_by_duration' => false,
        ]);
    }

    public function testUpdateConfigKeepsOvertimeByDurationWhenPermissionScopedToOtherWorkplace(): void
    {
        $this->actingAs($this->manager, 'api')
            ->patchJson("/manage/shifts/{$this->shift->id}/config", [
                'fixed_total' => 5,
                'temp_total' => 3,
                'is_overtime_by_duration' => true,
            ])
            ->assertOk();

        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);

        /** @var User $user */
        $user = User::factory()->create();
        $permission = Permission::where('name', PermissionName::ShiftEdit->value)->first();
        $user->givePermission($permission, $otherWorkplace->id);

        $response = $this->actingAs($user, 'api')->patchJson("/manage/shifts/{$this->shift->id}/config", [
            'fixed_total' => 5,
            'temp_total' => 3,
            'is_overtime_by_duration' => false,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('shifts', [
            'id' => $this->shift->id,
            'is_overtime_by_duration' => true,
        ]);
    }

    public function testUpdateConfigValidationFails(): void
    {
        $response = $this->actingAs($this->manager, 'api')->patchJson("/manage/shifts/{$this->shift->id}/config", [
            'fixed_total' => -1,
        ]);

        $response->assertStatus(422);
        $errors = $response->json('data') ?? [];
        $this->assertArrayHasKey('fixed_total', $errors);
        $this->assertArrayHasKey('temp_total', $errors);
    }

    public function testUpdateConfigForbiddenWithoutPermission(): void
    {
        /** @var User $other */
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->patchJson("/manage/shifts/{$this->shift->id}/config", [
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);

        $response->assertStatus(403);
    }

    public function testUpdateConfigForbiddenWhenPermissionScopedToOtherWorkplace(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);

        /** @var User $user */
        $user = User::factory()->create();
        $permission = Permission::where('name', PermissionName::ShiftEdit->value)->first();
        $user->givePermission($permission, $otherWorkplace->id);

        $response = $this->actingAs($user, 'api')->patchJson("/manage/shifts/{$this->shift->id}/config", [
            'fixed_total' => 5,
            'temp_total' => 3,
        ]);

        $response->assertStatus(403);
    }
}
