<?php

namespace Tests\Unit\Helpers;

use App\Enums\ShiftUserStatus;
use App\Helpers\WorkHelper;
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

class WorkHelperGetWorkHoursTest extends TestCase
{
    use RefreshDatabase;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    private User $user;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

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
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->workplacePosition = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);

        $this->shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->user->id,
            'date' => '2026-03-08',
            'adjusted_date' => '2026-03-08',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
    }

    public function testExcludesAbsentShiftWithoutCheckIn()
    {
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => true,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $this->assertEmpty($result);
    }

    public function testIncludesNonAbsentShiftWithoutCheckIn()
    {
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $this->assertNotEmpty($result);
        $this->assertArrayHasKey($this->user->id, $result);
    }

    public function testExcludesAbsentShiftWithCheckIn()
    {
        $shiftUser = ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => true,
        ]);

        CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'check_in_time' => '2026-03-08 09:00:00',
            'check_out_time' => '2026-03-08 18:00:00',
            'work_minutes' => 480,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $this->assertEmpty($result);
    }

    public function testDefaultIsAbsentIsFalse()
    {
        $shiftUser = ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $this->assertFalse($shiftUser->fresh()->is_absent);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $this->assertNotEmpty($result);
    }

    public function testExcludesPendingStatus()
    {
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Pending->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $this->assertEmpty($result);
    }

    public function testExcludesRejectedStatus()
    {
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Rejected->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $this->assertEmpty($result);
    }

    public function testExcludesShiftOutsideDateRange()
    {
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'));

        $this->assertEmpty($result);
    }

    public function testExcludesUsersNotInList()
    {
        $otherUser = User::factory()->create();

        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours([$otherUser->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $this->assertEmpty($result);
    }

    public function testIgnoreShiftIdExcludesSpecificShift()
    {
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours(
            [$this->user->id],
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            $this->shift->id
        );

        $this->assertEmpty($result);
    }

    public function testUsesWorkMinutesWhenCheckInExists()
    {
        $shiftUser = ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'check_in_time' => '2026-03-08 09:00:00',
            'check_out_time' => '2026-03-08 18:00:00',
            'work_minutes' => 420,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $dateData = $result[$this->user->id]['2026-03-08'];
        $this->assertEquals(7.0, $dateData['total']);
        $this->assertEquals([7.0], $dateData['hours']);
    }

    public function testUsesShiftDurationWhenNoCheckIn()
    {
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $dateData = $result[$this->user->id]['2026-03-08'];
        // 09:00-18:00 = 9 hours, no break configured
        $this->assertEquals(9.0, $dateData['total']);
        $this->assertEquals([9.0], $dateData['hours']);
    }

    public function testUsesShiftDurationWithBreakWhenNoCheckIn()
    {
        $workplaceShiftWithBreak = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'start_break_time' => '12:00:00',
            'end_break_time' => '13:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $shiftWithBreak = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShiftWithBreak->id,
            'creator_id' => $this->user->id,
            'date' => '2026-03-08',
            'adjusted_date' => '2026-03-08',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        ShiftUser::create([
            'shift_id' => $shiftWithBreak->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $dateData = $result[$this->user->id]['2026-03-08'];
        // 09:00-18:00 = 9 hours, 1 hour break = 8 hours
        $this->assertEquals(8.0, $dateData['total']);
    }

    public function testUsesCustomBreakMinutesFromCheckInOverShiftBreak()
    {
        $this->shift->update(['break_minutes' => 60]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        // CheckIn with only check_in_time (no check_out_time) uses shift duration path
        // but with custom_break_minutes from check_in record
        CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'check_in_time' => '2026-03-08 09:00:00',
            'check_out_time' => null,
            'work_minutes' => null,
            'break_minutes' => 30,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $dateData = $result[$this->user->id]['2026-03-08'];
        // 09:00-18:00 = 9 hours, custom_break_minutes=30 from check_in takes priority
        // getShiftWorkDuration: 9 - 30/60 = 8.5
        $this->assertEquals(8.5, $dateData['total']);
    }

    public function testMultipleShiftsSameDateSumsHours()
    {
        $morningShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $afternoonShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '14:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $shift1 = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $morningShift->id,
            'creator_id' => $this->user->id,
            'date' => '2026-03-08',
            'adjusted_date' => '2026-03-08',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shift2 = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $afternoonShift->id,
            'creator_id' => $this->user->id,
            'date' => '2026-03-08',
            'adjusted_date' => '2026-03-08',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        ShiftUser::create([
            'shift_id' => $shift1->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        ShiftUser::create([
            'shift_id' => $shift2->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $dateData = $result[$this->user->id]['2026-03-08'];
        // 4 hours + 4 hours = 8 hours
        $this->assertEquals(8.0, $dateData['total']);
        $this->assertCount(2, $dateData['hours']);
        $this->assertEquals(2, $dateData['shift_count']);
        $this->assertEquals([4.0, 4.0], $dateData['hours']);
    }

    public function testMultipleDatesGroupedCorrectly()
    {
        $shift2 = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->user->id,
            'date' => '2026-03-09',
            'adjusted_date' => '2026-03-09',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        ShiftUser::create([
            'shift_id' => $shift2->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $userData = $result[$this->user->id];
        $this->assertArrayHasKey('2026-03-08', $userData);
        $this->assertArrayHasKey('2026-03-09', $userData);
        $this->assertCount(2, $userData);
    }

    public function testMultipleUsersGroupedByUserId()
    {
        $user2 = User::factory()->create();

        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $user2->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours(
            [$this->user->id, $user2->id],
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31')
        );

        $this->assertArrayHasKey($this->user->id, $result);
        $this->assertArrayHasKey($user2->id, $result);
        $this->assertCount(2, $result);
    }

    public function testResultStructureContainsExpectedKeys()
    {
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $dateData = $result[$this->user->id]['2026-03-08'];
        $this->assertArrayHasKey('total', $dateData);
        $this->assertArrayHasKey('hours', $dateData);
        $this->assertArrayHasKey('ranges', $dateData);
        $this->assertArrayHasKey('shift_count', $dateData);
        $this->assertEquals(1, $dateData['shift_count']);
        $this->assertCount(1, $dateData['ranges']);
        $this->assertArrayHasKey('start', $dateData['ranges'][0]);
        $this->assertArrayHasKey('end', $dateData['ranges'][0]);
    }

    public function testRangesContainCorrectStartAndEndTimes()
    {
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $dateData = $result[$this->user->id]['2026-03-08'];
        $this->assertEquals('2026-03-08 09:00:00', $dateData['ranges'][0]['start']->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-03-08 18:00:00', $dateData['ranges'][0]['end']->format('Y-m-d H:i:s'));
    }

    public function testEmptyUserIdsReturnsEmpty()
    {
        $result = WorkHelper::getWorkHours([], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $this->assertEmpty($result);
    }

    public function testIgnoreShiftIdStillIncludesOtherShifts()
    {
        $otherShift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->user->id,
            'date' => '2026-03-09',
            'adjusted_date' => '2026-03-09',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        ShiftUser::create([
            'shift_id' => $otherShift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours(
            [$this->user->id],
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            $this->shift->id
        );

        $userData = $result[$this->user->id];
        $this->assertArrayNotHasKey('2026-03-08', $userData);
        $this->assertArrayHasKey('2026-03-09', $userData);
    }

    public function testShiftWithBreakMinutesUsedWhenNoCheckIn()
    {
        $this->shift->update(['break_minutes' => 60]);

        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $dateData = $result[$this->user->id]['2026-03-08'];
        // 09:00-18:00 = 9 hours, break_minutes=60 -> 9 - 1 = 8 hours
        $this->assertEquals(8.0, $dateData['total']);
    }

    public function testMixedCheckInAndNoCheckInOnSameDate()
    {
        $morningShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $afternoonShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '14:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $shift1 = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $morningShift->id,
            'creator_id' => $this->user->id,
            'date' => '2026-03-08',
            'adjusted_date' => '2026-03-08',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shift2 = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $afternoonShift->id,
            'creator_id' => $this->user->id,
            'date' => '2026-03-08',
            'adjusted_date' => '2026-03-08',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser1 = ShiftUser::create([
            'shift_id' => $shift1->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        // First shift has check-in with 180 work_minutes (3 hours)
        CheckIn::create([
            'shift_user_id' => $shiftUser1->id,
            'shift_id' => $shift1->id,
            'user_id' => $this->user->id,
            'check_in_time' => '2026-03-08 09:15:00',
            'check_out_time' => '2026-03-08 12:15:00',
            'work_minutes' => 180,
        ]);

        // Second shift has no check-in (uses shift duration = 4 hours)
        ShiftUser::create([
            'shift_id' => $shift2->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);

        $result = WorkHelper::getWorkHours([$this->user->id], Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $dateData = $result[$this->user->id]['2026-03-08'];
        // 3 hours (check-in) + 4 hours (shift duration) = 7 hours
        $this->assertEquals(7.0, $dateData['total']);
        $this->assertCount(2, $dateData['hours']);
        $this->assertEquals(2, $dateData['shift_count']);
    }
}
