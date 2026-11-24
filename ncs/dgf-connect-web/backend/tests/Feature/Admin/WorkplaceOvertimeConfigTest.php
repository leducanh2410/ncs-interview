<?php

namespace Tests\Feature\Admin;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkplaceOvertimeConfigTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $permission = Permission::firstOrCreate(
            ['name' => PermissionName::WorkplaceEdit->value],
            ['display_name' => 'Workplace Edit']
        );
        $this->admin->givePermission($permission, null);

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
    }

    public function testUpdateStoresOvertimeAfterHours(): void
    {
        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$this->workplace->id}",
            $this->payload(['overtime_after_hours' => 9])
        );

        $response->assertOk();
        $this->assertDatabaseHas('workplaces', [
            'id' => $this->workplace->id,
            'overtime_after_hours' => 9,
        ]);
    }

    public function testUpdateClearsOvertimeAfterHoursWhenNullIsSent(): void
    {
        $this->workplace->update(['overtime_after_hours' => 9]);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$this->workplace->id}",
            $this->payload(['overtime_after_hours' => null])
        );

        $response->assertOk();
        $this->assertNull($this->workplace->fresh()->overtime_after_hours);
    }

    public function testUpdateRejectsThresholdBelowOneHour(): void
    {
        $this->workplace->update(['overtime_after_hours' => 9]);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$this->workplace->id}",
            $this->payload(['overtime_after_hours' => 0])
        );

        $response->assertStatus(422);
        $this->assertSame(9, $this->workplace->fresh()->overtime_after_hours);
    }

    public function testUpdateRejectsThresholdAboveTwentyFourHours(): void
    {
        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$this->workplace->id}",
            $this->payload(['overtime_after_hours' => 25])
        );

        $response->assertStatus(422);
        $this->assertNull($this->workplace->fresh()->overtime_after_hours);
    }

    public function testUpdateForbiddenWithoutPermission(): void
    {
        /** @var User $other */
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->putJson(
            "/admin/workplaces/{$this->workplace->id}",
            $this->payload(['overtime_after_hours' => 9])
        );

        $response->assertStatus(403);
        $this->assertNull($this->workplace->fresh()->overtime_after_hours);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(
            [
                'name' => 'Test Workplace',
                'address' => 'Test Address',
                'coordinates' => '35.6895, 139.6917',
            ],
            $overrides
        );
    }
}
