<?php

namespace Tests\Feature\User\FixedSchedule;

use App\Enums\UserPaperStatus;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleChangeConfig;
use App\Models\FixedScheduleChangeRequest;
use App\Models\FixedScheduleDetail;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private Workplace $workplace;

    private WorkplaceShift $shiftA;

    private WorkplaceShift $shiftB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->shiftA = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $this->shiftB = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '13:00:00',
            'end_time' => '21:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
    }

    private function createSchedule(): FixedSchedule
    {
        $schedule = FixedSchedule::create(['user_id' => $this->employee->id, 'workplace_id' => $this->workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shiftA->id,
        ]);

        return $schedule;
    }

    private function openWindow(): void
    {
        $this->travelTo('2026-07-16');
        FixedScheduleChangeConfig::create([
            'workplace_id' => $this->workplace->id,
            'is_enabled' => true,
            'deadline' => '2026-07-20',
        ]);
    }

    public function testCreatesRequestHappyPath(): void
    {
        $this->openWindow();
        $schedule = $this->createSchedule();

        $response = $this->actingAs($this->employee, 'api')->postJson('/user/fixed-schedule/change-requests', [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $this->shiftB->id]],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('fixed_schedule_change_requests', [
            'fixed_schedule_id' => $schedule->id,
            'status' => 'pending',
        ]);
    }

    public function test422WhenWindowClosed(): void
    {
        $schedule = $this->createSchedule();

        $response = $this->actingAs($this->employee, 'api')->postJson('/user/fixed-schedule/change-requests', [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $this->shiftB->id]],
        ]);

        $response->assertUnprocessable();
    }

    public function test422WhenPendingRequestExists(): void
    {
        $this->openWindow();
        $schedule = $this->createSchedule();
        FixedScheduleChangeRequest::create([
            'fixed_schedule_id' => $schedule->id,
            'workplace_id' => $this->workplace->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->employee, 'api')->postJson('/user/fixed-schedule/change-requests', [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $this->shiftB->id]],
        ]);

        $response->assertUnprocessable();
    }

    public function test422WhenNoDayChanged(): void
    {
        $this->openWindow();
        $schedule = $this->createSchedule();

        $response = $this->actingAs($this->employee, 'api')->postJson('/user/fixed-schedule/change-requests', [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $this->shiftA->id]],
        ]);

        $response->assertUnprocessable();
    }

    public function test422WhenShiftFromOtherWorkplace(): void
    {
        $this->openWindow();
        $schedule = $this->createSchedule();
        $otherWorkplace = Workplace::create([
            'name' => 'Other',
            'address' => 'Addr',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $foreignShift = WorkplaceShift::create([
            'workplace_id' => $otherWorkplace->id,
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $response = $this->actingAs($this->employee, 'api')->postJson('/user/fixed-schedule/change-requests', [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $foreignShift->id]],
        ]);

        $response->assertUnprocessable();
    }

    public function test403WhenScheduleNotOwnedByUser(): void
    {
        $this->openWindow();
        $schedule = $this->createSchedule();
        $otherUser = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        $response = $this->actingAs($otherUser, 'api')->postJson('/user/fixed-schedule/change-requests', [
            'fixed_schedule_id' => $schedule->id,
            'days' => [['day' => 1, 'requested_workplace_shift_id' => $this->shiftB->id]],
        ]);

        $response->assertForbidden();
    }
}
