<?php

namespace Tests\Unit\Services\Common;

use App\Enums\AbsentNoticeStatus;
use App\Enums\ShiftUserStatus;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftAbsentNotice;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use App\Services\Common\AbsenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AbsenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private AbsenceService $service;

    private User $creator;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-08 10:00:00');
        config(['absence.window_days' => 180]);

        $this->service = new AbsenceService();
        $this->creator = User::factory()->create();

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testReturnsZeroForUserWithNoShifts()
    {
        $user = User::factory()->create();

        $this->assertSame(0, $this->service->getUserAbsenceCount($user->id));
    }

    public function testCountsApprovedPastShiftWithNoCheckInAndNoNotice()
    {
        $user = User::factory()->create();
        $this->createShiftUser($user, '2026-05-05');

        $this->assertSame(1, $this->service->getUserAbsenceCount($user->id));
    }

    public function testExcludesShiftWithCheckIn()
    {
        $user = User::factory()->create();
        $shiftUser = $this->createShiftUser($user, '2026-05-05');
        $this->createCheckIn($shiftUser);

        $this->assertSame(0, $this->service->getUserAbsenceCount($user->id));
    }

    public function testExcludesShiftWithAbsentNotice()
    {
        $user = User::factory()->create();
        $shiftUser = $this->createShiftUser($user, '2026-05-05');
        ShiftAbsentNotice::create(['shift_user_id' => $shiftUser->id]);

        $this->assertSame(0, $this->service->getUserAbsenceCount($user->id));
    }

    public function testStillExcludesShiftWithProcessedAbsentNotice()
    {
        $user = User::factory()->create();
        $shiftUser = $this->createShiftUser($user, '2026-05-05');
        ShiftAbsentNotice::create([
            'shift_user_id' => $shiftUser->id,
            'status' => AbsentNoticeStatus::Processed->value,
        ]);

        $this->assertSame(0, $this->service->getUserAbsenceCount($user->id));
    }

    public function testCountsShiftWhoseAbsentNoticeWasRefused()
    {
        // The admin answered "you still have to work" and left the employee on the shift. Not
        // turning up after that is an ordinary absence, so the request must stop excusing it.
        $user = User::factory()->create();
        $shiftUser = $this->createShiftUser($user, '2026-05-05');
        ShiftAbsentNotice::create([
            'shift_user_id' => $shiftUser->id,
            'status' => AbsentNoticeStatus::Rejected->value,
        ]);

        $this->assertSame(1, $this->service->getUserAbsenceCount($user->id));
    }

    public function testWorkplaceMemberCountAlsoCountsARefusedAbsentNotice()
    {
        $user = User::factory()->create();
        WorkplaceUser::create(['workplace_id' => $this->workplace->id, 'user_id' => $user->id]);
        $excused = $this->createShiftUser($user, '2026-05-06');
        $refused = $this->createShiftUser($user, '2026-05-05');
        ShiftAbsentNotice::create(['shift_user_id' => $excused->id]);
        ShiftAbsentNotice::create([
            'shift_user_id' => $refused->id,
            'status' => AbsentNoticeStatus::Rejected->value,
        ]);

        $this->assertSame([$user->id => 1], $this->service->getWorkplaceMemberAbsenceCount($this->workplace->id));
    }

    public function testExcludesPendingAndRejectedShifts()
    {
        $user = User::factory()->create();
        $this->createShiftUser($user, '2026-05-05', ShiftUserStatus::Pending->value);
        $this->createShiftUser($user, '2026-05-04', ShiftUserStatus::Rejected->value);

        $this->assertSame(0, $this->service->getUserAbsenceCount($user->id));
    }

    public function testExcludesShiftInsideTwoDayGracePeriod()
    {
        $user = User::factory()->create();
        $this->createShiftUser($user, '2026-05-07');

        $this->assertSame(0, $this->service->getUserAbsenceCount($user->id));
    }

    public function testExcludesShiftOlderThanWindow()
    {
        $user = User::factory()->create();
        $this->createShiftUser($user, '2025-11-08');

        $this->assertSame(0, $this->service->getUserAbsenceCount($user->id));
    }

    public function testIncludesShiftAtBoundaryDates()
    {
        $user = User::factory()->create();
        $this->createShiftUser($user, '2026-05-06');
        $this->createShiftUser($user, '2025-11-09');

        $this->assertSame(2, $this->service->getUserAbsenceCount($user->id));
    }

    public function testHonorsCustomWindowConfigOverride()
    {
        config(['absence.window_days' => 30]);

        $user = User::factory()->create();
        $this->createShiftUser($user, '2026-04-08');
        $this->createShiftUser($user, '2026-04-07');

        $this->assertSame(1, $this->service->getUserAbsenceCount($user->id));
    }

    public function testFallsBackToDefaultWhenConfigIsInvalid()
    {
        $user = User::factory()->create();
        $this->createShiftUser($user, '2025-11-09');

        foreach ([null, 0, -1] as $invalidWindowDays) {
            config(['absence.window_days' => $invalidWindowDays]);

            $this->assertSame(1, $this->service->getUserAbsenceCount($user->id));
        }
    }

    public function testBatchedCallReturnsZeroFilledArrayForEveryRequestedId()
    {
        $userWithAbsence = User::factory()->create();
        $userWithoutAbsence = User::factory()->create();
        $this->createShiftUser($userWithAbsence, '2026-05-05');

        $this->assertSame(
            [
                $userWithAbsence->id => 1,
                $userWithoutAbsence->id => 0,
            ],
            $this->service->getUserAbsenceCount([$userWithAbsence->id, $userWithoutAbsence->id])
        );
    }

    public function testBatchedCallExecutesSingleQuery()
    {
        $users = User::factory()->count(3)->create();
        foreach ($users as $index => $user) {
            $this->createShiftUser(
                $user,
                Carbon::today()
                    ->subDays($index + 2)
                    ->toDateString()
            );
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->service->getUserAbsenceCount($users->pluck('id')->all());

        $this->assertCount(1, DB::getQueryLog());

        DB::disableQueryLog();
    }

    private function createShiftUser(
        User $user,
        string $date,
        string $status = ShiftUserStatus::Approved->value
    ): ShiftUser {
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->creator->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => $status,
        ]);
    }

    private function createCheckIn(ShiftUser $shiftUser): CheckIn
    {
        return CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $shiftUser->shift_id,
            'user_id' => $shiftUser->user_id,
            'check_in_time' => $shiftUser->shift->date . ' 09:00:00',
            'check_out_time' => $shiftUser->shift->date . ' 18:00:00',
        ]);
    }
}
