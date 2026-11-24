<?php

namespace Tests\Feature\Manage\Shift;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ListShiftsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $stranger;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 08:00:00');

        $shiftList = Permission::create([
            'name' => PermissionName::ShiftList->value,
            'display_name' => 'Shift List',
        ]);

        $role = Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
        $role->permissions()->syncWithoutDetaching([$shiftList->id]);

        $this->admin = User::factory()->create();
        $this->admin->addRole($role);

        $this->stranger = User::factory()->create();

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testIndexReturnsShiftsForDate(): void
    {
        $shift = $this->createShift('2026-05-10');

        $response = $this->actingAs($this->admin, 'api')->getJson('/manage/shifts?date=2026-05-10');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $shift->id);
    }

    public function testIndexForbiddenWithoutPermission(): void
    {
        $response = $this->actingAs($this->stranger, 'api')->getJson('/manage/shifts?date=2026-05-10');

        $response->assertStatus(403);
    }

    public function testIndexSimpleReturnsShifts(): void
    {
        $shift = $this->createShift('2026-05-10');

        $response = $this->actingAs($this->admin, 'api')->getJson('/manage/shifts/simple?date=2026-05-10');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($shift->id));
    }

    public function testGetActiveShiftsReturnsOngoingShift(): void
    {
        $active = $this->createShift('2026-05-10');

        $response = $this->actingAs($this->admin, 'api')->getJson('/manage/shifts/active');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($active->id));
    }

    public function testGetActiveShiftsExcludesEndedShift(): void
    {
        // A shift that already ended (yesterday) must not appear in the active list.
        $ended = $this->createShift('2026-05-09');

        $response = $this->actingAs($this->admin, 'api')->getJson('/manage/shifts/active');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($ended->id));
    }

    private function createShift(string $date): Shift
    {
        return Shift::query()->create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->admin->id,
            'date' => $date,
            'adjusted_date' => $date,
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
    }
}
