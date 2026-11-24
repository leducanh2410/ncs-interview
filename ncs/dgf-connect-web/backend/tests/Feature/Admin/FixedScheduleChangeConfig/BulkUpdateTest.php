<?php

namespace Tests\Feature\Admin\FixedScheduleChangeConfig;

use App\Enums\PermissionName;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class BulkUpdateTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private Workplace $workplaceA;

    private Workplace $workplaceB;

    private Workplace $unmanagedWorkplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workplaceA = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->workplaceB = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $this->unmanagedWorkplace = Workplace::create([
            'name' => 'Workplace C',
            'address' => 'Addr C',
            'latitude' => 37.0,
            'longitude' => 141.0,
        ]);
    }

    public function testUpdatesAllSelectedWorkplaces(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigEdit,
            $this->workplaceA->id
        );
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigEdit,
            $this->workplaceB->id
        );

        $response = $this->actingAs($manager, 'api')->putJson('/admin/fixed-schedule-change-configs/bulk', [
            'workplace_ids' => [$this->workplaceA->id, $this->workplaceB->id],
            'is_enabled' => true,
            'deadline' => '2026-08-15',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('fixed_schedule_change_configs', 2);
    }

    public function testReturns403WhenAnySelectedWorkplaceUnmanaged(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigEdit,
            $this->workplaceA->id
        );

        $response = $this->actingAs($manager, 'api')->putJson('/admin/fixed-schedule-change-configs/bulk', [
            'workplace_ids' => [$this->workplaceA->id, $this->unmanagedWorkplace->id],
            'is_enabled' => true,
            'deadline' => '2026-08-15',
        ]);

        $response->assertForbidden();
    }

    public function testNothingAppliedWhenRejected(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigEdit,
            $this->workplaceA->id
        );

        $this->actingAs($manager, 'api')->putJson('/admin/fixed-schedule-change-configs/bulk', [
            'workplace_ids' => [$this->workplaceA->id, $this->unmanagedWorkplace->id],
            'is_enabled' => true,
            'deadline' => '2026-08-15',
        ]);

        $this->assertDatabaseCount('fixed_schedule_change_configs', 0);
    }
}
