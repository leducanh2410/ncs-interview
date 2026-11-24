<?php

namespace Tests\Unit\Services\Admin;

use App\Enums\ErrorCode;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleDetail;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use App\Services\Admin\FixedScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class FixedScheduleServiceTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private FixedScheduleService $service;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    private WorkplaceShift $shift;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FixedScheduleService::class);

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
        $this->shift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $this->employee = User::factory()->create();
        $this->employee->workplaces()->attach($this->workplace->id);
    }

    public function testListFixedSchedulesOnlyReturnsManagedWorkplaces(): void
    {
        $this->createSchedule($this->workplace);

        $otherEmployee = User::factory()->create();
        $otherEmployee->workplaces()->attach($this->otherWorkplace->id);
        FixedSchedule::create([
            'user_id' => $otherEmployee->id,
            'workplace_id' => $this->otherWorkplace->id,
        ]);

        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::FixedScheduleList, $this->workplace->id);

        $result = $this->service->listFixedSchedules($manager, []);

        $this->assertSame(1, $result->total());
        $this->assertSame($this->workplace->id, $result->items()[0]->workplace_id);
    }

    public function testListFixedSchedulesReturnsAllForGlobalAdmin(): void
    {
        $this->createSchedule($this->workplace);

        $otherEmployee = User::factory()->create();
        $otherEmployee->workplaces()->attach($this->otherWorkplace->id);
        FixedSchedule::create([
            'user_id' => $otherEmployee->id,
            'workplace_id' => $this->otherWorkplace->id,
        ]);

        $globalAdmin = User::factory()->create();
        $this->giveGlobalPermission($globalAdmin, PermissionName::FixedScheduleList);

        $result = $this->service->listFixedSchedules($globalAdmin, []);

        $this->assertSame(2, $result->total());
    }

    public function testListFixedSchedulesShowsShortenedNameForSriLankaEmployee(): void
    {
        $this->employee->update(['first_name' => 'Ragalage Tharindu Ravishann', 'last_name' => 'Karnasooriya']);
        $this->employee->info->update(['nationality' => Nationality::SriLanka->value]);
        $this->createSchedule($this->workplace);

        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::FixedScheduleList, $this->workplace->id);

        $result = $this->service->listFixedSchedules($manager, []);

        $this->assertSame('THARINDU RAVISHANN', $result->items()[0]->user['name']);
    }

    public function testStoreRejectsWorkplaceOutsideManagedScope(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::FixedScheduleCreate, $this->otherWorkplace->id);

        $result = $this->service->store($manager, [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [['day' => 1, 'workplace_shift_id' => $this->shift->id]],
        ]);

        $this->assertSame(ErrorCode::Forbidden, $result['error']);
        $this->assertDatabaseCount('fixed_schedules', 0);
    }

    public function testStoreSucceedsForManagedWorkplace(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::FixedScheduleCreate, $this->workplace->id);

        $result = $this->service->store($manager, [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
            'schedules' => [['day' => 1, 'workplace_shift_id' => $this->shift->id]],
        ]);

        $this->assertNull($result['error']);
        $this->assertDatabaseHas('fixed_schedules', [
            'user_id' => $this->employee->id,
            'workplace_id' => $this->workplace->id,
        ]);
    }

    public function testUpdateRejectsScheduleOutsideManagedScope(): void
    {
        $schedule = $this->createSchedule($this->workplace);

        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::FixedScheduleEdit, $this->otherWorkplace->id);

        $result = $this->service->update($manager, $schedule, [
            'schedules' => [['day' => 2, 'workplace_shift_id' => $this->shift->id]],
        ]);

        $this->assertSame(ErrorCode::Forbidden, $result['error']);
    }

    public function testDestroyRejectsScheduleOutsideManagedScope(): void
    {
        $schedule = $this->createSchedule($this->workplace);

        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::FixedScheduleDelete, $this->otherWorkplace->id);

        $result = $this->service->destroy($manager, $schedule);

        $this->assertSame(ErrorCode::Forbidden, $result['error']);
        $this->assertDatabaseHas('fixed_schedules', ['id' => $schedule->id]);
    }

    private function createSchedule(Workplace $workplace): FixedSchedule
    {
        $schedule = FixedSchedule::create([
            'user_id' => $this->employee->id,
            'workplace_id' => $workplace->id,
        ]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shift->id,
        ]);

        return $schedule;
    }
}
