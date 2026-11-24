<?php

namespace Tests\Unit\Actions\Admin\Group;

use App\Actions\Admin\Group\RecomputeFrequentUrgentWorkerGroupAction;
use App\Enums\GroupKind;
use App\Models\Group;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

class RecomputeFrequentUrgentWorkerGroupActionTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    private RecomputeFrequentUrgentWorkerGroupAction $recompute;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recompute = app(RecomputeFrequentUrgentWorkerGroupAction::class);
        $this->workplace = $this->createWorkplaceAt();
    }

    public function testTwoCheckedInUrgentShiftsInsideTheWindowQualify()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $this->urgentShiftFor($employee, 10, checkedIn: true);
        $this->urgentShiftFor($employee, 20, checkedIn: true);

        $group = ($this->recompute)();

        $this->assertTrue($group->users()->where('users.id', $employee->id)->exists());
    }

    public function testUrgentRegistrationsWithoutCheckInsDoNotQualify()
    {
        $noShow = $this->createEmployeeAt($this->workplace);
        $this->urgentShiftFor($noShow, 10, checkedIn: false);
        $this->urgentShiftFor($noShow, 20, checkedIn: false);

        $group = ($this->recompute)();

        // The group exists to find people worth calling in a hurry. Claiming an urgent shift
        // and then not turning up is evidence against that, not for it.
        $this->assertFalse($group->users()->where('users.id', $noShow->id)->exists());
    }

    public function testASingleQualifyingShiftIsNotEnough()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $this->urgentShiftFor($employee, 10, checkedIn: true);

        $group = ($this->recompute)();

        $this->assertFalse($group->users()->where('users.id', $employee->id)->exists());
    }

    public function testOrdinaryRegistrationsNeverQualifyHoweverMany()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        foreach ([5, 15, 25] as $daysAgo) {
            $shift = $this->createShiftAt($this->workplace, now()->subDays($daysAgo)->format('Y-m-d'));
            $this->checkInFor($this->assignToShift($shift, $employee));
        }

        $group = ($this->recompute)();

        $this->assertFalse($group->users()->where('users.id', $employee->id)->exists());
    }

    public function testMembershipExpiresAsTheWindowSlidesPastOldShifts()
    {
        $employee = $this->createEmployeeAt($this->workplace);

        // Accumulated history from an earlier lifecycle: one shift still inside the window,
        // one that has already aged out. No event fires when the second one expires, which
        // is exactly why this group is recomputed set-based rather than per-user.
        $this->urgentShiftFor($employee, 10, checkedIn: true);
        $this->urgentShiftFor($employee, 120, checkedIn: true);

        $group = ($this->recompute)();

        $this->assertFalse(
            $group->users()->where('users.id', $employee->id)->exists(),
            'Only shifts inside the trailing window may count toward the threshold'
        );
    }

    public function testAQualifyingMemberIsRemovedOnceTheirHistoryAgesOut()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $recent = $this->urgentShiftFor($employee, 10, checkedIn: true);
        $this->urgentShiftFor($employee, 20, checkedIn: true);

        $group = ($this->recompute)();
        $this->assertTrue($group->users()->where('users.id', $employee->id)->exists());

        // Push one of the two qualifying shifts out of the window and recompute.
        $recent->shift()->update(['date' => now()->subDays(200)->format('Y-m-d')]);
        $group = ($this->recompute)();

        $this->assertFalse($group->users()->where('users.id', $employee->id)->exists());
    }

    public function testDisabledEmployeesNeverJoin()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $this->urgentShiftFor($employee, 10, checkedIn: true);
        $this->urgentShiftFor($employee, 20, checkedIn: true);
        $employee->update(['is_disabled' => true]);

        $group = ($this->recompute)();

        $this->assertFalse($group->users()->where('users.id', $employee->id)->exists());
    }

    public function testRunningTwiceIsIdempotent()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        $this->urgentShiftFor($employee, 10, checkedIn: true);
        $this->urgentShiftFor($employee, 20, checkedIn: true);

        ($this->recompute)();
        $group = ($this->recompute)();

        $this->assertSame(1, $group->users()->count());
        $this->assertSame(
            1,
            Group::query()->where('kind', GroupKind::FrequentUrgentWorker->value)->count(),
            'The group is a global singleton and must not be duplicated'
        );
    }

    public function testTheGroupExistsButIsEmptyWhenNobodyQualifies()
    {
        $group = ($this->recompute)();

        $this->assertNotNull($group->id);
        $this->assertSame(GroupKind::FrequentUrgentWorker, $group->kind);
        $this->assertSame(0, $group->users()->count());
    }

    private function urgentShiftFor(User $user, int $daysAgo, bool $checkedIn): ShiftUser
    {
        $shift = $this->createShiftAt($this->workplace, now()->subDays($daysAgo)->format('Y-m-d'));
        $shiftUser = $this->assignToShift($shift, $user);
        $shiftUser->forceFill(['is_urgent_registration' => true])->saveQuietly();

        if ($checkedIn) {
            $this->checkInFor($shiftUser);
        }

        return $shiftUser;
    }
}
