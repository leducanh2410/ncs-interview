<?php

namespace Tests\Unit\Actions\Manage\Notification;

use App\Actions\Manage\Notification\FilterShiftEligibleUsersAction;
use App\Enums\UserPaperStatus;
use App\Models\Shift;
use App\Models\SystemConfig;
use App\Models\UserConfig;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

/**
 * The notification must not reach people the registration gate would turn away, so this
 * filter reuses that gate rather than reimplementing it.
 */
class FilterShiftEligibleUsersActionTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    private FilterShiftEligibleUsersAction $filter;

    private Workplace $workplace;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = app(FilterShiftEligibleUsersAction::class);
        $this->workplace = $this->createWorkplaceAt();
        $this->shift = $this->createShiftAt($this->workplace, now()->addDay()->format('Y-m-d'));
    }

    public function testAnUnencumberedEmployeeIsEligible()
    {
        $employee = $this->createEmployeeAt($this->workplace);

        $this->assertSame([$employee->id], ($this->filter)($this->shift, [$employee->id]));
    }

    public function testAnEmployeeWithAnOverlappingShiftIsExcluded()
    {
        $employee = $this->createEmployeeAt($this->workplace);

        // Starts inside the target shift's 09:00-18:00 window. Deliberately not an exact
        // duplicate: WorkHelper::isOverlap compares with exclusive bounds, so two identical
        // ranges are not treated as overlapping — a pre-existing quirk this filter inherits
        // rather than introduces.
        $clashing = $this->createShiftAt($this->workplace, $this->shift->date, '12:00:00', '20:00:00');
        $this->assignToShift($clashing, $employee);

        $this->assertSame([], ($this->filter)($this->shift, [$employee->id]));
    }

    public function testTheWeeklyHourLimitExcludesAnEmployeeWhoWouldExceedIt()
    {
        $employee = $this->createEmployeeAt($this->workplace);

        // Four nine-hour days already booked in the same week: 36 hours, past the 28 default
        // before the target shift is even added.
        for ($i = 1; $i <= 4; $i++) {
            $date = now()->addDay()->subDays($i)->format('Y-m-d');
            $this->assignToShift($this->createShiftAt($this->workplace, $date), $employee);
        }

        $this->assertSame([], ($this->filter)($this->shift, [$employee->id]));
    }

    public function testEachEmployeeIsJudgedAgainstTheirOwnWeeklyLimitNotAFixedTwentyEight()
    {
        $standard = $this->createEmployeeAt($this->workplace);
        $extended = $this->createEmployeeAt($this->workplace);

        // A generous per-user limit must let this employee through the very same schedule
        // that excludes the other. A hardcoded 28 anywhere in the filter fails here.
        UserConfig::query()->create([
            'user_id' => $extended->id,
            'max_hour_per_week' => 60,
            'max_shift_per_day' => 2,
        ]);

        foreach ([$standard, $extended] as $employee) {
            for ($i = 1; $i <= 4; $i++) {
                $date = now()->addDay()->subDays($i)->format('Y-m-d');
                $this->assignToShift($this->createShiftAt($this->workplace, $date), $employee);
            }
        }

        $eligible = ($this->filter)($this->shift, [$standard->id, $extended->id]);

        $this->assertNotContains($standard->id, $eligible);
        $this->assertContains($extended->id, $eligible);
    }

    public function testASystemWideLimitAppliesWhenTheEmployeeHasNoOwnConfig()
    {
        $employee = $this->createEmployeeAt($this->workplace);
        SystemConfig::query()->create([
            'max_hour_per_week' => 60,
            'max_shift_per_day' => 2,
            'salary_per_hour' => 1000,
        ]);

        for ($i = 1; $i <= 4; $i++) {
            $date = now()->addDay()->subDays($i)->format('Y-m-d');
            $this->assignToShift($this->createShiftAt($this->workplace, $date), $employee);
        }

        $this->assertContains($employee->id, ($this->filter)($this->shift, [$employee->id]));
    }

    public function testBatchEvaluationAgreesWithEvaluatingEachCandidateAlone()
    {
        $free = $this->createEmployeeAt($this->workplace);
        $busy = $this->createEmployeeAt($this->workplace);
        $this->assignToShift($this->createShiftAt($this->workplace, $this->shift->date), $busy);

        $batch = ($this->filter)($this->shift, [$free->id, $busy->id]);
        $individually = array_merge(
            ($this->filter)($this->shift, [$free->id]),
            ($this->filter)($this->shift, [$busy->id])
        );

        sort($batch);
        sort($individually);
        $this->assertSame($individually, $batch);
    }

    public function testAnEmptyCandidateListShortCircuits()
    {
        $this->assertSame([], ($this->filter)($this->shift, []));
    }

    /**
     * Every paper_status other than Approved, because CheckUserPaperStatus admits only that
     * one and the filter has to agree with it exactly — "not rejected" is not the same rule.
     */
    #[DataProvider('nonApprovedPaperStatusProvider')]
    public function testOnlyEmployeesWithApprovedPapersAreEligible(string $paperStatus)
    {
        $employee = $this->createEmployeeAt($this->workplace, null, $paperStatus);

        $this->assertSame([], ($this->filter)($this->shift, [$employee->id]));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function nonApprovedPaperStatusProvider(): array
    {
        return [
            'registered' => [UserPaperStatus::Registered->value],
            'pending' => [UserPaperStatus::Pending->value],
            'wait_for_approval' => [UserPaperStatus::WaitForApproval->value],
            'rejected' => [UserPaperStatus::Rejected->value],
        ];
    }

    public function testAReturningEmployeeWhoReuploadedDocumentsIsDroppedFromAnEligiblePool()
    {
        // The realistic shape of the gap: an established employee, workplace and staff_no
        // intact, whose papers went back to wait_for_approval after a re-upload.
        $active = $this->createEmployeeAt($this->workplace);
        $reuploading = $this->createEmployeeAt($this->workplace);
        $reuploading->forceFill(['paper_status' => UserPaperStatus::WaitForApproval->value])->save();

        $eligible = ($this->filter)($this->shift, [$active->id, $reuploading->id]);

        $this->assertContains($active->id, $eligible);
        $this->assertNotContains($reuploading->id, $eligible);
    }
}
