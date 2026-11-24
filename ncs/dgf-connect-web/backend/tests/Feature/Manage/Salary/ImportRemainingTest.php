<?php

namespace Tests\Feature\Manage\Salary;

use App\Actions\Common\Salary\CollectUserSalaryAction;
use App\Enums\Encoding;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\Salary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

/**
 * Opening balances: what each employee was still owed for the months worked before this
 * system started keeping the books. Every imported month is written as settled, so it counts
 * towards a salary request in full and is never recalculated from check-ins afterwards.
 */
class ImportRemainingTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const URL = '/manage/salary/import-remaining';

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');
        config(['salary.start_date' => '2025-08-01']);

        $this->admin = $this->createSalaryAdmin(PermissionName::SalaryImportRemaining);
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

    public function testRequiresTheImportRemainingPermission(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson(self::URL, $this->payload($this->csv([['2025-07', '0042', 30000]])))
            ->assertForbidden();
    }

    public function testRequiresAFileAndAnEncoding(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(self::URL, []);

        $response->assertStatus(422);
        $this->assertArrayHasKey('file', $response->json('data'));
        $this->assertArrayHasKey('encoding', $response->json('data'));
    }

    public function testRejectsAFileWhoseHeaderRowIsNotTheExpectedOne(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload(UploadedFile::fake()->createWithContent('remaining.csv', "a,b,c\n"))
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::SalaryCsvMalformed->value);
    }

    public function testCreatesASettledMonthForAnEmployeeWithNoSalaryRowYet(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL, $this->payload($this->csv([['2025-07', '0042', 30000]])))
            ->assertOk();

        $salary = Salary::query()->where('user_id', $this->employee->id)->where('month', '2025-07')->firstOrFail();
        $this->assertSame(30000, (int) $salary->total_amount);
        $this->assertSame(30000, (int) $salary->total_remaining);
        $this->assertTrue((bool) $salary->is_finalized);
        $this->assertTrue((bool) $salary->is_completed);
        $this->assertSame(0, (int) $salary->total_minutes);
    }

    public function testOverwritesAnExistingMonthAndClearsWhatWasBookedAgainstIt(): void
    {
        $salary = $this->createFinalizedSalary($this->employee, '2025-07', 10000, [
            'total_paid' => 500,
            'total_deductions' => 200,
            'total_fee' => 100,
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL, $this->payload($this->csv([['2025-07', '0042', 30000]])))
            ->assertOk();

        $salary->refresh();
        $this->assertSame(30000, (int) $salary->total_amount);
        $this->assertSame(30000, (int) $salary->total_remaining);
        $this->assertSame(0, (int) $salary->total_paid);
        $this->assertSame(0, (int) $salary->total_deductions);
        $this->assertSame(0, (int) $salary->total_fee);
    }

    public function testForgetsTheEmployeesCachedRequestRange(): void
    {
        Cache::put(CollectUserSalaryAction::CACHE_KEY_PREFIX . $this->employee->id, ['min' => 1], 3600);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL, $this->payload($this->csv([['2025-07', '0042', 30000]])))
            ->assertOk();

        $this->assertNull(Cache::get(CollectUserSalaryAction::CACHE_KEY_PREFIX . $this->employee->id));
    }

    public function testImportsSeveralMonthsForSeveralEmployeesInOneFile(): void
    {
        $other = $this->createEmployee();
        $other->update(['staff_no' => '0043']);

        $this->actingAs($this->admin, 'api')
            ->postJson(
                self::URL,
                $this->payload(
                    $this->csv([['2025-06', '0042', 10000], ['2025-07', '0042', 20000], ['2025-07', '0043', 30000]])
                )
            )
            ->assertOk();

        $this->assertDatabaseCount('salaries', 3);
        $this->assertDatabaseHas('salaries', [
            'user_id' => $this->employee->id,
            'month' => '2025-06',
            'total_amount' => 10000,
        ]);
        $this->assertDatabaseHas('salaries', ['user_id' => $other->id, 'month' => '2025-07', 'total_amount' => 30000]);
    }

    /**
     * @return array<string, array{0: array<int, mixed>, 1: ErrorCode}>
     */
    public static function badRowProvider(): array
    {
        return [
            'no month' => [['', '0042', 30000], ErrorCode::SalaryCsvInvalidMonth],
            'month is a full date' => [['2025-07-01', '0042', 30000], ErrorCode::SalaryCsvInvalidMonth],
            'month is nonsense' => [['last summer', '0042', 30000], ErrorCode::SalaryCsvInvalidMonth],
            // Anything from the month the system took over is its own to calculate.
            'the month the system took over' => [['2025-08', '0042', 30000], ErrorCode::SalaryCsvInvalidMonth],
            'month after the system started' => [['2025-09', '0042', 30000], ErrorCode::SalaryCsvInvalidMonth],
            'no staff number' => [['2025-07', '', 30000], ErrorCode::SalaryCsvInvalidStaffNo],
            'unknown staff number' => [['2025-07', '9999', 30000], ErrorCode::SalaryCsvInvalidStaffNo],
            'amount is not a number' => [['2025-07', '0042', 'lots'], ErrorCode::SalaryCsvInvalidAmount],
            'negative amount' => [['2025-07', '0042', -1], ErrorCode::SalaryCsvInvalidAmount],
        ];
    }

    /**
     * @param array<int, mixed> $row
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRowProvider')]
    public function testReportsABadRowByLineAndWritesNothing(array $row, ErrorCode $expected): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(self::URL, $this->payload($this->csv([$row])));

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $response->assertJsonPath('data.error_data.0.line', 2);
        $response->assertJsonPath('data.error_data.0.error_code', $expected->value);
        $this->assertDatabaseCount('salaries', 0);
    }

    /**
     * Every row is validated before anything is written, so a single bad line turns the whole
     * file away without a partial import to undo.
     */
    public function testOneBadRowRejectsTheWholeFile(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['2025-06', '0042', 10000], ['2025-09', '0042', 20000]]))
        );

        $response->assertStatus(422);
        $response->assertJsonCount(1, 'data.error_data');
        $response->assertJsonPath('data.error_data.0.line', 3);
        $this->assertDatabaseCount('salaries', 0);
    }

    /**
     * The action itself has no upper bound on the amount, so a corrupt file can still blow up
     * on the insert. The controller catches everything and reports the file as malformed
     * rather than letting a 500 stack trace out - and nothing is written.
     */
    public function testAnAmountTooLargeForTheColumnIsReportedAsAMalformedFile(): void
    {
        Log::spy();

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['2025-07', '0042', '999999999999999999999']]))
        );

        $response->assertStatus(500);
        $response->assertJsonPath('error', ErrorCode::SalaryCsvMalformed->value);
        $this->assertDatabaseCount('salaries', 0);
    }

    /**
     * The write itself is one transaction. Forcing it to fail on a later row - here an amount
     * the column cannot hold - has to leave the months already opened before it untouched.
     */
    public function testAFailedWriteUndoesTheRowsAlreadyAppliedInTheSameFile(): void
    {
        Log::spy();

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['2025-06', '0042', 10000], ['2025-07', '0042', '999999999999999999999']]))
        );

        $response->assertStatus(500);
        // 2025-06 was inserted before the second row blew up, and must be gone again.
        $this->assertDatabaseCount('salaries', 0);
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function csv(array $rows): UploadedFile
    {
        $lines = ['月,社員コード,残額'];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return UploadedFile::fake()->createWithContent('remaining.csv', implode("\n", $lines) . "\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?UploadedFile $file): array
    {
        return [
            'file' => $file,
            'encoding' => Encoding::UTF8->value,
        ];
    }
}
