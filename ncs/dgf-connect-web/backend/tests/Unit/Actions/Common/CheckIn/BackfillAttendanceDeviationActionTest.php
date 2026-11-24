<?php

namespace Tests\Unit\Actions\Common\CheckIn;

use App\Actions\Common\CheckIn\BackfillAttendanceDeviationAction;
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

class BackfillAttendanceDeviationActionTest extends TestCase
{
    use RefreshDatabase;

    private WorkplaceShift $workplaceShift;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-23 08:00:00');
        $workplace = Workplace::create([
            'name' => 'WP',
            'address' => 'Addr',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testReportedCheckInKeepsMinutesFromItsReportAfterTemplateChange(): void
    {
        $checkIn = $this->createCheckIn('2026-06-10', '2026-06-10 09:20:00', '2026-06-10 17:50:00');
        app()->call([new CreateShiftReport($checkIn->shift_user_id), 'handle']);
        $this->workplaceShift->update(['start_time' => '08:00:00', 'end_time' => '17:00:00']);
        $this->resetDeviation();

        app(BackfillAttendanceDeviationAction::class)();

        $checkIn->refresh();
        $this->assertSame(20, $checkIn->late_minutes);
        $this->assertSame(10, $checkIn->early_minutes);
    }

    public function testUnreportedCheckInIsCalculatedFromCurrentTemplate(): void
    {
        $checkIn = $this->createCheckIn('2026-06-23', '2026-06-23 09:20:00', null);
        $this->resetDeviation();

        app(BackfillAttendanceDeviationAction::class)();

        $this->assertSame(20, $checkIn->refresh()->late_minutes);
    }

    public function testReportedMinutesAreKeptAsFiledRegardlessOfThreshold(): void
    {
        $checkIn = $this->createCheckIn('2026-06-10', '2026-06-10 09:03:00', '2026-06-10 17:50:00');
        app()->call([new CreateShiftReport($checkIn->shift_user_id), 'handle']);
        $this->resetDeviation();
        config(['work.check_in.late_early_threshold_minute' => 5]);

        app(BackfillAttendanceDeviationAction::class)();

        $checkIn->refresh();
        $report = ShiftReport::query()->where('shift_user_id', $checkIn->shift_user_id)->firstOrFail();
        $this->assertSame(3, $checkIn->late_minutes);
        $this->assertSame(10, $checkIn->early_minutes);
        $this->assertEquals($report->late_minutes, $checkIn->late_minutes);
        $this->assertEquals($report->early_minutes, $checkIn->early_minutes);
    }

    private function resetDeviation(): void
    {
        CheckIn::query()->update(['late_minutes' => 0, 'early_minutes' => 0]);
    }

    private function createCheckIn(string $date, string $checkInTime, ?string $checkOutTime): CheckIn
    {
        $user = User::factory()->create();
        $position = WorkplacePosition::firstOrCreate([
            'workplace_id' => $this->workplaceShift->workplace_id,
            'name' => 'Staff',
        ]);
        $shift = Shift::create([
            'workplace_id' => $this->workplaceShift->workplace_id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $user->id,
            'date' => $date,
            'adjusted_date' => $date,
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
