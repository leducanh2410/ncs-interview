<?php

namespace Tests\Feature\Admin\Attendance;

use App\Enums\AdminAttendanceEventType;
use App\Enums\Nationality;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\AdminAttendanceSession;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use App\Services\Admin\AdminAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminAttendanceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    private User $adminA;

    private User $adminB;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        // Web history is restricted to GlobalAdmin via the role middleware.
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);

        $this->reviewer = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $this->reviewer->addRole($role);

        $this->adminA = User::factory()->create(['first_name' => 'AAA', 'staff_no' => '1001']);
        $this->adminB = User::factory()->create(['first_name' => 'BBB', 'staff_no' => '1002']);

        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Address A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
    }

    public function testDailySummaryReturnsOneRowPerAdminPerDay(): void
    {
        $today = now()->format('Y-m-d');
        $other = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Address B',
            'latitude' => 34.0,
            'longitude' => 135.0,
        ]);
        // adminA visited two workplaces today => still one summary row with workplace_count = 2.
        $this->makeSession($this->adminA, $today, 120);
        $this->makeSession($this->adminA, $today, 60, $other);
        // adminB visited one workplace today.
        $this->makeSession($this->adminB, $today, 90);

        $response = $this->actingAs($this->reviewer, 'api')->getJson('/admin/attendance/daily');

        $response->assertStatus(200);
        $rows = collect($response->json('data.data'));
        $this->assertCount(2, $rows); // one row per admin per day
        $a = $rows->firstWhere('user_id', $this->adminA->id);
        $this->assertSame(2, $a['workplace_count']);
        $this->assertSame('3.00', $a['total_hours']);
        $this->assertFalse($a['has_missing_checkout']);
    }

    public function testDailySummaryFlagsMissingCheckout(): void
    {
        $today = now()->format('Y-m-d');
        $this->makeSession($this->adminA, $today, null); // no check-out

        $response = $this->actingAs($this->reviewer, 'api')->getJson('/admin/attendance/daily');

        $response->assertStatus(200);
        $row = collect($response->json('data.data'))->firstWhere('user_id', $this->adminA->id);
        $this->assertTrue($row['has_missing_checkout']);
    }

    public function testDailySummaryFlagsMissingCheckoutAfterReCheckInWithoutCheckingOutAgain(): void
    {
        $today = now()->format('Y-m-d');
        $session = $this->makeSession($this->adminA, $today, 60); // check-in 9:00, check-out 10:00

        // Re-checked-in later the same day and never checked out again. This fixture seeds the
        // event log directly (bypassing AdminAttendanceService::recomputeDay), so it must also
        // set has_missing_checkout explicitly to the value the real interval/interruption state
        // machine (spec Requirement 8) would have persisted for this sequence — proving the
        // daily-summary query reads the persisted flag rather than re-deriving it from work_minutes.
        $session->events()->create([
            'type' => AdminAttendanceEventType::CheckIn,
            'event_time' => Carbon::parse($today)->setTime(10, 30)->format('Y-m-d H:i:s'),
            'coordinate' => '35.0,139.0',
        ]);
        $session->update(['has_missing_checkout' => true]);

        $response = $this->actingAs($this->reviewer, 'api')->getJson('/admin/attendance/daily');

        $response->assertStatus(200);
        $row = collect($response->json('data.data'))->firstWhere('user_id', $this->adminA->id);
        $this->assertTrue($row['has_missing_checkout']);
    }

    public function testDailySummaryCountsBothWorkplacesWhenOneIsLeftOpenByInterleavedCheckIns(): void
    {
        // Drives the real check-in/check-out pipeline (not the makeSession fixture) for:
        // A CI 09:00 -> B CI 10:00 -> A CO 11:00. A closes normally (2h credited); B is left
        // open/interrupted and never checked out. workplace_count must still be 2 — a session
        // row exists per workplace visited regardless of whether it was ever checked out.
        $service = app(AdminAttendanceService::class);
        $today = now()->format('Y-m-d');
        $other = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Address B',
            'latitude' => 34.0,
            'longitude' => 135.0,
        ]);

        $day = Carbon::parse($today);
        $service->persistCheckIn($this->adminA->id, $this->workplace->id, '35.0,139.0', $day->copy()->setTime(9, 0));
        $service->persistCheckIn($this->adminA->id, $other->id, '34.0,135.0', $day->copy()->setTime(10, 0));
        $sessionA = AdminAttendanceSession::query()
            ->where('user_id', $this->adminA->id)
            ->where('workplace_id', $this->workplace->id)
            ->firstOrFail();
        $service->persistCheckOut($sessionA, '35.0,139.0', $day->copy()->setTime(11, 0));

        $response = $this->actingAs($this->reviewer, 'api')->getJson('/admin/attendance/daily');

        $response->assertStatus(200);
        $row = collect($response->json('data.data'))->firstWhere('user_id', $this->adminA->id);
        $this->assertSame(2, $row['workplace_count']);
        $this->assertSame('2.00', $row['total_hours']);
        $this->assertTrue($row['has_missing_checkout']);
    }

    public function testDailySummaryFilterByUserAndDateRange(): void
    {
        $this->makeSession($this->adminA, now()->format('Y-m-d'), 120);
        $this->makeSession($this->adminB, now()->format('Y-m-d'), 60);
        $this->makeSession($this->adminA, now()->subDays(10)->format('Y-m-d'), 60);

        $from = now()->subDays(3)->format('Y-m-d');
        $to = now()->format('Y-m-d');
        $response = $this->actingAs($this->reviewer, 'api')->getJson(
            "/admin/attendance/daily?user_id={$this->adminA->id}&dates[]={$from}&dates[]={$to}"
        );

        $response->assertStatus(200);
        $rows = collect($response->json('data.data'));
        $this->assertCount(1, $rows);
        $this->assertSame($this->adminA->id, $rows->first()['user_id']);
    }

    public function testDayDetailReturnsSessionsWithEventLogs(): void
    {
        $today = now()->format('Y-m-d');
        $this->makeSession($this->adminA, $today, 120);
        $this->makeSession(
            $this->adminA,
            $today,
            null,
            Workplace::create([
                'name' => 'Workplace C',
                'address' => 'Address C',
                'latitude' => 33.0,
                'longitude' => 133.0,
            ])
        );

        $response = $this->actingAs($this->reviewer, 'api')->getJson(
            "/admin/attendance/day?user_id={$this->adminA->id}&date={$today}"
        );

        $response->assertStatus(200);
        $sessions = collect($response->json('data'));
        $this->assertCount(2, $sessions);
        $this->assertNotEmpty($sessions->first()['events']);
        // The page header needs the admin's name/staff_no, so the `user` relation must be loaded.
        $this->assertSame($this->adminA->id, $sessions->first()['user']['id']);
        $this->assertSame('1001', $sessions->first()['user']['staff_no']);
    }

    public function testDailySummaryShowsShortenedNameForSriLankaAdmin(): void
    {
        $this->adminA->update(['first_name' => 'Ragalage Tharindu Ravishann', 'last_name' => 'Karnasooriya']);
        $this->adminA->info->update(['nationality' => Nationality::SriLanka->value]);
        $this->makeSession($this->adminA, now()->format('Y-m-d'), 120);

        $response = $this->actingAs($this->reviewer, 'api')->getJson('/admin/attendance/daily');

        $response->assertStatus(200);
        $row = collect($response->json('data.data'))->firstWhere('user_id', $this->adminA->id);
        $this->assertSame('THARINDU RAVISHANN', $row['user']['name']);
    }

    public function testDayDetailShowsShortenedNameForSriLankaAdmin(): void
    {
        $this->adminA->update(['first_name' => 'Ragalage Tharindu Ravishann', 'last_name' => 'Karnasooriya']);
        $this->adminA->info->update(['nationality' => Nationality::SriLanka->value]);
        $today = now()->format('Y-m-d');
        $this->makeSession($this->adminA, $today, 120);

        $response = $this->actingAs($this->reviewer, 'api')->getJson(
            "/admin/attendance/day?user_id={$this->adminA->id}&date={$today}"
        );

        $response->assertStatus(200);
        $sessions = collect($response->json('data'));
        $this->assertSame('THARINDU RAVISHANN', $sessions->first()['user']['name']);
    }

    public function testDayDetailRequiresUserIdAndDate(): void
    {
        $response = $this->actingAs($this->reviewer, 'api')->getJson('/admin/attendance/day');

        $response->assertStatus(422);
    }

    public function testDailySummaryExcludesInvalidatedSessionFromMissingCheckoutWhileFlaggingTheDay(): void
    {
        // A is checked into and left open; B is checked into later the same day without closing
        // A — A becomes not-Current_Focus and is flagged has_missing_checkout=true immediately
        // (the same interleaved-check-in mechanic already covered in AdminAttendanceCheckInTest
        // and the interval calculator suite). Both workplaces' Valid_Checkout_Windows then
        // elapse overnight, and the next request at each workplace invalidates it (Requirement
        // 3) — reproducing a session that is simultaneously has_missing_checkout=true AND
        // invalidated, the exact double-count Requirement 4.2 excludes.
        $service = app(AdminAttendanceService::class);
        $other = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Address B',
            'latitude' => 34.0,
            'longitude' => 135.0,
        ]);

        $day = Carbon::parse('2026-08-05');
        $service->persistCheckIn($this->adminA->id, $this->workplace->id, '35.0,139.0', $day->copy()->setTime(9, 0));
        $service->persistCheckIn($this->adminA->id, $other->id, '34.0,135.0', $day->copy()->setTime(21, 0));

        $sessionA = AdminAttendanceSession::query()
            ->where('user_id', $this->adminA->id)
            ->where('workplace_id', $this->workplace->id)
            ->firstOrFail();
        // Precondition: before invalidation, A's dangling interval already reads as an ordinary
        // missing checkout — this is the value that must not double-count once A is invalidated.
        $this->assertTrue($sessionA->has_missing_checkout);

        $nextMorning = $day->copy()->addDay()->setTime(8, 30);
        $service->persistCheckIn($this->adminA->id, $this->workplace->id, '35.0,139.0', $nextMorning);
        $service->persistCheckIn($this->adminA->id, $other->id, '34.0,135.0', $nextMorning);

        // adminB: a genuine, unrelated missing checkout the same day — must still be counted.
        $this->makeSession($this->adminB, $day->format('Y-m-d'), null);

        $response = $this->actingAs($this->reviewer, 'api')->getJson(
            "/admin/attendance/daily?dates[]={$day->format('Y-m-d')}&dates[]={$day->format('Y-m-d')}"
        );

        $response->assertStatus(200);
        $rows = collect($response->json('data.data'));

        $rowA = $rows->firstWhere('user_id', $this->adminA->id);
        $this->assertFalse($rowA['has_missing_checkout']);
        $this->assertTrue($rowA['has_invalidated_session']);

        $rowB = $rows->firstWhere('user_id', $this->adminB->id);
        $this->assertTrue($rowB['has_missing_checkout']);
        $this->assertFalse($rowB['has_invalidated_session']);
    }

    public function testDayDetailIncludesInvalidatedReasonForAnInvalidatedSession(): void
    {
        $service = app(AdminAttendanceService::class);
        $day = Carbon::parse('2026-08-05');
        $service->persistCheckIn($this->adminA->id, $this->workplace->id, '35.0,139.0', $day->copy()->setTime(21, 0));

        $nextMorning = $day->copy()->addDay()->setTime(8, 30);
        $service->persistCheckIn($this->adminA->id, $this->workplace->id, '35.0,139.0', $nextMorning);

        $response = $this->actingAs($this->reviewer, 'api')->getJson(
            "/admin/attendance/day?user_id={$this->adminA->id}&date={$day->format('Y-m-d')}"
        );

        $response->assertStatus(200);
        $sessions = collect($response->json('data'));
        $this->assertCount(1, $sessions);
        $this->assertSame(
            AdminAttendanceService::INVALIDATED_REASON_LATE_CHECKOUT,
            $sessions->first()['invalidated_reason']
        );
    }

    public function testRoleGateBlocksNonGlobalAdmins(): void
    {
        $adminRole = Role::firstOrCreate(['name' => RoleName::Admin->value], ['display_name' => 'Admin']);
        $nonGlobal = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $nonGlobal->addRole($adminRole);

        $response = $this->actingAs($nonGlobal, 'api')->getJson('/admin/attendance/daily');

        $response->assertStatus(403);
    }

    private function makeSession(
        User $user,
        string $date,
        ?int $workMinutes,
        ?Workplace $workplace = null
    ): AdminAttendanceSession {
        $workplace = $workplace ?? $this->workplace;
        $checkInAt = Carbon::parse($date)->setTime(9, 0);

        $session = AdminAttendanceSession::create([
            'user_id' => $user->id,
            'workplace_id' => $workplace->id,
            'work_date' => $date,
            'first_check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
            'last_check_out_at' =>
                $workMinutes !== null ? $checkInAt->copy()->addMinutes($workMinutes)->format('Y-m-d H:i:s') : null,
            'work_minutes' => $workMinutes,
            // This fixture seeds sessions directly (bypassing AdminAttendanceService::recomputeDay),
            // so has_missing_checkout must be set explicitly to match: no check-out yet => missing.
            'has_missing_checkout' => $workMinutes === null,
        ]);

        $session->events()->create([
            'type' => AdminAttendanceEventType::CheckIn,
            'event_time' => $checkInAt->format('Y-m-d H:i:s'),
            'coordinate' => '35.0,139.0',
        ]);

        if ($workMinutes !== null) {
            $session->events()->create([
                'type' => AdminAttendanceEventType::CheckOut,
                'event_time' => $checkInAt->copy()->addMinutes($workMinutes)->format('Y-m-d H:i:s'),
                'coordinate' => '35.0,139.0',
            ]);
        }

        return $session;
    }
}
