<?php

namespace Tests\Feature\Admin\PaperReconciliation;

use App\Enums\PermissionName;
use App\Models\User;
use App\Support\Papers\AdminScanFieldComparator;
use App\Support\Residence\ResidenceSlotResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsAdminPaperUploadFixtures;
use Tests\TestCase;

/**
 * The employee side and the card history, driven through the real capture endpoints so
 * every admin scan under test is one the system actually produced.
 */
class PaperReconciliationVerdictTest extends TestCase
{
    use BuildsAdminPaperUploadFixtures;
    use RefreshDatabase;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Queue::fake();
        $this->fakeAdminScanOcr();

        $this->viewer = $this->adminWithPermissions(PermissionName::UserPaperList);
    }

    public function testAMatchingCardReportsMatched(): void
    {
        $employee = $this->employeeMatchingTheScan();
        $this->scanAndSubmit($employee);

        $detail = $this->detail($employee);
        $detail->assertOk();
        $this->assertSame(AdminScanFieldComparator::VERDICT_MATCHED, $detail->json('data.verdict'));
        $this->assertSame(0, $detail->json('data.mismatch_count'));
        $this->assertFalse($detail->json('data.employee_residence_missing'));
    }

    public function testAProfileWithNoResidenceCardStillComparesTheRestOfTheEmployeeSide(): void
    {
        // The regression this pins: blanking the whole employee side would hide a scanned
        // card bearing somebody else's name on exactly the employees most at risk.
        $employee = $this->employee();
        $employee->update(['last_name' => 'NGUYEN VAN', 'first_name' => 'A']);
        $employee->info->update(['birthday' => '2000-01-01', 'gender' => 'male', 'nationality' => 'vietnam']);

        $this->scanAndSubmit($employee);

        $detail = $this->detail($employee);
        $detail->assertOk();
        $this->assertSame(AdminScanFieldComparator::VERDICT_EMPLOYEE_DATA_MISSING, $detail->json('data.verdict'));
        $this->assertTrue($detail->json('data.employee_residence_missing'));

        $this->assertSame('NGUYEN VAN A', $this->field($detail, 'name')['employee']);
        $this->assertSame(AdminScanFieldComparator::FIELD_MATCH, $this->field($detail, 'name')['verdict']);
        $this->assertNull($this->field($detail, 'residence_number')['employee']);
        $this->assertNotNull($detail->json('data.admin_scan'), 'the admin side still comes back');
    }

    public function testANameMismatchOnAnEmployeeWithNoCardReportsBothFacts(): void
    {
        $employee = $this->employee();
        $employee->update(['last_name' => 'TRAN THI', 'first_name' => 'B']);

        $this->scanAndSubmit($employee);

        $detail = $this->detail($employee);
        $this->assertSame(AdminScanFieldComparator::VERDICT_MISMATCHED, $detail->json('data.verdict'));
        $this->assertTrue($detail->json('data.employee_residence_missing'), 'the missing card is not swallowed');
    }

    public function testAProfileMissingOnlyABirthdayLeavesThatFieldUnknownWithoutMismatching(): void
    {
        $employee = $this->employeeMatchingTheScan();
        $employee->info->update(['birthday' => null]);

        $this->scanAndSubmit($employee);

        $detail = $this->detail($employee);
        $this->assertSame(AdminScanFieldComparator::VERDICT_MATCHED, $detail->json('data.verdict'));
        $this->assertSame(AdminScanFieldComparator::FIELD_UNKNOWN, $this->field($detail, 'birthday')['verdict']);
        $this->assertFalse($detail->json('data.employee_residence_missing'));
    }

    public function testAResidenceNumberTypedInLowerCaseOnTheProfileStillMatches(): void
    {
        $employee = $this->employeeMatchingTheScan(['residence_number_1' => 'ab12345678cd']);

        $this->scanAndSubmit($employee);

        $this->assertSame(AdminScanFieldComparator::VERDICT_MATCHED, $this->detail($employee)->json('data.verdict'));
    }

    public function testAScannedCardMatchingASupersededSlotIsFlaggedNotReportedAsUnknown(): void
    {
        // Two renewals: the card the admin is holding now sits in slot 1.
        $employee = $this->employeeMatchingTheScan([
            'residence_number_1' => 'AB12345678CD',
            'residence_expiry_date_1' => '2024-01-01',
            'residence_status_1' => 'study_abroad',
            'residence_number_2' => 'EF87654321GH',
            'residence_expiry_date_2' => '2027-01-01',
            'residence_status_2' => 'study_abroad',
            'residence_number_3' => 'IJ11112222KL',
            'residence_expiry_date_3' => '2031-01-01',
            'residence_status_3' => 'study_abroad',
        ]);

        $this->scanAndSubmit($employee);

        $detail = $this->detail($employee);
        $row = $this->field($detail, 'residence_number');
        $this->assertSame(AdminScanFieldComparator::FIELD_MISMATCH, $row['verdict']);
        $this->assertSame(1, $row['matches_previous_slot']);
        $this->assertSame(
            ResidenceSlotResolver::latestSlot($employee->info->refresh()),
            $detail->json('data.current_slot'),
            'the compared slot is whatever the resolver says, never a hard-coded number'
        );
    }

    public function testACardInNoSlotIsAPlainMismatch(): void
    {
        $employee = $this->employeeMatchingTheScan(['residence_number_1' => 'ZZ99999999ZZ']);

        $this->scanAndSubmit($employee);

        $row = $this->field($this->detail($employee), 'residence_number');
        $this->assertSame(AdminScanFieldComparator::FIELD_MISMATCH, $row['verdict']);
        $this->assertNull($row['matches_previous_slot']);
    }

    public function testAProfileChangeFlipsTheVerdictWithNoAdminActionInBetween(): void
    {
        $employee = $this->employeeMatchingTheScan();
        $this->scanAndSubmit($employee);
        $this->assertSame(AdminScanFieldComparator::VERDICT_MATCHED, $this->detail($employee)->json('data.verdict'));

        // The employee renews: the old card rotates down a slot, a new one becomes current.
        $employee->info->update([
            'residence_number_2' => 'EF87654321GH',
            'residence_expiry_date_2' => '2035-01-01',
            'residence_status_2' => 'study_abroad',
        ]);

        $detail = $this->detail($employee);
        $this->assertSame(
            AdminScanFieldComparator::VERDICT_MISMATCHED,
            $detail->json('data.verdict'),
            'the verdict is computed on read, never cached'
        );
        $this->assertSame(1, $this->field($detail, 'residence_number')['matches_previous_slot']);
    }

    public function testTheReadSurfacesNeverQueryTheEmployeeOcrResults(): void
    {
        $employee = $this->employeeMatchingTheScan();
        $this->scanAndSubmit($employee);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($this->viewer, 'api')->getJson('/admin/paper-reconciliation')->assertOk();
        $this->detail($employee)->assertOk();

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString(
                'user_paper_ocr_results',
                $sql,
                'the employee side is the profile; no OCR reading of an employee image takes part'
            );
        }
    }

    public function testHistoryKeepsEverySubmissionWithAFreshlyComputedVerdict(): void
    {
        $employee = $this->employeeMatchingTheScan();
        $this->scanAndSubmit($employee);

        $this->fakeAdminScanOcr(['residence_number' => 'ZZ99999999ZZ']);
        $this->scanAndSubmit($employee);

        $history = $this->detail($employee)->json('data.history');
        $this->assertCount(2, $history);
        $this->assertSame(AdminScanFieldComparator::VERDICT_MISMATCHED, $history[0]['verdict']);
        $this->assertSame(AdminScanFieldComparator::VERDICT_MATCHED, $history[1]['verdict']);
    }

    public function testAnUnknownOrNonEmployeeUserIs404(): void
    {
        $this->actingAs($this->viewer, 'api')->getJson('/admin/paper-reconciliation/999999')->assertStatus(404);

        $notAnEmployee = User::factory()->create();
        $this->actingAs($this->viewer, 'api')
            ->getJson("/admin/paper-reconciliation/{$notAnEmployee->id}")
            ->assertStatus(404);
    }

    private function scanAndSubmit(User $employee): void
    {
        $admin = $this->uploadingAdmin();
        [, $row] = $this->uploadAndRead($admin, $employee);
        $this->actingAs($admin, 'api')
            ->postJson("/admin/paper-uploads/{$row->id}/submit", $this->confirmPayload($row->refresh()))
            ->assertOk();
    }

    private function detail(User $employee): TestResponse
    {
        return $this->actingAs($this->viewer, 'api')->getJson("/admin/paper-reconciliation/{$employee->id}");
    }

    /**
     * @return array<string, mixed>
     */
    private function field(TestResponse $response, string $name): array
    {
        foreach ($response->json('data.fields') as $row) {
            if ($row['field'] === $name) {
                return $row;
            }
        }

        $this->fail("field {$name} missing from the response");
    }
}
