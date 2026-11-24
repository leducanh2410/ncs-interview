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

class CreateShiftTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplaceShift $inactiveShift;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-08 10:00:00');

        $this->manager = User::factory()->create();
        $permission = Permission::create([
            'name' => PermissionName::ShiftCreate->value,
            'display_name' => 'Shift Create',
        ]);
        $this->manager->givePermission($permission, null);

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->inactiveShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '13:00:00',
            'end_time' => '22:00:00',
            'is_active' => false,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testCreateShiftSucceeds(): void
    {
        $response = $this->actingAs($this->manager, 'api')->postJson('/manage/shifts', $this->payload());

        $response->assertOk();
        $this->assertDatabaseHas('shifts', [
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'date' => '2026-05-10',
            'fixed_total' => 2,
            'temp_total' => 1,
            'break_minutes' => 60,
            // Counting overtime by shift duration is an opt-in decision per shift.
            'is_overtime_by_duration' => false,
        ]);
    }

    public function testCreateDuplicateShiftReturnsBadRequest(): void
    {
        Shift::query()->create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);

        $response = $this->actingAs($this->manager, 'api')->postJson('/manage/shifts', $this->payload());

        $response->assertStatus(400);
        $this->assertDatabaseCount('shifts', 1);
    }

    public function testCreateInactiveWorkplaceShiftReturns400(): void
    {
        $response = $this->actingAs($this->manager, 'api')->postJson(
            '/manage/shifts',
            $this->payload(['workplace_shift_id' => $this->inactiveShift->id])
        );

        $response->assertStatus(400);
        $this->assertDatabaseCount('shifts', 0);
    }

    public function testCreateValidationFailsWithoutRequiredFields(): void
    {
        $response = $this->actingAs($this->manager, 'api')->postJson('/manage/shifts', []);

        $response->assertStatus(422);
        $errors = $response->json('data') ?? [];
        $this->assertArrayHasKey('date', $errors);
        $this->assertArrayHasKey('workplace_shift_id', $errors);
        $this->assertArrayHasKey('fixed_total', $errors);
        $this->assertArrayHasKey('temp_total', $errors);
    }

    public function testCreatePastDateFailsValidation(): void
    {
        $response = $this->actingAs($this->manager, 'api')->postJson(
            '/manage/shifts',
            $this->payload(['date' => '2026-05-01'])
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey('date', $response->json('data') ?? []);
    }

    public function testCreateForbiddenWithoutPermission(): void
    {
        /** @var User $other */
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->postJson('/manage/shifts', $this->payload());

        $response->assertStatus(403);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(
            [
                'date' => '2026-05-10',
                'workplace_shift_id' => $this->workplaceShift->id,
                'fixed_total' => 2,
                'temp_total' => 1,
                'max_registered_temp' => 3,
                'break_minutes' => 60,
            ],
            $overrides
        );
    }
}
