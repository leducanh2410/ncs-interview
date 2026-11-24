<?php

namespace Tests\Feature\Console;

use App\Actions\Common\Deployment\AnalyzeDeploymentWindowAction;
use App\Enums\ShiftUserStatus;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CheckDeploymentWindowCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private Workplace $workplace;

    private WorkplacePosition $position;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creator = User::factory()->create();
        $this->workplace = Workplace::create([
            'name' => 'Test Site',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $this->position = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testGoodVerdictWhenQuietAndSuggestsWindows(): void
    {
        Carbon::setTestNow('2026-06-22 10:00:00');

        // A daytime shift that started an hour ago and ends far away (18:00).
        $this->makeShift('2026-06-22', '09:00:00', '18:00:00', approvedUsers: 3);

        $this->artisan('deploy:check-window')
            ->expectsOutputToContain('GOOD TO DEPLOY')
            ->expectsOutputToContain('On shift now: 3 worker(s) across 1 shift(s)')
            ->assertExitCode(0);
    }

    public function testAvoidVerdictWhenBoundaryImminentAndRecentActivity(): void
    {
        Carbon::setTestNow('2026-06-22 08:55:00');

        // A shift that starts in 5 minutes -> imminent check-in wave.
        $shift = $this->makeShift('2026-06-22', '09:00:00', '18:00:00', approvedUsers: 4);

        // Recent check-in activity within the lookback window.
        $this->makeCheckIn($shift, Carbon::parse('2026-06-22 08:53:00'));

        $this->artisan('deploy:check-window')->expectsOutputToContain('AVOID')->assertExitCode(1);
    }

    public function testActionReportsNearestBoundariesAndRecentActivity(): void
    {
        Carbon::setTestNow('2026-06-22 10:00:00');

        $shift = $this->makeShift('2026-06-22', '09:00:00', '18:00:00', approvedUsers: 2);
        $this->makeCheckIn($shift, Carbon::parse('2026-06-22 09:58:00'));

        $result = app(AnalyzeDeploymentWindowAction::class)(Carbon::parse('2026-06-22 10:00:00'));

        $this->assertSame(1, $result['recent_activity']['check_ins']);
        $this->assertSame(0, $result['recent_activity']['check_outs']);
        // 2 seeded approved users + the 1 approved user attached to the check-in.
        $this->assertSame(3, $result['active_now']['workers']);

        // Last start was 09:00 (60 min ago); next end is 18:00 (480 min away).
        $this->assertSame(60, $result['nearest']['last_start']['minutes_away']);
        $this->assertSame('09:00', $result['nearest']['last_start']['time']->format('H:i'));
        $this->assertSame(480, $result['nearest']['next_end']['minutes_away']);
        $this->assertNull($result['nearest']['next_start']);

        $this->assertNotEmpty($result['best_windows']['windows']);
    }

    public function testEscalatesHorizonUntilAWindowIsFound(): void
    {
        Carbon::setTestNow('2026-06-22 08:55:00');

        // Pack the first hour with shift starts every 10 minutes so no gap is wide
        // enough (>= margin) within the 1h tier. All shifts end far later (18:00), so
        // the next genuinely quiet stretch only appears once the search widens to 2h.
        foreach (['09:05:00', '09:15:00', '09:25:00', '09:35:00', '09:45:00'] as $start) {
            $this->makeShift('2026-06-22', $start, '18:00:00', approvedUsers: 1);
        }

        $result = app(AnalyzeDeploymentWindowAction::class)(
            Carbon::parse('2026-06-22 08:55:00'),
            horizonSteps: [1, 2, 4, 12]
        );

        $this->assertSame(2, $result['best_windows']['horizon_used_hours']);
        $this->assertSame([1, 2], $result['best_windows']['horizons_tried']);
        $this->assertNotEmpty($result['best_windows']['windows']);
    }

    public function testJsonOutputIsValid(): void
    {
        Carbon::setTestNow('2026-06-22 10:00:00');
        $this->makeShift('2026-06-22', '09:00:00', '18:00:00', approvedUsers: 1);

        $this->artisan('deploy:check-window', ['--json' => true])
            ->expectsOutputToContain('"verdict"')
            ->assertExitCode(0);
    }

    private function makeShift(string $date, string $start, string $end, int $approvedUsers): Shift
    {
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => $start,
            'end_time' => $end,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->creator->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => $approvedUsers,
        ]);

        for ($i = 0; $i < $approvedUsers; $i++) {
            ShiftUser::create([
                'shift_id' => $shift->id,
                'user_id' => User::factory()->create()->id,
                'workplace_position_id' => $this->position->id,
                'status' => ShiftUserStatus::Approved->value,
                'is_fixed' => true,
            ]);
        }

        return $shift;
    }

    private function makeCheckIn(Shift $shift, Carbon $checkInTime): CheckIn
    {
        $user = User::factory()->create();
        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_fixed' => true,
        ]);

        return CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'check_in_time' => $checkInTime,
        ]);
    }
}
