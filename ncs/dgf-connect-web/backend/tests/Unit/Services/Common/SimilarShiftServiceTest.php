<?php

namespace Tests\Unit\Services\Common;

use App\Enums\ShiftUserStatus;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Services\Common\SimilarShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SimilarShiftServiceTest extends TestCase
{
    use RefreshDatabase;

    private SimilarShiftService $service;

    private Workplace $workplace;

    private WorkplacePosition $position;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-08 10:00:00');

        $this->service = app(SimilarShiftService::class);
        $this->creator = User::factory()->create();

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
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

    public function testSimilarWorkplaceShiftIdsMatchesShiftsWithinTimeWindow(): void
    {
        $target = $this->createWorkplaceShift('09:00:00', '18:00:00');
        $similar = $this->createWorkplaceShift('09:30:00', '18:30:00'); // start +30, end +30 -> similar
        $this->createWorkplaceShift('11:00:00', '18:00:00'); // start +120 -> not similar
        $this->createWorkplaceShift('09:00:00', '20:00:00'); // end +120 -> not similar

        $ids = $this->service->similarWorkplaceShiftIds([$this->workplace->id], $target);

        sort($ids);
        $expected = [$target->id, $similar->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testSimilarWorkplaceShiftIdsHandlesOvernightShifts(): void
    {
        $target = $this->createWorkplaceShift('22:00:00', '06:00:00'); // crosses midnight
        $similar = $this->createWorkplaceShift('22:30:00', '06:30:00'); // overnight, within window
        $this->createWorkplaceShift('09:00:00', '18:00:00'); // daytime -> not similar

        $ids = $this->service->similarWorkplaceShiftIds([$this->workplace->id], $target);

        sort($ids);
        $expected = [$target->id, $similar->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testSimilarWorkplaceShiftIdsFallsBackToTargetWhenNoneMatch(): void
    {
        $target = $this->createWorkplaceShift('09:00:00', '18:00:00');

        $otherWorkplace = Workplace::create([
            'name' => 'Other',
            'address' => 'Other Address',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        // The only shift in the searched workplace is far from the target's time range.
        WorkplaceShift::create([
            'workplace_id' => $otherWorkplace->id,
            'start_time' => '02:00:00',
            'end_time' => '05:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $ids = $this->service->similarWorkplaceShiftIds([$otherWorkplace->id], $target);

        $this->assertSame([$target->id], $ids);
    }

    public function testGetSimilarShiftWorkedCountCountsOnlyCheckedInSimilarShifts(): void
    {
        $target = $this->createWorkplaceShift('09:00:00', '18:00:00');
        $similar = $this->createWorkplaceShift('09:30:00', '18:30:00');
        $nonSimilar = $this->createWorkplaceShift('11:00:00', '20:00:00');

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Counts: checked in on the exact target shift and on a similar shift, both in window.
        $this->createWorkedShift($target, $userA, '2026-05-01');
        $this->createWorkedShift($similar, $userA, '2026-04-01');

        // Does not count: approved but no check-in.
        $this->createShiftUser($this->createShift($similar, '2026-05-02'), $userA, ShiftUserStatus::Approved->value);

        // Does not count: check-in on a non-similar workplace shift.
        $this->createWorkedShift($nonSimilar, $userA, '2026-05-03');

        // Does not count: check-in older than the 90-day window.
        $this->createWorkedShift($similar, $userA, '2026-01-01');

        $counts = $this->service->getSimilarShiftWorkedCount([$userA->id, $userB->id], $this->workplace->id, [
            $target->id,
            $similar->id,
        ]);

        $this->assertSame(2, $counts[$userA->id]);
        $this->assertSame(0, $counts[$userB->id]);
    }

    public function testGetSimilarShiftWorkedCountReturnsZerosForEmptyInputs(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], $this->service->getSimilarShiftWorkedCount([], $this->workplace->id, [1, 2]));
        $this->assertSame(
            [$user->id => 0],
            $this->service->getSimilarShiftWorkedCount([$user->id], $this->workplace->id, [])
        );
    }

    private function createWorkplaceShift(string $start, string $end): WorkplaceShift
    {
        return WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => $start,
            'end_time' => $end,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
    }

    private function createShift(WorkplaceShift $workplaceShift, string $date): Shift
    {
        return Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->creator->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
    }

    private function createShiftUser(Shift $shift, User $user, string $status): ShiftUser
    {
        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $this->position->id,
            'status' => $status,
        ]);
    }

    private function createWorkedShift(WorkplaceShift $workplaceShift, User $user, string $date): void
    {
        $shift = $this->createShift($workplaceShift, $date);
        $shiftUser = $this->createShiftUser($shift, $user, ShiftUserStatus::Approved->value);

        CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'check_in_time' => $date . ' 09:05:00',
        ]);
    }
}
