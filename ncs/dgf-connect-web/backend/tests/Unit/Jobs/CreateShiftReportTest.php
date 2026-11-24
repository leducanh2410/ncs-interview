<?php

namespace Tests\Unit\Jobs;

use App\Enums\ShiftUserStatus;
use App\Jobs\CreateShiftReport;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftReport;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CreateShiftReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testReportMatchesStoredDeviationAndRespectsThreshold(): void
    {
        config(['work.check_in.late_early_threshold_minute' => 5]);
        Carbon::setTestNow('2026-06-23 08:00:00');
        $withinThreshold = $this->createCheckIn('2026-06-22 09:03:00', '2026-06-22 17:50:00');

        app()->call([new CreateShiftReport($withinThreshold->shift_user_id), 'handle']);

        $report = ShiftReport::query()->where('shift_user_id', $withinThreshold->shift_user_id)->firstOrFail();
        $this->assertNull($report->late_minutes);
        $this->assertEquals(10, $report->early_minutes);
        $this->assertEquals($withinThreshold->early_minutes, $report->early_minutes);
    }

    public function testRerunClearsDeviationThatNoLongerApplies(): void
    {
        Carbon::setTestNow('2026-06-23 08:00:00');
        $checkIn = $this->createCheckIn('2026-06-22 09:20:00', '2026-06-22 18:00:00');
        app()->call([new CreateShiftReport($checkIn->shift_user_id), 'handle']);
        $this->assertEquals(
            20,
            ShiftReport::query()->where('shift_user_id', $checkIn->shift_user_id)->value('late_minutes')
        );

        $checkIn->update(['check_in_time' => '2026-06-22 09:00:00']);
        app()->call([new CreateShiftReport($checkIn->shift_user_id), 'handle']);

        $this->assertNull(ShiftReport::query()->where('shift_user_id', $checkIn->shift_user_id)->value('late_minutes'));
    }

    private function createCheckIn(string $checkInTime, string $checkOutTime): CheckIn
    {
        $user = User::factory()->create();
        $workplace = Workplace::create([
            'name' => 'WP',
            'address' => 'Addr',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);
        $position = WorkplacePosition::create(['workplace_id' => $workplace->id, 'name' => 'Staff']);
        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => '2026-06-22',
            'adjusted_date' => '2026-06-22',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        return CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'check_in_time' => $checkInTime,
            'check_out_time' => $checkOutTime,
        ])->refresh();
    }
}
