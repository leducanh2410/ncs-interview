<?php

namespace Tests\Feature\Manage\Salary;

use App\Enums\Encoding;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Jobs\UpdateUserSalary;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

/**
 * The payroll system exports a wide CSV; only four of its columns matter here. Row 0 is the
 * exporter's own title line, row 1 carries the headers that are checked, and the data starts
 * at row 2 - which is why the reported line numbers start at 3.
 */
class ImportDeductionTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const URL = '/manage/salary/import-deduction';

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->admin = $this->createSalaryAdmin(PermissionName::SalaryImportDeduction);
        $this->employee = $this->createEmployee();
        $this->employee->update(['staff_no' => '0042']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testRequiresAuthentication(): void
    {
        $this->postJson(self::URL)->assertUnauthorized();
    }

    public function testRequiresTheImportDeductionPermission(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson(self::URL, $this->payload($this->csv([['0042', 0, 0, 0]])))
            ->assertForbidden();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'no file' => [['file' => null], 'file'],
            'no month' => [['month' => null], 'month'],
            'month is a full date' => [['month' => '2026-03-01'], 'month'],
            'no encoding' => [['encoding' => null], 'encoding'],
            'unknown encoding' => [['encoding' => 'latin-1'], 'encoding'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloadProvider')]
    public function testRejectsAnInvalidPayload(array $overrides, string $expectedField): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $overrides + $this->payload($this->csv([['0042', 0, 0, 0]]))
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey($expectedField, $response->json('data'));
    }

    public function testRejectsAFileWhoseHeaderRowIsNotTheExpectedOne(): void
    {
        $content = "title\n" . implode(',', array_fill(0, 37, 'wrong')) . "\n";

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload(UploadedFile::fake()->createWithContent('deduction.csv', $content))
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::SalaryCsvMalformed->value);
    }

    public function testRecordsTheDeductionAndSettlesTheMonth(): void
    {
        $salary = $this->createUnfinalizedSalary($this->employee, '2026-03', 50000);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            // 3000 subtracted in total, of which 1000 was an advance and 330 its fee.
            $this->payload($this->csv([['0042', 1000, 330, 3000]]))
        );

        $response->assertOk();
        $salary->refresh();
        $this->assertSame(1670, (int) $salary->total_deductions);
        $this->assertTrue((bool) $salary->is_finalized);
        $response->assertJsonPath('data.error_data', []);
        $response->assertJsonPath('data.skipped_data', []);
        $response->assertJsonPath('data.valid_data.0042.total_deductions', 1670);
    }

    /**
     * @return array<string, array{0: array<int, mixed>, 1: ErrorCode}>
     */
    public static function badRowProvider(): array
    {
        return [
            'unknown staff number' => [['9999', 0, 0, 0], ErrorCode::SalaryCsvInvalidStaffNo],
            'empty staff number' => [['', 0, 0, 0], ErrorCode::SalaryCsvInvalidStaffNo],
            'advance is not a number' => [['0042', 'x', 0, 0], ErrorCode::SalaryRequestInvalidAmount],
            'negative advance' => [['0042', -1, 0, 0], ErrorCode::SalaryRequestInvalidAmount],
            'negative fee' => [['0042', 0, -1, 0], ErrorCode::SalaryRequestInvalidAmount],
            'negative total' => [['0042', 0, 0, -1], ErrorCode::SalaryRequestInvalidAmount],
            // The advance and its fee alone exceed the total subtracted.
            'total is smaller than its parts' => [['0042', 1000, 330, 500], ErrorCode::SalaryRequestInvalidAmount],
        ];
    }

    /**
     * @param array<int, mixed> $row
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRowProvider')]
    public function testReportsABadRowByLineAndLeavesTheMonthAlone(array $row, ErrorCode $expected): void
    {
        $salary = $this->createUnfinalizedSalary($this->employee, '2026-03', 50000);

        $response = $this->actingAs($this->admin, 'api')->postJson(self::URL, $this->payload($this->csv([$row])));

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $response->assertJsonPath('data.error_data.0.line', 3);
        $response->assertJsonPath('data.error_data.0.error_code', $expected->value);
        $salary->refresh();
        $this->assertSame(0, (int) $salary->total_deductions);
        $this->assertFalse((bool) $salary->is_finalized);
    }

    public function testRefusesADeductionBiggerThanTheMonthIsWorth(): void
    {
        $salary = $this->createUnfinalizedSalary($this->employee, '2026-03', 1000, [
            'total_paid' => 500,
            'total_fee' => 100,
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['0042', 0, 0, 900]]))
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::SalaryCsvAmountTooHigh->value);
        $this->assertSame(0, (int) $salary->fresh()->total_deductions);
    }

    /**
     * Every row is validated before anything is written, so a single bad line turns the whole
     * file away without a partial import to undo.
     */
    public function testOneBadRowRejectsTheWholeFile(): void
    {
        $other = $this->createEmployee();
        $other->update(['staff_no' => '0043']);
        $good = $this->createUnfinalizedSalary($this->employee, '2026-03', 50000);
        $alsoGood = $this->createUnfinalizedSalary($other, '2026-03', 50000);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['0042', 0, 0, 1000], ['9999', 0, 0, 1000], ['0043', 0, 0, 2000]]))
        );

        $response->assertStatus(422);
        $response->assertJsonCount(1, 'data.error_data');
        $response->assertJsonPath('data.error_data.0.line', 4);
        // Nothing at all is written when any line fails.
        $this->assertSame(0, (int) $good->fresh()->total_deductions);
        $this->assertSame(0, (int) $alsoGood->fresh()->total_deductions);
    }

    public function testAppliesEveryRowOfAFileThatIsEntirelyValid(): void
    {
        $other = $this->createEmployee();
        $other->update(['staff_no' => '0043']);
        $first = $this->createUnfinalizedSalary($this->employee, '2026-03', 50000);
        $second = $this->createUnfinalizedSalary($other, '2026-03', 50000);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL, $this->payload($this->csv([['0042', 0, 0, 1000], ['0043', 0, 0, 2000]])))
            ->assertOk();

        $this->assertSame(1000, (int) $first->fresh()->total_deductions);
        $this->assertSame(2000, (int) $second->fresh()->total_deductions);
    }

    public function testOnlyTouchesTheMonthThatWasImported(): void
    {
        $march = $this->createUnfinalizedSalary($this->employee, '2026-03', 50000);
        $april = $this->createUnfinalizedSalary($this->employee, '2026-04', 50000);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL, $this->payload($this->csv([['0042', 0, 0, 1000]]), '2026-03'))
            ->assertOk();

        $this->assertSame(1000, (int) $march->fresh()->total_deductions);
        $this->assertSame(0, (int) $april->fresh()->total_deductions);
    }

    /**
     * A month the system has not calculated yet cannot carry a deduction - booking it would
     * leave the month owing more than it holds. It gets its own code rather than the
     * amount-too-high one, because from the admin's side the file is fine: what is missing is
     * this employee's salary for that month.
     */
    public function testRefusesADeductionForAMonthTheEmployeeHasNoSalaryFor(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['0042', 0, 0, 1000]]))
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.error_data.0.line', 3);
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::SalaryCsvSalaryNotFound->value);
        $this->assertDatabaseCount('salaries', 0);
    }

    /**
     * An advance whose own fee cancels it out nets to zero, but payroll still moved money that
     * month. The mismatch has to surface: reporting only on a positive net deduction would let
     * the row through silently and the import would claim success.
     */
    public function testReportsAMissingMonthEvenWhenTheRowNetsToZero(): void
    {
        // 1000 advanced, 330 fee, 1330 subtracted in total - a net deduction of 0.
        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['0042', 1000, 330, 1330]]))
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::SalaryCsvSalaryNotFound->value);
        $this->assertDatabaseCount('salaries', 0);
    }

    /**
     * The amount-too-high code stays reserved for what it says: a month that exists but is not
     * worth what the file wants taken out of it.
     */
    public function testTellsAMissingMonthApartFromAMonthThatIsTooSmall(): void
    {
        $this->createUnfinalizedSalary($this->employee, '2026-03', 500);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['0042', 0, 0, 1000]]))
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::SalaryCsvAmountTooHigh->value);
    }

    /**
     * With nothing deducted there is nothing to settle either: the month is left alone rather
     * than opened at 0 yen and finalized, which would lock it against every recalculation.
     */
    public function testLeavesAMonthTheEmployeeHasNoSalaryForAlone(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['0042', 0, 0, 0]]))
        );

        $response->assertOk();
        $this->assertDatabaseCount('salaries', 0);
        // Silently dropping it would leave the admin thinking the whole file went in, so the
        // line comes back listed as skipped.
        $response->assertJsonPath('data.skipped_data.0.line', 3);
        $response->assertJsonPath('data.skipped_data.0.staff_no', '0042');
    }

    /**
     * A file can hold both kinds of row at once. Only the row that was genuinely left alone is
     * reported as skipped - the applied one is not, and neither is a row that failed.
     */
    public function testListsOnlyTheRowsItActuallyLeftAlone(): void
    {
        $other = $this->createEmployee();
        $other->update(['staff_no' => '0043']);
        $march = $this->createUnfinalizedSalary($this->employee, '2026-03', 50000);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            // 0042 has a month to settle; 0043 has none and nothing on the row.
            $this->payload($this->csv([['0042', 0, 0, 1000], ['0043', 0, 0, 0]]))
        );

        $response->assertOk();
        $this->assertSame(1000, (int) $march->fresh()->total_deductions);
        $response->assertJsonCount(1, 'data.skipped_data');
        $response->assertJsonPath('data.skipped_data.0.staff_no', '0043');
        $response->assertJsonPath('data.skipped_data.0.line', 4);
    }

    /**
     * The point of leaving that month alone: work history recorded afterwards - a late
     * check-in fix, a shift added to the roster - still gets counted. A settled 0 yen row
     * would have pinned the month there for good, because SalaryService never recalculates a
     * finalized one.
     */
    public function testAMonthTheImportSkippedIsStillCalculatedWhenWorkHistoryArrives(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL, $this->payload($this->csv([['0042', 0, 0, 0]]), '2026-04'))
            ->assertOk();

        $this->recordEightHoursWorkedOn('2026-04-10');
        (new UpdateUserSalary($this->employee->id, '2026-04', true))->handle();

        // 8 hours at the default 1000 yen an hour.
        $this->assertDatabaseHas('salaries', [
            'user_id' => $this->employee->id,
            'month' => '2026-04',
            'total_amount' => 8000,
        ]);
    }

    private function recordEightHoursWorkedOn(string $date): void
    {
        $workplace = Workplace::factory()->create();
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $position = WorkplacePosition::create(['workplace_id' => $workplace->id, 'name' => 'Staff']);
        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => User::factory()->create()->id,
            'date' => $date,
            'adjusted_date' => $date,
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $this->employee->id,
            'check_in_time' => "{$date} 09:00:00",
            'check_out_time' => "{$date} 18:00:00",
            'check_in_time_rounded' => "{$date} 09:00:00",
            'check_out_time_rounded' => "{$date} 18:00:00",
            'break_minutes' => 60,
            'work_minutes' => 480,
            'work_minutes_normal' => 480,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
    }

    /**
     * The ceiling above bounds every deduction by what its month is worth, so no value that
     * reaches the write can overflow the column any more: this import has no reachable path to
     * a 500. Rollback of the shared transaction shape is proven by the twin case in
     * ImportRemainingTest, whose written amount is not bounded the same way.
     */
    public function testNoValidFileCanBreakTheWrite(): void
    {
        $this->createUnfinalizedSalary($this->employee, '2026-03', 50000);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['0042', 0, 0, '999999999999999999999']]))
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::SalaryCsvAmountTooHigh->value);
        $this->assertDatabaseHas('salaries', ['user_id' => $this->employee->id, 'total_deductions' => 0]);
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function csv(array $rows): UploadedFile
    {
        $header = array_fill(0, 37, '');
        $header[0] = '社員コード';
        $header[34] = '前払い';
        $header[35] = '前払い手数料';
        $header[36] = '控除合計';

        $lines = ['給与明細', implode(',', $header)];
        foreach ($rows as $row) {
            $line = array_fill(0, 37, '');
            [$line[0], $line[34], $line[35], $line[36]] = $row;
            $lines[] = implode(',', $line);
        }

        return UploadedFile::fake()->createWithContent('deduction.csv', implode("\n", $lines) . "\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?UploadedFile $file, string $month = '2026-03'): array
    {
        return [
            'file' => $file,
            'month' => $month,
            'encoding' => Encoding::UTF8->value,
        ];
    }
}
