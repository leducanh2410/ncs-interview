<?php

namespace Tests\Unit\Jobs;

use App\Actions\Common\Salary\CollectUserSalaryAction;
use App\Jobs\UpdateUserSalary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

/**
 * Queued from every edit that can move a month's worth of work (check-in fixes, absences,
 * approved history edits). Other tests already assert it gets pushed; this one runs it.
 *
 * Whether it did any work is observed through the employee's cached request range, which a
 * recalculation always clears.
 */
class UpdateUserSalaryTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->employee = $this->createEmployee();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testDoesNothingForAMonthTheEmployeeHasNoSalaryRowFor(): void
    {
        $this->primeCache();

        (new UpdateUserSalary($this->employee->id, '2026-03'))->handle();

        $this->assertNotNull($this->cachedRange(), 'No salary row means there is nothing to recalculate.');
        $this->assertDatabaseCount('salaries', 0);
    }

    public function testRecalculatesAnywayWhenAskedToCreateTheRowIfMissing(): void
    {
        $this->primeCache();

        (new UpdateUserSalary($this->employee->id, '2026-03', true))->handle();

        $this->assertNull($this->cachedRange());
    }

    public function testRecalculatesAnExistingMonth(): void
    {
        $this->createUnfinalizedSalary($this->employee, '2026-03', 50000);
        $this->primeCache();

        (new UpdateUserSalary($this->employee->id, '2026-03'))->handle();

        $this->assertNull($this->cachedRange());
    }

    public function testClearsAMonthThatLostAllOfItsWorkTime(): void
    {
        $salary = $this->createUnfinalizedSalary($this->employee, '2026-03', 50000);

        (new UpdateUserSalary($this->employee->id, '2026-03'))->handle();

        // No check-ins are left behind it, and nothing was paid out of it.
        $salary->refresh();
        $this->assertSame(0, (int) $salary->total_amount);
        $this->assertSame(0, (int) $salary->total_remaining);
    }

    public function testLeavesASettledMonthUntouched(): void
    {
        $salary = $this->createFinalizedSalary($this->employee, '2026-03', 50000);

        (new UpdateUserSalary($this->employee->id, '2026-03'))->handle();

        $this->assertSame(50000, (int) $salary->fresh()->total_amount);
    }

    public function testOnlyTouchesTheMonthItWasQueuedFor(): void
    {
        $march = $this->createUnfinalizedSalary($this->employee, '2026-03', 50000);
        $april = $this->createUnfinalizedSalary($this->employee, '2026-04', 40000);

        (new UpdateUserSalary($this->employee->id, '2026-03'))->handle();

        $this->assertSame(0, (int) $march->fresh()->total_amount);
        $this->assertSame(40000, (int) $april->fresh()->total_amount);
    }

    public function testOnlyTouchesTheEmployeeItWasQueuedFor(): void
    {
        $other = $this->createEmployee();
        $mine = $this->createUnfinalizedSalary($this->employee, '2026-03', 50000);
        $theirs = $this->createUnfinalizedSalary($other, '2026-03', 40000);

        (new UpdateUserSalary($this->employee->id, '2026-03'))->handle();

        $this->assertSame(0, (int) $mine->fresh()->total_amount);
        $this->assertSame(40000, (int) $theirs->fresh()->total_amount);
    }

    private function primeCache(): void
    {
        Cache::put(CollectUserSalaryAction::CACHE_KEY_PREFIX . $this->employee->id, ['min' => 1], 3600);
    }

    private function cachedRange(): mixed
    {
        return Cache::get(CollectUserSalaryAction::CACHE_KEY_PREFIX . $this->employee->id);
    }
}
