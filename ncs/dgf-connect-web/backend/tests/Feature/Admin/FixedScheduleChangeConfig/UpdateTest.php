<?php

namespace Tests\Feature\Admin\FixedScheduleChangeConfig;

use App\Enums\PermissionName;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->otherWorkplace = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
    }

    public function testRequiresFixedScheduleChangeConfigEditPermission(): void
    {
        $manager = User::factory()->create();

        $response = $this->actingAs($manager, 'api')->putJson(
            "/admin/fixed-schedule-change-configs/{$this->workplace->id}",
            ['is_enabled' => true, 'deadline' => '2026-08-15']
        );

        $response->assertForbidden();
    }

    public function testUpdatesEnabledAndDeadline(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigEdit,
            $this->workplace->id
        );

        $response = $this->actingAs($manager, 'api')->putJson(
            "/admin/fixed-schedule-change-configs/{$this->workplace->id}",
            ['is_enabled' => true, 'deadline' => '2026-08-15']
        );

        $response->assertOk();
        $this->assertDatabaseHas('fixed_schedule_change_configs', [
            'workplace_id' => $this->workplace->id,
            'is_enabled' => true,
            'deadline' => '2026-08-15',
        ]);
    }

    public function testReturns403ForUnmanagedWorkplace(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigEdit,
            $this->otherWorkplace->id
        );

        $response = $this->actingAs($manager, 'api')->putJson(
            "/admin/fixed-schedule-change-configs/{$this->workplace->id}",
            ['is_enabled' => true, 'deadline' => '2026-08-15']
        );

        $response->assertForbidden();
    }

    public function testValidatesDeadlineIsAValidDate(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeConfigEdit,
            $this->workplace->id
        );

        $response = $this->actingAs($manager, 'api')->putJson(
            "/admin/fixed-schedule-change-configs/{$this->workplace->id}",
            ['is_enabled' => true, 'deadline' => 'not-a-date']
        );

        $response->assertUnprocessable();
    }
}
