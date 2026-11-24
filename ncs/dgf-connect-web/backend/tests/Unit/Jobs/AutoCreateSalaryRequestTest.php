<?php

namespace Tests\Unit\Jobs;

use App\Enums\SalaryProcessStatus;
use App\Enums\SalaryRequestStatus;
use App\Enums\SalaryRequestType;
use App\Jobs\AutoCreateSalaryRequest;
use App\Models\Salary;
use App\Models\SalaryProcess;
use App\Models\SalaryRequest;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkTimeReport;
use App\Models\WorkTimeReportDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

/**
 * Runs every minute and works through the queue of salary processes one at a time. For each
 * employee who worked at the process's workplace that month it raises a pre-approved transfer
 * request for as much of the month as is payable, rounded down to a full 10000 yen.
 */
class AutoCreateSalaryRequestTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const MONTH = '2026-04';

    private User $employee;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');
        Log::spy();

        $this->employee = $this->createEmployee();
        $this->workplace = Workplace::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testDoesNothingWhenThereIsNoProcessQueued(): void
    {
        $this->reportWorkAt($this->workplace);
        $this->createFinalizedSalary($this->employee, self::MONTH, 55000);

        (new AutoCreateSalaryRequest())->handle();

        $this->assertDatabaseCount('salary_requests', 0);
    }

    public function testWaitsWhileAnotherProcessIsStillRunning(): void
    {
        $this->reportWorkAt($this->workplace);
        $this->createFinalizedSalary($this->employee, self::MONTH, 55000);
        $this->createProcess($this->workplace, ['status' => SalaryProcessStatus::Processing->value]);
        $pending = $this->createProcess($this->workplace);

        (new AutoCreateSalaryRequest())->handle();

        $this->assertDatabaseCount('salary_requests', 0);
        $this->assertSame(SalaryProcessStatus::Pending->value, $pending->fresh()->status);
    }

    public function testLeavesAProcessScheduledForLaterAlone(): void
    {
        $this->reportWorkAt($this->workplace);
        $this->createFinalizedSalary($this->employee, self::MONTH, 55000);
        $process = $this->createProcess($this->workplace, ['scheduled_at' => Carbon::now()->addDay()]);

        (new AutoCreateSalaryRequest())->handle();

        $this->assertDatabaseCount('salary_requests', 0);
        $this->assertSame(SalaryProcessStatus::Pending->value, $process->fresh()->status);
    }

    public function testRaisesAPreApprovedRequestRoundedDownToAFullTenThousand(): void
    {
        $this->reportWorkAt($this->workplace);
        $salary = $this->createFinalizedSalary($this->employee, self::MONTH, 55000);
        $process = $this->createProcess($this->workplace);

        (new AutoCreateSalaryRequest())->handle();

        $request = SalaryRequest::query()->firstOrFail();
        $this->assertSame($this->employee->id, $request->user_id);
        $this->assertSame(50000, (int) $request->amount);
        $this->assertSame(55000, (int) $request->max_amount);
        $this->assertSame(SalaryRequestType::Transfer->value, $request->type);
        // It skips both approval stages: the process itself is the approval.
        $this->assertSame(SalaryRequestStatus::WaitForPayment->value, $request->status);
        $this->assertNotNull($request->approved_at);
        $this->assertNotNull($request->accountant_approved_at);
        $this->assertNull($request->approver_id);

        $this->assertDatabaseHas('salary_request_details', [
            'salary_request_id' => $request->id,
            'salary_id' => $salary->id,
        ]);
        $this->assertSame(SalaryProcessStatus::Completed->value, $process->fresh()->status);
    }

    /**
     * The detail rows are what ImportTransferInfoAction books against the salary once the bank
     * confirms the payment, so they carry the amount actually being transferred rather than
     * the maximum it was rounded down from.
     *
     * The month is billed the 330 yen transfer fee too, as long as rounding the request down
     * left enough behind to take it from.
     */
    public function testTheDetailRowCarriesTheAmountBeingTransferred(): void
    {
        $this->reportWorkAt($this->workplace);
        $salary = $this->createFinalizedSalary($this->employee, self::MONTH, 55000);
        $this->createProcess($this->workplace);

        (new AutoCreateSalaryRequest())->handle();

        $request = SalaryRequest::query()->firstOrFail();
        $this->assertSame(50000, (int) $request->amount);
        $this->assertDatabaseHas('salary_request_details', [
            'salary_request_id' => $request->id,
            'salary_id' => $salary->id,
            'amount' => 50000,
            'has_fee' => true,
        ]);
    }

    /**
     * An unfinalized month only advances 80% of itself, so a request that uses up all of that
     * still leaves the remaining fifth untouched - easily enough for the fee. Measuring the
     * headroom against the 80% cap instead of the month's real balance would skip the fee here
     * even though 13500 yen sat unspent.
     */
    public function testTheUntouchedFifthOfAnUnfinalizedMonthStillCoversTheFee(): void
    {
        $this->reportWorkAt($this->workplace);
        // Five 8-hour shifts at 1250 yen an hour: collectUsersSalary works the month out to
        // 50000 on its own, and because nothing has settled it only 80% of that - a round
        // 40000 - may be advanced. That leaves nothing over against the 80% cap while 10000 of
        // the month itself sits untouched, which is what the fee has to come out of.
        $this->recordShiftsWorked($this->employee, $this->workplace, self::MONTH, 5, 480, 1250);
        $this->createProcess($this->workplace);

        (new AutoCreateSalaryRequest())->handle();

        $salary = Salary::query()->where('user_id', $this->employee->id)->where('month', self::MONTH)->firstOrFail();
        $this->assertSame(50000, (int) $salary->total_amount);
        $this->assertFalse((bool) $salary->is_finalized);

        $request = SalaryRequest::query()->firstOrFail();
        $this->assertSame(40000, (int) $request->amount);
        $this->assertDatabaseHas('salary_request_details', [
            'salary_request_id' => $request->id,
            'salary_id' => $salary->id,
            'amount' => 40000,
            'has_fee' => true,
        ]);
    }

    /**
     * When the payable amount is already a full 10000 the request drains the month exactly and
     * there is nothing left to take the fee from. Billing it anyway would drive the month's
     * remainder negative and make the bank import reject the entire file, so it is skipped.
     */
    public function testNoFeeIsBilledWhenTheRequestDrainsTheMonthExactly(): void
    {
        $this->reportWorkAt($this->workplace);
        $salary = $this->createFinalizedSalary($this->employee, self::MONTH, 50000);
        $this->createProcess($this->workplace);

        (new AutoCreateSalaryRequest())->handle();

        $request = SalaryRequest::query()->firstOrFail();
        $this->assertSame(50000, (int) $request->amount);
        $this->assertDatabaseHas('salary_request_details', [
            'salary_request_id' => $request->id,
            'salary_id' => $salary->id,
            'amount' => 50000,
            'has_fee' => false,
        ]);
    }

    public function testOnlyEightyPercentOfAnUnsettledMonthIsPayable(): void
    {
        $this->reportWorkAt($this->workplace);
        // Five 8-hour shifts at 1250 yen an hour: SalaryService works the month out to 50000,
        // and because nothing about it is settled only 80% of that may be advanced.
        $this->recordShiftsWorked($this->employee, $this->workplace, self::MONTH, 5, 480, 1250);
        // 4000 of it already went out on an earlier advance. The row is seeded rather than
        // earned through a whole transfer round, but that is the state the bank return file
        // leaves behind - see ImportTransferTest::testAnAutoCreatedRequestIsBookedForExactly-
        // WhatWasTransferred. The check-ins above are what keep the recalculation from
        // touching it, so this does not lean on the "lost all work time" warning branch.
        $this->createUnfinalizedSalary($this->employee, self::MONTH, 0, ['total_paid' => 4000]);
        $this->createProcess($this->workplace);

        (new AutoCreateSalaryRequest())->handle();

        $salary = Salary::query()->where('user_id', $this->employee->id)->where('month', self::MONTH)->firstOrFail();
        $this->assertSame(50000, (int) $salary->total_amount, 'The month is worth what the check-ins say.');
        $this->assertSame(4000, (int) $salary->total_paid, 'What was already advanced is left alone.');

        $request = SalaryRequest::query()->firstOrFail();
        $this->assertSame(36000, (int) $request->max_amount, '80% of the month, less what it already paid out.');
        $this->assertSame(30000, (int) $request->amount, 'The request itself is rounded down to a full 10000.');
    }

    public function testNeverAsksForMoreThanTheConfiguredTransferCeiling(): void
    {
        config(['salary.request.max_transfer_amount' => 30000]);
        $this->reportWorkAt($this->workplace);
        $this->createFinalizedSalary($this->employee, self::MONTH, 55000);
        $this->createProcess($this->workplace);

        (new AutoCreateSalaryRequest())->handle();

        $this->assertSame(30000, (int) SalaryRequest::query()->firstOrFail()->amount);
    }

    public function testSkipsAnEmployeeWhoseMonthIsWorthLessThanTenThousand(): void
    {
        $this->reportWorkAt($this->workplace);
        $this->createFinalizedSalary($this->employee, self::MONTH, 9000);
        $process = $this->createProcess($this->workplace);

        (new AutoCreateSalaryRequest())->handle();

        $this->assertDatabaseCount('salary_requests', 0);
        $this->assertSame(SalaryProcessStatus::Completed->value, $process->fresh()->status);
    }

    public function testSkipsAnEmployeeWhoseMonthIsAlreadySpent(): void
    {
        $this->reportWorkAt($this->workplace);
        $this->createFinalizedSalary($this->employee, self::MONTH, 55000, [
            'total_paid' => 50000,
            'total_deductions' => 5000,
        ]);
        $this->createProcess($this->workplace);

        (new AutoCreateSalaryRequest())->handle();

        $this->assertDatabaseCount('salary_requests', 0);
    }

    public function testSkipsAnEmployeeWhoAlreadyHasAnActiveRequest(): void
    {
        $this->reportWorkAt($this->workplace);
        $this->createFinalizedSalary($this->employee, self::MONTH, 55000);
        // A second unpaid month, so the employee's own screen offers a range wide enough to
        // ask for something that still leaves room for the transfer fee.
        $this->createUnfinalizedSalary($this->employee, '2026-05', 20000);
        // The employee got there first through their own screen.
        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 56000])
            ->assertOk();
        $this->createProcess($this->workplace);

        (new AutoCreateSalaryRequest())->handle();

        $this->assertDatabaseCount('salary_requests', 1);
        $this->assertSame(56000, (int) SalaryRequest::query()->firstOrFail()->amount);
    }

    public function testIgnoresEmployeesWhoDidNotWorkAtThatWorkplace(): void
    {
        $elsewhere = Workplace::factory()->create();
        $this->reportWorkAt($elsewhere);
        $this->createFinalizedSalary($this->employee, self::MONTH, 55000);
        $this->createProcess($this->workplace);

        (new AutoCreateSalaryRequest())->handle();

        $this->assertDatabaseCount('salary_requests', 0);
    }

    public function testIgnoresMonthsOtherThanTheProcessesOwn(): void
    {
        $this->reportWorkAt($this->workplace, '2026-03');
        $this->createFinalizedSalary($this->employee, '2026-03', 55000);
        $this->createProcess($this->workplace);

        (new AutoCreateSalaryRequest())->handle();

        $this->assertDatabaseCount('salary_requests', 0);
    }

    public function testTakesTheHighestPriorityProcessFirstAndLeavesTheRestQueued(): void
    {
        $other = Workplace::factory()->create();
        $this->reportWorkAt($this->workplace);
        $this->createFinalizedSalary($this->employee, self::MONTH, 55000);
        $low = $this->createProcess($this->workplace, ['priority' => 1]);
        $high = $this->createProcess($other, ['priority' => 9]);

        (new AutoCreateSalaryRequest())->handle();

        $this->assertSame(SalaryProcessStatus::Completed->value, $high->fresh()->status);
        $this->assertSame(SalaryProcessStatus::Pending->value, $low->fresh()->status);
        // The employee did not work at the higher-priority workplace, so nothing was raised.
        $this->assertDatabaseCount('salary_requests', 0);
    }

    /**
     * An employee who worked at two queued workplaces belongs to the one that outranks the
     * other. There is no deferring logic for that: the queue is walked in priority order, so
     * the higher-priority workplace simply gets there first, and the later run finds the
     * request already open and leaves them alone.
     */
    public function testTheHigherPriorityWorkplaceClaimsAnEmployeeTheyShare(): void
    {
        $other = Workplace::factory()->create();
        $report = $this->reportWorkAt($this->workplace);
        $this->addWorkplaceToReport($report, $other);
        $this->createFinalizedSalary($this->employee, self::MONTH, 55000);
        $higher = $this->createProcess($other, ['priority' => 9]);
        $lower = $this->createProcess($this->workplace, ['priority' => 1]);

        (new AutoCreateSalaryRequest())->handle();

        // The first run is the higher-priority one, and it is the run that raised the request.
        $this->assertSame(SalaryProcessStatus::Completed->value, $higher->fresh()->status);
        $this->assertSame(SalaryProcessStatus::Pending->value, $lower->fresh()->status);
        $this->assertDatabaseCount('salary_requests', 1);

        (new AutoCreateSalaryRequest())->handle();

        $this->assertSame(SalaryProcessStatus::Completed->value, $lower->fresh()->status);
        $this->assertDatabaseCount('salary_requests', 1);
    }

    /**
     * Priority only orders the queue; it never defers an employee to a workplace whose run is
     * not due yet. A higher-priority process scheduled for later is simply not in this run, so
     * the workplace that is due claims the employee - and the later run finds nothing to do.
     */
    public function testAWorkplaceThatIsDueClaimsTheEmployeeEvenIfAnotherOutranksItLater(): void
    {
        $other = Workplace::factory()->create();
        $report = $this->reportWorkAt($this->workplace);
        $this->addWorkplaceToReport($report, $other);
        $this->createFinalizedSalary($this->employee, self::MONTH, 55000);
        $dueNow = $this->createProcess($this->workplace, ['priority' => 1]);
        $outranksButLater = $this->createProcess($other, [
            'priority' => 9,
            'scheduled_at' => Carbon::now()->addDay(),
        ]);

        (new AutoCreateSalaryRequest())->handle();

        $this->assertSame(SalaryProcessStatus::Completed->value, $dueNow->fresh()->status);
        $this->assertSame(SalaryProcessStatus::Pending->value, $outranksButLater->fresh()->status);
        $this->assertDatabaseCount('salary_requests', 1);
    }

    /**
     * A workplace can have several months queued at once. Whichever of them the run picks, its
     * employees still get their request - nothing in the run treats the workplace's other
     * queued months as a reason to pass them over.
     */
    public function testASecondQueuedMonthDoesNotStopAWorkplaceClaimingItsEmployees(): void
    {
        $other = Workplace::factory()->create();
        $report = $this->reportWorkAt($this->workplace);
        $this->addWorkplaceToReport($report, $other);
        $this->createFinalizedSalary($this->employee, self::MONTH, 55000);

        $running = $this->createProcess($this->workplace, ['priority' => 9]);
        // Same workplace, an earlier month still queued behind it at a lower priority.
        $this->createProcess($this->workplace, ['priority' => 1, 'month' => '2026-03']);
        $this->createProcess($other, ['priority' => 5]);

        (new AutoCreateSalaryRequest())->handle();

        $this->assertSame(SalaryProcessStatus::Completed->value, $running->fresh()->status);
        $this->assertDatabaseCount('salary_requests', 1);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createProcess(Workplace $workplace, array $overrides = []): SalaryProcess
    {
        return SalaryProcess::query()->create(
            $overrides + [
                'month' => self::MONTH,
                'workplace_id' => $workplace->id,
                'status' => SalaryProcessStatus::Pending->value,
                'priority' => 0,
                'scheduled_at' => Carbon::now()->subHour(),
            ]
        );
    }

    private function reportWorkAt(Workplace $workplace, string $month = self::MONTH): WorkTimeReport
    {
        $report = WorkTimeReport::query()->create([
            'user_id' => $this->employee->id,
            'month' => $month,
            'work_days' => 10,
            'work_minutes' => 3000,
            'work_minutes_normal' => 3000,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
        $this->addWorkplaceToReport($report, $workplace);

        return $report;
    }

    private function addWorkplaceToReport(WorkTimeReport $report, Workplace $workplace): void
    {
        WorkTimeReportDetail::query()->create([
            'work_time_report_id' => $report->id,
            'workplace_id' => $workplace->id,
            'work_days' => 5,
            'work_minutes' => 1500,
            'work_minutes_normal' => 1500,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
    }
}
