<?php

namespace Tests\Feature\Dashboard;

use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Models\CheckIn;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftReport;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\AssertsAggregateOnlyResponse;
use Tests\TestCase;

class GetKpisActionTest extends TestCase
{
    use AssertsAggregateOnlyResponse;
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-22 10:00:00');

        $this->workplace = Workplace::create([
            'id' => 1,
            'name' => 'WP1',
            'address' => 'A',
            'latitude' => 35.6,
            'longitude' => 139.6,
        ]);
        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => 1,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 0,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 0,
        ]);

        $shiftListPerm = Permission::create(['name' => 'shift.list', 'display_name' => 'Shift List']);
        Permission::create(['name' => 'work_history.list', 'display_name' => 'Work History']);

        $role = Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
        $role->permissions()->syncWithoutDetaching([$shiftListPerm->id]);

        $this->admin = User::factory()->create();
        $this->admin->addRole($role);
    }

    public function testActivePoolCountsDistinctCheckInUsers(): void
    {
        $workers = User::factory()->count(3)->create();

        foreach ($workers as $worker) {
            $shift = $this->createShift('2026-05-10');
            $su = $this->createApprovedShiftUser($shift, $worker);
            $this->createCheckIn($su, $shift, $worker);
        }

        // Duplicate check-in for same user should not double-count
        $shift2 = $this->createShift('2026-05-11');
        $su2 = $this->createApprovedShiftUser($shift2, $workers[0]);
        $this->createCheckIn($su2, $shift2, $workers[0]);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/kpis?window=90');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals(3, $data['active_pool']['value']);
    }

    public function testShowUpRateUsesAllApprovedAsDenominator(): void
    {
        $workers = User::factory()->count(4)->create();

        // 3 shows, 1 no-show (4 approved total)
        foreach ($workers as $i => $worker) {
            $shift = $this->createShift('2026-05-15');
            $su = $this->createApprovedShiftUser($shift, $worker);

            if ($i < 3) {
                $this->createCheckIn($su, $shift, $worker);
            }
        }

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/kpis?window=90');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals(75.0, $data['show_up_rate']['value']);
    }

    public function testNoShowRiskUsesShiftReportsIsAbsent(): void
    {
        $workers = User::factory()->count(5)->create();

        foreach ($workers as $i => $worker) {
            $shift = $this->createShift('2026-05-10');
            $su = $this->createApprovedShiftUser($shift, $worker);

            if ($i < 3) {
                $this->createCheckIn($su, $shift, $worker);
            } elseif ($i === 3) {
                // No check-in + shift_report.is_absent = true → no-show
                ShiftReport::create([
                    'shift_user_id' => $su->id,
                    'user_id' => $worker->id,
                    'is_absent' => true,
                    'date' => '2026-05-10',
                ]);
            }
            // i === 4: no check-in, no report → missing_unreported (not counted as no-show)
        }

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/kpis?window=90');

        $response->assertOk();
        $data = $response->json('data');

        // 1 no-show / 5 approved = 20%
        $this->assertEquals(20.0, $data['no_show_risk']['value']);
    }

    public function testShiftUsersIsAbsentDoesNotAffectMetrics(): void
    {
        $worker = User::factory()->create();
        $shift = $this->createShift('2026-05-10');
        $su = $this->createApprovedShiftUser($shift, $worker);
        $su->update(['is_absent' => true]); // worktime validation flag — should be ignored
        $this->createCheckIn($su, $shift, $worker);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/kpis?window=90');

        $response->assertOk();
        $data = $response->json('data');

        // Still counted: denominator = 1, shows = 1
        $this->assertEquals(100.0, $data['show_up_rate']['value']);
        $this->assertEquals(1, $data['active_pool']['value']);
    }

    public function testReturnsNullsWhenReliabilityWorkplacesEmpty(): void
    {
        // User without work_history.list
        $user = User::factory()->create();
        $role = Role::create(['name' => 'shift_only', 'display_name' => 'Shift Only']);
        $shiftPerm = Permission::where('name', 'shift.list')->first();
        $role->permissions()->syncWithoutDetaching([$shiftPerm->id]);
        $user->addRole($role, $this->workplace->id);

        $response = $this->actingAs($user, 'api')->getJson('/admin/dashboard/kpis?window=90');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertFalse($data['reliability_available']);
        $this->assertNull($data['active_pool']['value']);
        $this->assertNull($data['show_up_rate']['value']);
        $this->assertNull($data['no_show_risk']['value']);
        $this->assertStringContainsString('"by_workplace":{}', $response->getContent());
    }

    public function testDeltaCalculatesAgainstPriorWindow(): void
    {
        $workers = User::factory()->count(5)->create();

        // Current window (last 30d): 5 workers checked in
        foreach ($workers as $worker) {
            $shift = $this->createShift('2026-05-10');
            $su = $this->createApprovedShiftUser($shift, $worker);
            $this->createCheckIn($su, $shift, $worker);
        }

        // Prior window (30-60d ago): 3 workers checked in
        foreach ($workers->take(3) as $worker) {
            $shift = $this->createShift('2026-04-05');
            $su = $this->createApprovedShiftUser($shift, $worker);
            $this->createCheckIn($su, $shift, $worker);
        }

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/kpis?window=30');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals(5, $data['active_pool']['value']);
        $this->assertEquals(2, $data['active_pool']['delta']); // 5 - 3
    }

    public function testResponseShapeContainsNoUserIdentifyingFields(): void
    {
        $worker = User::factory()->create();
        $shift = $this->createShift('2026-05-10');
        $su = $this->createApprovedShiftUser($shift, $worker);
        $this->createCheckIn($su, $shift, $worker);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/kpis?window=90');

        $response->assertOk();
        $this->assertNoUserIdentifyingFields($response->json('data'));
    }

    public function testValidationRejectsInvalidWindow(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/kpis?window=45');

        $response->assertStatus(422);
    }

    public function testDailyCheckInsBreakdownIsReturned(): void
    {
        $workers = User::factory()->count(3)->create();

        foreach ($workers as $worker) {
            $shift = $this->createShift('2026-05-10');
            $su = $this->createApprovedShiftUser($shift, $worker);
            $this->createCheckIn($su, $shift, $worker);
        }

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/kpis?window=90');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('daily_check_ins', $data);
        $this->assertArrayHasKey('workplaces', $data['daily_check_ins']);
        $this->assertArrayHasKey('rows', $data['daily_check_ins']);

        $workplaces = $data['daily_check_ins']['workplaces'];
        $this->assertCount(1, $workplaces);
        $this->assertSame($this->workplace->id, $workplaces[0]['id']);
        $this->assertFalse($workplaces[0]['is_other']);

        // The selected window is 90 days; one row per day inclusive.
        $this->assertCount(90, $data['daily_check_ins']['rows']);

        // The day with check-ins should reflect the 3 distinct workers.
        $populated = collect($data['daily_check_ins']['rows'])->firstWhere('date', '2026-05-10');
        $this->assertSame(3, $populated['total']);
        $this->assertSame(3, $populated['by_workplace'][(string) $this->workplace->id]);
    }

    public function testCachedKpisRemainSerializableWithClassesDisabled(): void
    {
        config([
            'cache.default' => 'serialized-array',
            'cache.serializable_classes' => false,
            'cache.stores.serialized-array' => [
                'driver' => 'array',
                'serialize' => true,
            ],
        ]);
        Cache::forgetDriver('serialized-array');

        $worker = User::factory()->create();
        $shift = $this->createShift('2026-05-10');
        $shiftUser = $this->createApprovedShiftUser($shift, $worker);
        $this->createCheckIn($shiftUser, $shift, $worker);

        $uncachedResponse = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/kpis?window=90');
        $cachedResponse = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/kpis?window=90');

        $uncachedResponse->assertOk();
        $cachedResponse->assertOk();
        $this->assertSame($uncachedResponse->json('data'), $cachedResponse->json('data'));
        $this->assertStringNotContainsString('__PHP_Incomplete_Class', $cachedResponse->getContent());
    }

    public function testDailyCheckInsCapsAtTopNAndAggregatesOthers(): void
    {
        // The cap is 8; create 12 workplaces so 4 should be rolled up under Others.
        $workplaces = [];
        for ($i = 1; $i <= 12; $i++) {
            $wp = Workplace::create([
                'name' => 'Extra ' . $i,
                'address' => 'A',
                'latitude' => 35.6,
                'longitude' => 139.6,
            ]);
            $ws = WorkplaceShift::create([
                'workplace_id' => $wp->id,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'check_in_round_type' => 1,
                'check_in_round_minutes' => 0,
                'check_out_round_type' => 1,
                'check_out_round_minutes' => 0,
            ]);
            $workplaces[] = [$wp, $ws, $i];
        }

        // Generate volume per workplace = its index (so 1, 2, 3, …, 12). The top
        // 8 are workplaces 5..12; 1..4 should be rolled up under Others.
        foreach ($workplaces as [$wp, $ws, $volume]) {
            // One concrete Shift per workplace slot; the volume comes from its ShiftUsers.
            $shift = Shift::create([
                'workplace_id' => $wp->id,
                'workplace_shift_id' => $ws->id,
                'creator_id' => $this->admin->id,
                'date' => '2026-05-10',
                'adjusted_date' => '2026-05-10',
                'fixed_total' => 5,
                'temp_total' => 5,
                'max_registered_temp' => 10,
                'break_minutes' => 60,
            ]);
            for ($n = 0; $n < $volume; $n++) {
                $worker = User::factory()->create();
                $su = ShiftUser::create([
                    'shift_id' => $shift->id,
                    'user_id' => $worker->id,
                    'status' => ShiftUserStatus::Approved->value,
                    'is_fixed' => false,
                    'is_overtime_allowed' => false,
                    'is_absent' => false,
                ]);
                $this->createCheckIn($su, $shift, $worker);
            }
        }

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/kpis?window=90');

        $response->assertOk();
        $data = $response->json('data');

        $returnedWorkplaces = $data['daily_check_ins']['workplaces'];

        // 8 individual + 1 Others.
        $this->assertCount(9, $returnedWorkplaces);

        $others = collect($returnedWorkplaces)->firstWhere('is_other', true);
        $this->assertNotNull($others, 'Others bucket missing');
        $this->assertSame(0, $others['id']);

        $individual = collect($returnedWorkplaces)->where('is_other', false);
        $this->assertSame(8, $individual->count());
        // Top 8 by volume = workplaces with the highest volumes (Extra 5..12),
        // returned in alphabetical order so the chart legend reads stably.
        $this->assertSame(
            ['Extra 10', 'Extra 11', 'Extra 12', 'Extra 5', 'Extra 6', 'Extra 7', 'Extra 8', 'Extra 9'],
            $individual->pluck('name')->values()->all()
        );

        // The day with check-ins must include the rolled-up totals for
        // workplaces 1..4 (volume 1+2+3+4 = 10) under the Others key (id=0).
        $row = collect($data['daily_check_ins']['rows'])->firstWhere('date', '2026-05-10');
        $this->assertSame(10, $row['by_workplace']['0']);
        $this->assertSame(1 + 2 + 3 + 4 + 5 + 6 + 7 + 8 + 9 + 10 + 11 + 12, $row['total']);
    }

    public function testKpisAcceptCustomDateRange(): void
    {
        $worker = User::factory()->create();
        $shift = $this->createShift('2026-05-10');
        $su = $this->createApprovedShiftUser($shift, $worker);
        $this->createCheckIn($su, $shift, $worker);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/dashboard/kpis?start_date=2026-05-01&end_date=2026-05-15'
        );

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(15, $data['daily_check_ins']['rows']);
        $this->assertSame('2026-05-01', $data['daily_check_ins']['rows'][0]['date']);
        $this->assertSame('2026-05-15', $data['daily_check_ins']['rows'][14]['date']);
    }

    public function testKpisRejectsInvalidCustomRange(): void
    {
        // end before start
        $this->actingAs($this->admin, 'api')
            ->getJson('/admin/dashboard/kpis?start_date=2026-05-15&end_date=2026-05-01')
            ->assertStatus(422);

        // partial range
        $this->actingAs($this->admin, 'api')->getJson('/admin/dashboard/kpis?start_date=2026-05-15')->assertStatus(422);
    }

    // One concrete Shift per (date, slot): repeated calls for the same date reuse it so several workers
    // share the shift (the realistic model and what the shifts unique index now enforces).
    private function createShift(string $date): Shift
    {
        return Shift::firstOrCreate(
            [
                'workplace_id' => $this->workplace->id,
                'workplace_shift_id' => $this->workplaceShift->id,
                'date' => $date,
            ],
            [
                'creator_id' => $this->admin->id,
                'adjusted_date' => $date,
                'fixed_total' => 5,
                'temp_total' => 5,
                'max_registered_temp' => 10,
                'break_minutes' => 60,
            ]
        );
    }

    private function createApprovedShiftUser(Shift $shift, User $user): ShiftUser
    {
        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_fixed' => false,
            'is_overtime_allowed' => false,
            'is_absent' => false,
        ]);
    }

    private function createCheckIn(ShiftUser $su, Shift $shift, User $user): CheckIn
    {
        return CheckIn::create([
            'shift_user_id' => $su->id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'check_in_time' => $shift->date . ' 09:00:00',
        ]);
    }
}
