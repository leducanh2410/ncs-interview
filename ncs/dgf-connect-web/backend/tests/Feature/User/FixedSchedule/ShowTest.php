<?php

namespace Tests\Feature\User\FixedSchedule;

use App\Enums\UserPaperStatus;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleDetail;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private Workplace $workplace;

    private WorkplaceShift $shift;

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
        $this->shift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
    }

    public function testReturnsOwnFixedScheduleShape(): void
    {
        $schedule = FixedSchedule::create(['user_id' => $this->employee->id, 'workplace_id' => $this->workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shift->id,
        ]);

        $response = $this->actingAs($this->employee, 'api')->getJson(
            "/user/fixed-schedule?workplace_id={$this->workplace->id}"
        );

        $response->assertOk();
        $response->assertJsonPath('data.0.fixed_schedule_id', $schedule->id);
        $response->assertJsonPath('data.0.workplace.id', $this->workplace->id);
        $this->assertCount(7, $response->json('data.0.days'));
    }

    public function testReturnsEmptyListWhenNoSchedule(): void
    {
        $response = $this->actingAs($this->employee, 'api')->getJson(
            "/user/fixed-schedule?workplace_id={$this->workplace->id}"
        );

        $response->assertOk();
        $response->assertJsonPath('data', []);
    }

    public function testRequiresAuthentication(): void
    {
        $response = $this->getJson("/user/fixed-schedule?workplace_id={$this->workplace->id}");

        $response->assertUnauthorized();
    }

    public function testDaysExposeWorkplacePosition(): void
    {
        $position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Picking']);
        $schedule = FixedSchedule::create(['user_id' => $this->employee->id, 'workplace_id' => $this->workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shift->id,
            'workplace_position_id' => $position->id,
        ]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 2,
            'workplace_shift_id' => $this->shift->id,
        ]);

        $response = $this->actingAs($this->employee, 'api')->getJson(
            "/user/fixed-schedule?workplace_id={$this->workplace->id}"
        );

        $response->assertOk();
        $response->assertJsonPath('data.0.days.1.workplace_position.id', $position->id);
        $response->assertJsonPath('data.0.days.1.workplace_position.name', 'Picking');
        $response->assertJsonPath('data.0.days.2.workplace_position', null);
        $response->assertJsonPath('data.0.days.0.workplace_position', null);
    }
}
