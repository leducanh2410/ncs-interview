<?php

namespace Tests\Unit\Actions\Common\CheckIn;

use App\Actions\Common\CheckIn\CalculateAttendanceDeviationAction;
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

class CalculateAttendanceDeviationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testOnTimeCheckInAndCheckOutHaveNoDeviation(): void
    {
        $checkIn = $this->createCheckIn('09:00:00', '18:00:00', '2026-06-22 08:50:00', '2026-06-22 18:10:00');

        $this->assertSame(['late_minutes' => 0, 'early_minutes' => 0], $this->calculate($checkIn));
        $this->assertSame(0, $checkIn->late_minutes);
        $this->assertSame(0, $checkIn->early_minutes);
    }

    public function testLateCheckInAndEarlyCheckOutAreFlooredToWholeMinutes(): void
    {
        $checkIn = $this->createCheckIn('09:00:00', '18:00:00', '2026-06-22 09:12:40', '2026-06-22 17:44:30');

        $this->assertSame(['late_minutes' => 12, 'early_minutes' => 15], $this->calculate($checkIn));
    }

    public function testOpenCheckInHasNoEarlyLeave(): void
    {
        $checkIn = $this->createCheckIn('09:00:00', '18:00:00', '2026-06-22 09:05:00', null);

        $this->assertSame(['late_minutes' => 5, 'early_minutes' => 0], $this->calculate($checkIn));
    }

    public function testOvernightShiftUsesNextDayEnd(): void
    {
        $checkIn = $this->createCheckIn('22:00:00', '06:00:00', '2026-06-22 22:10:00', '2026-06-23 05:30:00');

        $this->assertSame(['late_minutes' => 10, 'early_minutes' => 30], $this->calculate($checkIn));
    }

    public function testDeviationWithinThresholdIsIgnored(): void
    {
        config(['work.check_in.late_early_threshold_minute' => 5]);
        $checkIn = $this->createCheckIn('09:00:00', '18:00:00', '2026-06-22 09:05:00', '2026-06-22 17:54:00');

        $this->assertSame(['late_minutes' => 0, 'early_minutes' => 6], $this->calculate($checkIn));
    }

    public function testObserverRecalculatesWhenTimesChange(): void
    {
        $checkIn = $this->createCheckIn('09:00:00', '18:00:00', '2026-06-22 09:20:00', '2026-06-22 18:00:00');
        $this->assertSame(20, $checkIn->late_minutes);

        $checkIn->update(['check_in_time' => '2026-06-22 09:00:00', 'check_out_time' => '2026-06-22 17:00:00']);

        $checkIn->refresh();
        $this->assertSame(0, $checkIn->late_minutes);
        $this->assertSame(60, $checkIn->early_minutes);
    }

    public function testObserverKeepsDeviationWhenUnrelatedFieldChanges(): void
    {
        $checkIn = $this->createCheckIn('09:00:00', '18:00:00', '2026-06-22 09:20:00', '2026-06-22 18:00:00');
        $checkIn->update(['late_reason' => 'Rain']);

        $checkIn->refresh();
        $this->assertSame(20, $checkIn->late_minutes);
        $this->assertSame('Rain', $checkIn->late_reason);
    }

    public function testTemplateTimeChangeRecalculatesRecentCheckInsAndKeepsOlderHistory(): void
    {
        Carbon::setTestNow('2026-06-23 10:00:00');
        $recent = $this->createCheckIn(
            '09:00:00',
            '18:00:00',
            '2026-06-22 09:20:00',
            '2026-06-22 18:00:00',
            '2026-06-22'
        );
        $workplaceShift = $recent->shift->workplaceShift;
        $older = $this->createCheckInForWorkplaceShift(
            $workplaceShift,
            '2026-06-10',
            '2026-06-10 09:20:00',
            '2026-06-10 18:00:00'
        );
        $older->update(['late_reason' => 'Train delay']);

        $workplaceShift->update(['start_time' => '09:30:00']);

        $this->assertSame(0, $recent->refresh()->late_minutes);
        $older->refresh();
        $this->assertSame(20, $older->late_minutes);
        $this->assertSame('Train delay', $older->late_reason);
    }

    public function testTemplateChangeOfUnrelatedFieldDoesNotRecalculate(): void
    {
        Carbon::setTestNow('2026-06-23 10:00:00');
        $checkIn = $this->createCheckIn(
            '09:00:00',
            '18:00:00',
            '2026-06-22 09:20:00',
            '2026-06-22 18:00:00',
            '2026-06-22'
        );
        CheckIn::query()
            ->whereKey($checkIn->id)
            ->update(['late_minutes' => 99]);

        $checkIn->shift->workplaceShift->update(['salary_per_hour' => 1500]);

        $this->assertSame(99, $checkIn->refresh()->late_minutes);
    }

    public function testObserverUsesTheNewShiftWhenShiftIdChanges(): void
    {
        $checkIn = $this->createCheckIn('09:00:00', '18:00:00', '2026-06-22 09:20:00', '2026-06-22 18:00:00');
        $checkIn->load('shift.workplaceShift');
        $laterShift = Shift::create([
            'workplace_id' => $checkIn->shift->workplace_id,
            'workplace_shift_id' => WorkplaceShift::create([
                'workplace_id' => $checkIn->shift->workplace_id,
                'start_time' => '09:15:00',
                'end_time' => '18:00:00',
            ])->id,
            'creator_id' => $checkIn->user_id,
            'date' => '2026-06-22',
            'adjusted_date' => '2026-06-22',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $checkIn->update(['shift_id' => $laterShift->id]);

        $this->assertSame(5, $checkIn->refresh()->late_minutes);
    }

    private function calculate(CheckIn $checkIn): array
    {
        return app(CalculateAttendanceDeviationAction::class)($checkIn);
    }

    private function createCheckIn(
        string $start,
        string $end,
        string $checkInTime,
        ?string $checkOutTime,
        string $date = '2026-06-22'
    ): CheckIn {
        $workplace = Workplace::create([
            'name' => 'WP',
            'address' => 'Addr',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => $start,
            'end_time' => $end,
        ]);

        return $this->createCheckInForWorkplaceShift($workplaceShift, $date, $checkInTime, $checkOutTime);
    }

    private function createCheckInForWorkplaceShift(
        WorkplaceShift $workplaceShift,
        string $date,
        string $checkInTime,
        ?string $checkOutTime
    ): CheckIn {
        $user = User::factory()->create();
        $position = WorkplacePosition::firstOrCreate([
            'workplace_id' => $workplaceShift->workplace_id,
            'name' => 'Staff',
        ]);
        $shift = Shift::create([
            'workplace_id' => $workplaceShift->workplace_id,
            'workplace_shift_id' => $workplaceShift->id,
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
