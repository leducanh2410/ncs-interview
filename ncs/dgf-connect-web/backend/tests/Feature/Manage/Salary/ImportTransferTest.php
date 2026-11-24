<?php

namespace Tests\Feature\Manage\Salary;

use App\Actions\Common\Salary\CollectUserSalaryAction;
use App\Enums\Encoding;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\SalaryProcessStatus;
use App\Enums\SalaryRequestStatus;
use App\Jobs\AutoCreateSalaryRequest;
use App\Models\Salary;
use App\Models\SalaryProcess;
use App\Models\SalaryRequest;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkTimeReport;
use App\Models\WorkTimeReportDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

/**
 * The bank returns the sheet it was given with two extra timestamp columns: one filled in when
 * the employee collected the money, the other when the transfer expired. This import closes
 * the request either way, and on collection it also books the money against the months the
 * request drew from. Row 0 is the header, so the first data row is reported as line 2.
 */
class ImportTransferTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const URL = '/manage/salary/import-transfer';

    private User $admin;

    private User $employee;

    private Salary $finalized;

    private Salary $unfinalized;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->admin = $this->createSalaryAdmin(
            PermissionName::SalaryImportTransfer,
            PermissionName::SalaryImportDeduction
        );
        $this->employee = $this->createEmployee();
        $this->employee->update(['staff_no' => '0042']);
        $this->finalized = $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->unfinalized = $this->createUnfinalizedSalary($this->employee, '2026-05', 20000);
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

    public function testRequiresTheImportTransferPermission(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson(self::URL, $this->payload($this->csv([['0042', 51000, '', '']])))
            ->assertForbidden();
    }

    public function testRejectsAFileWhoseHeaderRowIsNotTheExpectedOne(): void
    {
        $content = implode(',', array_fill(0, 17, 'wrong')) . "\n";

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload(UploadedFile::fake()->createWithContent('transfer.csv', $content))
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::SalaryCsvMalformed->value);
    }

    public function testRequiresAFileAndAnEncoding(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(self::URL, []);

        $response->assertStatus(422);
        $this->assertArrayHasKey('file', $response->json('data'));
        $this->assertArrayHasKey('encoding', $response->json('data'));
    }

    public function testBooksACollectedTransferAgainstEveryMonthItDrewFrom(): void
    {
        $request = $this->createPaidRequest();
        Cache::put(CollectUserSalaryAction::CACHE_KEY_PREFIX . $this->employee->id, ['min' => 1], 3600);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['0042', 51000, '2026-05-11 09:00:00', '']]))
        );

        $response->assertOk();
        $request->refresh();
        $this->assertSame(SalaryRequestStatus::ReceivedConfirmed->value, $request->status);
        $this->assertSame('2026-05-11 09:00:00', Carbon::parse($request->received_at)->toDateTimeString());
        $this->assertNull($request->returned_at);

        // The settled month was drained to the yen, so it is now fully paid.
        $this->finalized->refresh();
        $this->assertSame(50000, (int) $this->finalized->total_paid);
        $this->assertSame(0, (int) $this->finalized->total_fee);
        $this->assertSame(0, (int) $this->finalized->total_remaining);
        $this->assertTrue((bool) $this->finalized->is_fully_paid);

        // The month in progress paid the remaining 1000 plus the 330 transfer fee.
        $this->unfinalized->refresh();
        $this->assertSame(1000, (int) $this->unfinalized->total_paid);
        $this->assertSame(330, (int) $this->unfinalized->total_fee);
        $this->assertSame(18670, (int) $this->unfinalized->total_remaining);
        $this->assertFalse((bool) $this->unfinalized->is_fully_paid);

        // The employee's cached request range no longer reflects reality.
        $this->assertNull(Cache::get(CollectUserSalaryAction::CACHE_KEY_PREFIX . $this->employee->id));
    }

    public function testAnExpiredTransferClosesTheRequestWithoutMovingAnyMoney(): void
    {
        $request = $this->createPaidRequest();

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['0042', 51000, '', '2026-05-20 09:00:00']]))
        );

        $response->assertOk();
        $request->refresh();
        $this->assertSame(SalaryRequestStatus::Returned->value, $request->status);
        $this->assertSame('2026-05-20 09:00:00', Carbon::parse($request->returned_at)->toDateTimeString());
        $this->assertNull($request->received_at);
        $this->assertSame(0, (int) $this->finalized->fresh()->total_paid);
        $this->assertSame(0, (int) $this->unfinalized->fresh()->total_paid);
    }

    public function testARowWithNeitherTimestampIsStillInFlightAndIsSkipped(): void
    {
        $request = $this->createPaidRequest();

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['0042', 51000, '', '']]))
        );

        $response->assertOk();
        $this->assertSame(SalaryRequestStatus::Paid->value, $request->fresh()->status);
        $this->assertSame(0, (int) $this->finalized->fresh()->total_paid);
    }

    public function testAlsoAcceptsARequestTheEmployeeAlreadyMarkedAsReceived(): void
    {
        $request = $this->createPaidRequest();
        $this->actingAs($this->employee, 'api')
            ->postJson("/user/salary/requests/{$request->id}/confirm-received")
            ->assertOk();

        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL, $this->payload($this->csv([['0042', 51000, '2026-05-11 09:00:00', '']])))
            ->assertOk();

        $this->assertSame(SalaryRequestStatus::ReceivedConfirmed->value, $request->fresh()->status);
    }

    /**
     * @return array<string, array{0: array<int, mixed>, 1: ErrorCode}>
     */
    public static function badRowProvider(): array
    {
        return [
            'empty customer number' => [['', 51000, '', ''], ErrorCode::SalaryTransferCsvInvalidStaffNo],
            'unknown customer number' => [['9999', 51000, '', ''], ErrorCode::SalaryTransferCsvInvalidStaffNo],
            'amount is not a number' => [['0042', 'x', '', ''], ErrorCode::SalaryTransferCsvInvalidAmount],
            'negative amount' => [['0042', -1, '', ''], ErrorCode::SalaryTransferCsvInvalidAmount],
            'amount does not match the request' => [
                ['0042', 52000, '', ''],
                ErrorCode::SalaryTransferCsvAmountNotMatch,
            ],
            'both timestamps filled in' => [
                ['0042', 51000, '2026-05-11 09:00:00', '2026-05-12 09:00:00'],
                ErrorCode::SalaryTransferCsvInvalidTime,
            ],
            'collected before the payment went out' => [
                ['0042', 51000, '2026-05-09 09:00:00', ''],
                ErrorCode::SalaryTransferCsvInvalidTime,
            ],
            'expired before the payment went out' => [
                ['0042', 51000, '', '2026-05-09 09:00:00'],
                ErrorCode::SalaryTransferCsvInvalidTime,
            ],
            'timestamp is not a date' => [
                ['0042', 51000, 'yesterday-ish', ''],
                ErrorCode::SalaryTransferCsvInvalidTime,
            ],
        ];
    }

    /**
     * @param array<int, mixed> $row
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRowProvider')]
    public function testReportsABadRowByLineAndChangesNothing(array $row, ErrorCode $expected): void
    {
        $request = $this->createPaidRequest();

        $response = $this->actingAs($this->admin, 'api')->postJson(self::URL, $this->payload($this->csv([$row])));

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $response->assertJsonPath('data.error_data.0.line', 2);
        $response->assertJsonPath('data.error_data.0.error_code', $expected->value);
        $this->assertSame(SalaryRequestStatus::Paid->value, $request->fresh()->status);
        $this->assertSame(0, (int) $this->finalized->fresh()->total_paid);
    }

    public function testReportsAnEmployeeWithNoPayableRequest(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['0042', 51000, '2026-05-11 09:00:00', '']]))
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::SalaryTransferCsvRequestNotFound->value);
    }

    public function testARequestStillWaitingForPaymentIsNotPayableYet(): void
    {
        $request = $this->createRequest();
        $this->advanceRequestTo($request, SalaryRequestStatus::WaitForPayment, $this->createSalaryApprover());

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['0042', 51000, '2026-05-11 09:00:00', '']]))
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::SalaryTransferCsvRequestNotFound->value);
        $this->assertSame(SalaryRequestStatus::WaitForPayment->value, $request->fresh()->status);
    }

    /**
     * A deduction imported after the request was raised can leave the month unable to carry
     * what the request claims from it. That is only discovered on the second pass, once the
     * per-row checks have all passed.
     */
    public function testRefusesToBookMoreThanAMonthCanStillCarry(): void
    {
        $request = $this->createPaidRequest();
        $this->importDeductionOf(1000);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload($this->csv([['0042', 51000, '2026-05-11 09:00:00', '']]))
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.error_data.0.error_code', ErrorCode::SalaryTransferCsvAmountTooHigh->value);
        $this->assertSame(SalaryRequestStatus::Paid->value, $request->fresh()->status);
        $this->assertSame(0, (int) $this->finalized->fresh()->total_paid);
    }

    /**
     * Every row is validated before anything is written, so a single bad line turns the whole
     * file away without a partial import to undo.
     */
    public function testOneBadRowRejectsTheWholeFile(): void
    {
        $mine = $this->createPaidRequest();
        $other = $this->createEmployee();
        $other->update(['staff_no' => '0043']);
        $this->createFinalizedSalary($other, '2026-03', 50000);
        $this->createUnfinalizedSalary($other, '2026-05', 20000);
        $theirs = $this->createPaidRequestFor($other);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload(
                $this->csv([
                    ['0042', 51000, '2026-05-11 09:00:00', ''],
                    ['9999', 51000, '2026-05-11 09:00:00', ''],
                    ['0043', 51000, '2026-05-11 09:00:00', ''],
                ])
            )
        );

        $response->assertStatus(422);
        $response->assertJsonCount(1, 'data.error_data');
        $response->assertJsonPath('data.error_data.0.line', 3);
        $this->assertSame(SalaryRequestStatus::Paid->value, $mine->fresh()->status);
        $this->assertSame(SalaryRequestStatus::Paid->value, $theirs->fresh()->status);
        $this->assertSame(0, (int) $this->finalized->fresh()->total_paid);
    }

    /**
     * A request the scheduler raised is booked exactly like a manual one: the month is
     * credited with what the bank actually sent and keeps owing the rest.
     *
     * The 330 yen transfer fee is billed to the month as well, exactly as the manual flow
     * would - rounding the request down to 50000 left 5000 behind to take it from.
     */
    public function testAnAutoCreatedRequestIsBookedForExactlyWhatWasTransferred(): void
    {
        $salary = $this->autoCreateRequestFor('2026-04', 55000);
        $request = SalaryRequest::query()->latest('id')->firstOrFail();
        $this->assertSame(50000, (int) $request->amount);
        $this->actingAs($this->createSalaryApprover(), 'api')
            ->postJson("/manage/salary/requests/{$request->id}/confirm-paid")
            ->assertOk();

        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL, $this->payload($this->csv([['0042', 50000, '2026-05-11 09:00:00', '']])))
            ->assertOk();

        $salary->refresh();
        $this->assertSame(50000, (int) $salary->total_paid);
        $this->assertSame(330, (int) $salary->total_fee);
        $this->assertSame(4670, (int) $salary->total_remaining);
        $this->assertFalse((bool) $salary->is_fully_paid);
    }

    /**
     * The fee billed against an unfinalized month's untouched fifth is bookable: the sum the
     * job checks before setting has_fee is the same one this import checks before writing.
     */
    public function testAFeeBilledAgainstAnUnfinalizedMonthIsBookable(): void
    {
        // Five 8-hour shifts at 1250 an hour: the month works out to 50000, of which 80% -
        // a round 40000 - may be advanced while it is still unsettled.
        $salary = $this->autoCreateRequestForUnfinalized('2026-04');
        $request = SalaryRequest::query()->latest('id')->firstOrFail();
        $this->assertSame(40000, (int) $request->amount);
        $this->actingAs($this->createSalaryApprover(), 'api')
            ->postJson("/manage/salary/requests/{$request->id}/confirm-paid")
            ->assertOk();

        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL, $this->payload($this->csv([['0042', 40000, '2026-05-11 09:00:00', '']])))
            ->assertOk();

        $salary->refresh();
        $this->assertSame(50000, (int) $salary->total_amount);
        $this->assertSame(40000, (int) $salary->total_paid);
        $this->assertSame(330, (int) $salary->total_fee);
        $this->assertSame(9670, (int) $salary->total_remaining);
        $this->assertFalse((bool) $salary->is_fully_paid);
    }

    /**
     * A month whose payable amount is already a full 10000 is drained exactly by the request,
     * leaving nothing to bill the fee from. The import has to go through regardless - charging
     * it would drive the remainder negative and reject every row in the file.
     */
    public function testAnAutoCreatedRequestThatDrainsItsMonthStillImports(): void
    {
        $salary = $this->autoCreateRequestFor('2026-04', 50000);
        $request = SalaryRequest::query()->latest('id')->firstOrFail();
        $this->assertSame(50000, (int) $request->amount);
        $this->actingAs($this->createSalaryApprover(), 'api')
            ->postJson("/manage/salary/requests/{$request->id}/confirm-paid")
            ->assertOk();

        $this->actingAs($this->admin, 'api')
            ->postJson(self::URL, $this->payload($this->csv([['0042', 50000, '2026-05-11 09:00:00', '']])))
            ->assertOk();

        $salary->refresh();
        $this->assertSame(50000, (int) $salary->total_paid);
        $this->assertSame(0, (int) $salary->total_fee);
        $this->assertSame(0, (int) $salary->total_remaining);
        $this->assertTrue((bool) $salary->is_fully_paid);
    }

    /**
     * Drive AutoCreateSalaryRequest for one employee at one workplace, the way the scheduler
     * does, against a settled month worth $totalAmount. A finalized row is locked against
     * recalculation, so building it directly is the state the import flows leave behind.
     */
    private function autoCreateRequestFor(string $month, int $totalAmount): Salary
    {
        $this->queueMonthForProcessing($month);
        $salary = $this->createFinalizedSalary($this->employee, $month, $totalAmount);

        (new AutoCreateSalaryRequest())->handle();

        return $salary;
    }

    /**
     * The same run against a month that is not settled. Five 8-hour shifts at 1250 yen an hour
     * make it worth 50000, worked out by SalaryService itself - an unfinalized row cannot be
     * built directly, because the recalculation the job kicks off would zero it.
     */
    private function autoCreateRequestForUnfinalized(string $month): Salary
    {
        $workplace = $this->queueMonthForProcessing($month);
        $this->recordShiftsWorked($this->employee, $workplace, $month, 5, 480, 1250);

        (new AutoCreateSalaryRequest())->handle();

        return Salary::query()->where('user_id', $this->employee->id)->where('month', $month)->firstOrFail();
    }

    /**
     * The workplace, the month's work time report and the queued process - everything the job
     * needs to pick this employee up, short of the salary itself.
     */
    private function queueMonthForProcessing(string $month): Workplace
    {
        $workplace = Workplace::factory()->create();
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
        WorkTimeReportDetail::query()->create([
            'work_time_report_id' => $report->id,
            'workplace_id' => $workplace->id,
            'work_days' => 10,
            'work_minutes' => 3000,
            'work_minutes_normal' => 3000,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
        SalaryProcess::query()->create([
            'month' => $month,
            'workplace_id' => $workplace->id,
            'status' => SalaryProcessStatus::Pending->value,
            'priority' => 0,
            'scheduled_at' => Carbon::now()->subHour(),
        ]);

        return $workplace;
    }

    private function importDeductionOf(int $amount): void
    {
        $header = array_fill(0, 37, '');
        $header[0] = '社員コード';
        $header[34] = '前払い';
        $header[35] = '前払い手数料';
        $header[36] = '控除合計';
        $row = array_fill(0, 37, '');
        [$row[0], $row[34], $row[35], $row[36]] = ['0042', 0, 0, $amount];

        $this->actingAs($this->admin, 'api')
            ->postJson('/manage/salary/import-deduction', [
                'file' => UploadedFile::fake()->createWithContent(
                    'deduction.csv',
                    implode("\n", ['title', implode(',', $header), implode(',', $row)]) . "\n"
                ),
                'month' => '2026-03',
                'encoding' => Encoding::UTF8->value,
            ])
            ->assertOk();
    }

    private function createPaidRequest(): SalaryRequest
    {
        return $this->createPaidRequestFor($this->employee);
    }

    private function createPaidRequestFor(User $employee): SalaryRequest
    {
        $this->actingAs($employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 51000])
            ->assertOk();
        $request = SalaryRequest::query()->where('user_id', $employee->id)->latest('id')->firstOrFail();
        $this->advanceRequestTo($request, SalaryRequestStatus::Paid, $this->createSalaryApprover());

        return $request;
    }

    private function createRequest(): SalaryRequest
    {
        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 51000])
            ->assertOk();

        return SalaryRequest::query()->where('user_id', $this->employee->id)->latest('id')->firstOrFail();
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function csv(array $rows): UploadedFile
    {
        $header = array_fill(0, 17, '');
        $header[6] = 'お客様番号';
        $header[7] = '送金額';
        $header[13] = '送金完了更新日時';
        $header[16] = '期限切れ更新日時';

        $lines = [implode(',', $header)];
        foreach ($rows as $row) {
            $line = array_fill(0, 17, '');
            [$line[6], $line[7], $line[13], $line[16]] = $row;
            $lines[] = implode(',', $line);
        }

        return UploadedFile::fake()->createWithContent('transfer.csv', implode("\n", $lines) . "\n");
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
