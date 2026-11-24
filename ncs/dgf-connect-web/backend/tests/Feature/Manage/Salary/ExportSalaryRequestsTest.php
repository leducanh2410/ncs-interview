<?php

namespace Tests\Feature\Manage\Salary;

use App\Enums\PermissionName;
use App\Enums\SalaryRequestStatus;
use App\Exports\SalaryRequestExport;
use App\Models\SalaryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

/**
 * The sheet this produces is uploaded to the bank, so the shape of each row matters more than
 * the query behind it: the customer number is zero-padded, the payee name is stripped of
 * spaces and cut to 15 characters, and the fixed columns are always the same.
 */
class ExportSalaryRequestsTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const URL = '/manage/salary/requests/export';

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->admin = $this->createSalaryAdmin(PermissionName::SalaryRequestExport);
        $this->employee = $this->createEmployee();
        $this->employee->update([
            'staff_no' => '42',
            'email' => 'taro@example.com',
            'first_name' => 'Taro',
            'last_name' => 'Yamada',
        ]);
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->createUnfinalizedSalary($this->employee, '2026-05', 20000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testRequiresAuthentication(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function testRequiresTheSalaryRequestExportPermission(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson(self::URL)
            ->assertForbidden();
    }

    public function testTheListPermissionAloneDoesNotGrantTheExport(): void
    {
        $reader = $this->createSalaryAdmin(PermissionName::SalaryRequestList);

        $this->actingAs($reader, 'api')->getJson(self::URL)->assertForbidden();
    }

    public function testWritesOneBankRowPerRequest(): void
    {
        Excel::fake();
        $this->createRequestFor($this->employee);

        $this->actingAs($this->admin, 'api')->get(self::URL)->assertOk();

        Excel::assertDownloaded('salary_request.xlsx', function (SalaryRequestExport $export) {
            $row = $export->collection()->first();

            // Names are stored upper-cased, and the sheet wants them without the separator.
            return $row['staff_no'] === '0042' &&
                $row['staff_no_2'] === '0042' &&
                $row['amount'] === 51000 &&
                $row['notification_method'] === 'Ｅメール' &&
                $row['content'] === '前払い' &&
                $row['name'] === 'YAMADATARO' &&
                $row['email'] === 'taro@example.com';
        });
    }

    public function testCutsAPayeeNameLongerThanTheBankAccepts(): void
    {
        Excel::fake();
        $this->employee->update(['first_name' => 'Ravishann', 'last_name' => 'Karnasooriya']);
        $this->createRequestFor($this->employee);

        $this->actingAs($this->admin, 'api')->get(self::URL)->assertOk();

        Excel::assertDownloaded('salary_request.xlsx', function (SalaryRequestExport $export) {
            return $export->collection()->first()['name'] === 'KARNASOORIYARAV';
        });
    }

    public function testExportsEveryStatusUnlessAskedOtherwise(): void
    {
        Excel::fake();
        $cancelled = $this->createRequestFor($this->employee);
        $this->actingAs($this->employee, 'api')
            ->postJson("/user/salary/requests/{$cancelled->id}/cancel")
            ->assertOk();
        $this->createRequestFor($this->employee);

        $this->actingAs($this->admin, 'api')->get(self::URL)->assertOk();

        Excel::assertDownloaded('salary_request.xlsx', function (SalaryRequestExport $export) {
            return $export->collection()->count() === 2;
        });
    }

    public function testFiltersByStatusSoOnlyPayableRequestsReachTheBank(): void
    {
        Excel::fake();
        $cancelled = $this->createRequestFor($this->employee);
        $this->actingAs($this->employee, 'api')
            ->postJson("/user/salary/requests/{$cancelled->id}/cancel")
            ->assertOk();
        $open = $this->createRequestFor($this->employee);
        $this->advanceRequestTo($open, SalaryRequestStatus::WaitForPayment, $this->createSalaryApprover());

        $this->actingAs($this->admin, 'api')
            ->get(self::URL . '?status=' . SalaryRequestStatus::WaitForPayment->value)
            ->assertOk();

        Excel::assertDownloaded('salary_request.xlsx', function (SalaryRequestExport $export) {
            return $export->collection()->count() === 1 && $export->collection()->first()['amount'] === 51000;
        });
    }

    public function testFiltersByEmployee(): void
    {
        Excel::fake();
        $other = $this->createEmployee();
        $other->update(['staff_no' => '77']);
        $this->createFinalizedSalary($other, '2026-03', 50000);
        $this->createUnfinalizedSalary($other, '2026-05', 20000);
        $this->createRequestFor($this->employee);
        $this->createRequestFor($other);

        $this->actingAs($this->admin, 'api')
            ->get(self::URL . "?user_id={$other->id}")
            ->assertOk();

        Excel::assertDownloaded('salary_request.xlsx', function (SalaryRequestExport $export) {
            return $export->collection()->count() === 1 && $export->collection()->first()['staff_no'] === '0077';
        });
    }

    public function testExportsAnEmptySheetWhenNothingMatches(): void
    {
        Excel::fake();

        $this->actingAs($this->admin, 'api')->get(self::URL)->assertOk();

        Excel::assertDownloaded('salary_request.xlsx', function (SalaryRequestExport $export) {
            return $export->collection()->isEmpty();
        });
    }

    private function createRequestFor(User $employee): SalaryRequest
    {
        $this->actingAs($employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 51000])
            ->assertOk();

        return SalaryRequest::query()->where('user_id', $employee->id)->latest('id')->firstOrFail();
    }
}
