<?php

namespace Tests\Feature\Manage\Salary;

use App\Actions\Common\Salary\CollectUserSalaryAction;
use App\Models\Salary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CollectUserSalaryAction runs whenever an employee opens the salary screen, and it
 * recalculates from config('salary.start_date') up to now - so the reset branch of
 * SalaryService sweeps every fully elapsed month, not just the one that changed.
 *
 * That is only safe while no flow writes a salary amount by hand without marking it settled.
 * These tests pin that contract: if a future flow starts creating unlocked salary rows that
 * are not derived from check-ins, the money would be silently zeroed here and the matching
 * case below fails.
 */
class SalaryResetScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');
        $this->employee = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function salaryRowProvider(): array
    {
        return [
            // How ImportRemainingSalaryAction and ImportSalaryDeductionAction leave a row.
            'finalized by import' => [['is_finalized' => true], true],
            'fully paid' => [['is_fully_paid' => true], true],
            // ImportTransferInfoAction on a partial advance leaves both flags false, so the
            // amount survives on the committed-money check instead.
            'partially paid, no flag' => [['total_paid' => 3000], true],
            'deductions only, no flag' => [['total_deductions' => 500], true],
            'transfer fee only, no flag' => [['total_fee' => 200], true],
            // Nothing committed and no check-ins left: this is what the feature must clear.
            'unlocked and untouched' => [[], false],
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('salaryRowProvider')]
    public function testWideRangeRecalculationOnlyClearsUncommittedMonths(array $attributes, bool $shouldSurvive): void
    {
        $salary = Salary::query()->create(
            [
                'user_id' => $this->employee->id,
                // A fully elapsed month, well inside the sweep window.
                'month' => '2026-03',
                'total_amount' => 50000,
                'total_remaining' => 50000,
                'min_salary' => 1000,
                'max_salary' => 1000,
                'total_minutes' => 3000,
                'is_completed' => true,
            ] + $attributes
        );

        // The employee opening the salary screen is the widest-range caller there is.
        (new CollectUserSalaryAction())($this->employee->id, false);

        $this->assertEquals(
            $shouldSurvive ? 50000 : 0,
            (float) $salary->fresh()->total_amount,
            $shouldSurvive
                ? 'A salary with committed money must never be zeroed by a recalculation.'
                : 'A month with no countable work time and nothing committed must be cleared.'
        );
    }

    public function testTheSweepWindowStopsAtTheCurrentPartialMonth(): void
    {
        $current = Salary::query()->create([
            'user_id' => $this->employee->id,
            // Today is 2026-05-10, so May is only partially covered by the range.
            'month' => '2026-05',
            'total_amount' => 50000,
            'total_remaining' => 50000,
            'min_salary' => 1000,
            'max_salary' => 1000,
            'total_minutes' => 3000,
        ]);

        (new CollectUserSalaryAction())($this->employee->id, false);

        $this->assertEquals(
            50000,
            (float) $current->fresh()->total_amount,
            'A partial range says nothing about the rest of the month, so it must be left alone.'
        );
    }
}
