<?php

namespace Tests\Feature\Admin\PaperReconciliation;

use App\Enums\PermissionName;
use App\Models\User;
use App\Models\Workplace;
use App\Support\Papers\AdminScanFieldComparator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsAdminPaperUploadFixtures;
use Tests\TestCase;

class PaperReconciliationListTest extends TestCase
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

    public function testAnEmployeeWithNoScanStillAppearsBecauseThatIsWhatTheListIsFor(): void
    {
        $employee = $this->employee();

        $response = $this->list();
        $response->assertOk();

        $row = $this->rowFor($response, $employee->id);
        $this->assertSame(AdminScanFieldComparator::VERDICT_NOT_UPLOADED, $row['verdict']);
        $this->assertNull($row['admin_scan']);
    }

    public function testDisabledAndNonEmployeeUsersAreNotListed(): void
    {
        $active = $this->employee();
        $disabled = $this->employee();
        $disabled->update(['is_disabled' => true]);
        $admin = User::factory()->create();

        $response = $this->list();

        $ids = collect($response->json('data.data'))->pluck('user.id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($disabled->id, $ids);
        $this->assertNotContains($admin->id, $ids);
    }

    public function testADateSelectsOnlyEmployeesRosteredThatDay(): void
    {
        $rostered = $this->employee();
        $elsewhere = $this->employee();
        $this->rosterOnto($rostered, '2026-09-20');
        $this->rosterOnto($elsewhere, '2026-09-21');

        $ids = collect($this->list(['date' => '2026-09-20'])->json('data.data'))
            ->pluck('user.id')
            ->all();

        $this->assertSame([$rostered->id], $ids);
    }

    public function testARejectedAssignmentNeverAppearsUnderAnyRosterStatus(): void
    {
        $employee = $this->employee();
        $this->rosterOnto($employee, '2026-09-20', 'rejected');

        foreach ([null, 'approved', 'pending'] as $status) {
            $filters = ['date' => '2026-09-20'];
            if ($status !== null) {
                $filters['shift_user_status'] = $status;
            }

            $ids = collect($this->list($filters)->json('data.data'))
                ->pluck('user.id')
                ->all();
            $this->assertNotContains(
                $employee->id,
                $ids,
                'a rejected assignment means the employee is not on that shift at all'
            );
        }
    }

    public function testTheDefaultRosterStatusReturnsApprovedAndPendingAndEachValueOnlyItsOwn(): void
    {
        $approved = $this->employee();
        $pending = $this->employee();
        $this->rosterOnto($approved, '2026-09-20', 'approved');
        $this->rosterOnto($pending, '2026-09-20', 'pending');

        $both = collect($this->list(['date' => '2026-09-20'])->json('data.data'))
            ->pluck('user.id')
            ->all();
        $this->assertEqualsCanonicalizing([$approved->id, $pending->id], $both);

        $onlyApproved = collect(
            $this->list(['date' => '2026-09-20', 'shift_user_status' => 'approved'])->json('data.data')
        )
            ->pluck('user.id')
            ->all();
        $this->assertSame([$approved->id], $onlyApproved);
    }

    public function testAWorkplaceWithoutADateFiltersByMembershipNotByRoster(): void
    {
        $member = $this->employee();
        $rosteredOnly = $this->employee();

        $shift = $this->rosterOnto($rosteredOnly, '2026-09-20');
        $member->workplaces()->attach($shift->workplace_id);

        $ids = collect($this->list(['workplace_id' => $shift->workplace_id])->json('data.data'))
            ->pluck('user.id')
            ->all();

        $this->assertContains($member->id, $ids, 'registered to the workplace but not rostered that day');
        $this->assertNotContains($rosteredOnly->id, $ids, 'rostered there but not registered');
    }

    public function testAShiftWithoutAWorkplaceAndDateIsRejected(): void
    {
        $this->list(['workplace_shift_id' => 1])->assertStatus(422);
        $this->list(['workplace_shift_id' => 1, 'date' => '2026-09-20'])->assertStatus(422);
    }

    public function testARosterStatusWithoutADateIsRejected(): void
    {
        $this->list(['shift_user_status' => 'approved'])->assertStatus(422);
    }

    public function testAVerdictFilterWithNothingToNarrowItIsRejected(): void
    {
        foreach (['matched', 'mismatched', 'not_comparable'] as $status) {
            $this->list(['status' => $status])->assertStatus(422);
            // Any one narrowing filter is enough.
            $this->list(['status' => $status, 'date' => '2026-09-20'])->assertOk();
        }
    }

    public function testEveryStatusValueReturnsExactlyTheEmployeesWhoseVerdictItIs(): void
    {
        $matched = $this->employeeMatchingTheScan();
        $this->scanAndSubmit($matched);

        $mismatched = $this->employeeMatchingTheScan(['residence_number_1' => 'ZZ99999999ZZ']);
        $this->scanAndSubmit($mismatched);

        // Agrees on everything the profile does hold, so the absent card is the only
        // reason it is not `matched` — a mismatch anywhere else would outrank it, which
        // is the rule, not an accident.
        $missing = $this->employee(['birthday' => '2000-01-01', 'gender' => 'male', 'nationality' => 'vietnam']);
        $missing->update(['last_name' => 'NGUYEN VAN', 'first_name' => 'A']);
        $this->scanAndSubmit($missing);

        $notUploaded = $this->employee();

        // The three verdict filters need something to narrow the candidate set, so every
        // employee joins one workplace and the whole sweep filters by it.
        $workplace = Workplace::factory()->create();
        foreach ([$matched, $mismatched, $missing, $notUploaded] as $employee) {
            $employee->workplaces()->attach($workplace->id);
        }

        $expected = [
            'matched' => [$matched->id],
            'mismatched' => [$mismatched->id],
            'employee_data_missing' => [$missing->id],
            'not_uploaded' => [$notUploaded->id],
            // Reachable, but only through the manual fallback with every field left
            // blank: the resolver always picks a slot that holds something, so a profile
            // with a card always has at least one field that either matches or does not.
            // Asserted here as "accepted and correctly empty".
            'not_comparable' => [],
            'uploaded' => [$matched->id, $mismatched->id, $missing->id],
        ];

        foreach ($expected as $status => $ids) {
            $response = $this->list(['status' => $status, 'workplace_id' => $workplace->id]);
            $response->assertOk();

            $actual = collect($response->json('data.data'))->pluck('user.id')->all();

            $this->assertEqualsCanonicalizing($ids, $actual, "status={$status}");
        }
    }

    public function testAnEmptyProfileWithNoAdminScanIsNotUploadedNotEmployeeDataMissing(): void
    {
        // The case a bare whereNull chain gets wrong: no residence card and no scan.
        // not_uploaded wins outright, because there is nothing to compare against.
        $employee = $this->employee();

        $missingIds = collect($this->list(['status' => 'employee_data_missing'])->json('data.data'))
            ->pluck('user.id')
            ->all();
        $this->assertNotContains($employee->id, $missingIds);

        $notUploadedIds = collect($this->list(['status' => 'not_uploaded'])->json('data.data'))
            ->pluck('user.id')
            ->all();
        $this->assertContains($employee->id, $notUploadedIds);
    }

    public function testTheListIsReadableOnlyWithTheViewPermission(): void
    {
        $this->actingAs($this->adminWithPermissions(), 'api')
            ->getJson('/admin/paper-reconciliation')
            ->assertStatus(403);
    }

    public function testEnrichmentQueryCountDoesNotGrowWithPageSize(): void
    {
        foreach (range(1, 6) as $ignored) {
            $this->scanAndSubmit($this->employeeMatchingTheScan());
        }

        $small = $this->countQueries(fn() => $this->list(['page_size' => 2])->assertOk());
        $large = $this->countQueries(fn() => $this->list(['page_size' => 6])->assertOk());

        $this->assertSame($small, $large, 'enrichment must be bounded, not one lookup per row');
    }

    public function testTheKeywordFindsAnEmployeeByTheFullNameTheListPrints(): void
    {
        // The name is split across two columns but read as one on screen, so the whole
        // name as typed off the row has to find the row it was typed from.
        $employee = $this->employee();
        $employee->update(['last_name' => 'NGUYEN VAN', 'first_name' => 'A', 'staff_no' => 'EMP-42']);
        $other = $this->employee();
        $other->update(['last_name' => 'TRAN', 'first_name' => 'B']);

        foreach (['NGUYEN VAN A', 'NGUYEN VAN', 'A', 'EMP-42'] as $keyword) {
            $ids = collect(
                $this->list(['keyword' => $keyword])
                    ->assertOk()
                    ->json('data.data')
            )
                ->pluck('user.id')
                ->all();

            $this->assertContains($employee->id, $ids, "keyword '{$keyword}' lost the employee it names");
        }

        $ids = collect($this->list(['keyword' => 'NGUYEN VAN A'])->json('data.data'))
            ->pluck('user.id')
            ->all();
        $this->assertNotContains($other->id, $ids);
    }

    public function testFilterOptionsOffersTheWorkplacesUnderTheReconciliationPermissionAlone(): void
    {
        // The viewer holds user.paper.list and nothing else: the workplace administration
        // list is closed to them, so these options have to come from here.
        $workplace = Workplace::factory()->create();

        $response = $this->actingAs($this->viewer, 'api')->getJson('/admin/paper-reconciliation/filter-options');

        $response->assertOk();
        $this->assertContains($workplace->id, collect($response->json('data.workplaces'))->pluck('id')->all());
        $this->assertSame(
            $workplace->name,
            collect($response->json('data.workplaces'))->firstWhere('id', $workplace->id)['name']
        );
        // Nothing picked yet, so there is no day at a workplace to read shifts from.
        $this->assertSame([], $response->json('data.workplace_shifts'));

        $this->actingAs($this->adminWithPermissions(), 'api')
            ->getJson('/admin/paper-reconciliation/filter-options')
            ->assertStatus(403);
    }

    public function testFilterOptionsOffersOnlyTheShiftsThatRanThatDay(): void
    {
        $employee = $this->employee();
        $shift = $this->rosterOnto($employee, '2026-09-20');
        $this->rosterOnto($this->employee(), '2026-09-21');

        $response = $this->actingAs($this->viewer, 'api')->getJson(
            "/admin/paper-reconciliation/filter-options?workplace_id={$shift->workplace_id}&date=2026-09-20"
        );

        $response->assertOk();
        $this->assertSame(
            [$shift->workplace_shift_id],
            collect($response->json('data.workplace_shifts'))->pluck('id')->all()
        );
        $this->assertSame('09:00-18:00', $response->json('data.workplace_shifts.0.label'));
        $this->assertContains($shift->workplace_id, collect($response->json('data.workplaces'))->pluck('id')->all());
    }

    public function testFilterOptionsTakesTheWorkplaceAndTheDateTogetherOrNotAtAll(): void
    {
        // A shift exists at one workplace on one day; half the pair asks for something
        // the roster cannot answer.
        $this->actingAs($this->viewer, 'api')
            ->getJson('/admin/paper-reconciliation/filter-options?workplace_id=1')
            ->assertStatus(422);
        $this->actingAs($this->viewer, 'api')
            ->getJson('/admin/paper-reconciliation/filter-options?date=2026-09-20')
            ->assertStatus(422);
    }

    private function scanAndSubmit(User $employee): void
    {
        $admin = $this->uploadingAdmin();
        [, $row] = $this->uploadAndRead($admin, $employee);
        $this->actingAs($admin, 'api')
            ->postJson("/admin/paper-uploads/{$row->id}/submit", $this->confirmPayload($row->refresh()))
            ->assertOk();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function list(array $filters = []): TestResponse
    {
        return $this->actingAs($this->viewer, 'api')->getJson(
            '/admin/paper-reconciliation?' . http_build_query($filters)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(TestResponse $response, int $userId): array
    {
        foreach ($response->json('data.data') as $row) {
            if ($row['user']['id'] === $userId) {
                return $row;
            }
        }

        $this->fail("employee {$userId} missing from the list");
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $callback();

        return $count;
    }
}
