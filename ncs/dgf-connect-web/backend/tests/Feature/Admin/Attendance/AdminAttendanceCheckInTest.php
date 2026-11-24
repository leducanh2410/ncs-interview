<?php

namespace Tests\Feature\Admin\Attendance;

use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminAttendanceCheckInTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        config(['work.check_in.max_distance' => 200]);

        // GlobalAdmin resolves to all workplaces via getWorkplaceIdsByPermission (null).
        $role = $this->roleWithPermissions(RoleName::GlobalAdmin->value, 'Global Admin', [
            PermissionName::ManagerAuth->value,
            PermissionName::ManagerAttendanceCheckIn->value,
        ]);

        $this->admin = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $this->admin->addRole($role);

        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Address A',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
    }

    public function testCheckInSuccess(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.workplace_id', $this->workplace->id);

        $this->assertDatabaseHas('admin_attendance_sessions', [
            'user_id' => $this->admin->id,
            'workplace_id' => $this->workplace->id,
            'work_minutes' => null,
        ]);
        $this->assertDatabaseHas('admin_attendance_events', ['type' => 'check_in']);
    }

    public function testSubmitFailsWithoutAttendancePermission(): void
    {
        // A non-global Admin without manager.attendance.check_in is blocked by the route-level
        // permission gate before check-in/check-out resolution runs, so this is a 403 (not the
        // ADMIN_ATTENDANCE_NOT_ASSIGNED_WORKPLACE business error). One gate, one test — the merged
        // endpoint has no separate check-in/check-out permission path to verify independently.
        $adminRole = $this->roleWithPermissions(RoleName::Admin->value, 'Admin', [PermissionName::ManagerAuth->value]);
        $unassigned = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $unassigned->addRole($adminRole);

        $response = $this->actingAs($unassigned, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response->assertStatus(403);
    }

    public function testCheckInFailsWhenNoNearbyWorkplace(): void
    {
        $this->workplace->update(['latitude' => 0, 'longitude' => 0]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response->assertStatus(400)->assertJsonPath('error', ErrorCode::AdminAttendanceNoNearbyWorkplace->value);
    }

    public function testCheckInValidationFailsWithoutCoordinates(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', []);

        $response->assertStatus(422);
    }

    public function testCheckOutSuccessComputesWorkedHours(): void
    {
        Carbon::setTestNow(now()->setTime(9, 0));
        $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        Carbon::setTestNow(now()->setTime(11, 0));
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.work_minutes', 120);
        $this->assertDatabaseHas('admin_attendance_events', ['type' => 'check_out']);

        Carbon::setTestNow();
    }

    public function testSubmitWithNothingOpenAnywherePerformsACheckIn(): void
    {
        // With a single endpoint there is no way for the client to explicitly request "check
        // out" — the button only ever means "submit". When nothing is open at any nearby
        // workplace, `resolveCheckInOrOutTarget()` resolves this as a check-in rather than the
        // old two-endpoint API's ADMIN_ATTENDANCE_NOT_CHECKED_IN error for the wrong button.
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.workplace_id', $this->workplace->id);
        $this->assertDatabaseHas('admin_attendance_events', ['type' => 'check_in']);
    }

    public function testResubmitAfterCheckOutPerformsAFreshCheckInAtTheSameWorkplace(): void
    {
        // Per spec Requirement 8.10 the OLD two-endpoint API treated a repeat "/check-in/end"
        // call with no open interval as an audit-only no-op re-checkout. The merged endpoint has
        // no such call to make: once nothing is open, submitting again is unambiguously a fresh
        // check-in (reusing today's session row at this workplace), which reopens it rather than
        // silently no-op'ing.
        Carbon::setTestNow(now()->setTime(9, 0));
        $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        Carbon::setTestNow(now()->setTime(10, 0));
        $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        Carbon::setTestNow(now()->setTime(12, 0));
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        // The 12:00 interval is still open (uncredited), so work_minutes still only reflects the
        // closed 09:00->10:00 interval.
        $response->assertStatus(200)->assertJsonPath('data.work_minutes', 60);
        $this->assertDatabaseCount('admin_attendance_sessions', 1);
        $this->assertDatabaseCount('admin_attendance_events', 3);
        $this->assertDatabaseHas('admin_attendance_events', ['type' => 'check_in']);

        // Confirm the session is genuinely open again (not a closed audit-only entry) via the
        // read-only current-session lookup.
        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/attendance/current-session?workplace_id={$this->workplace->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $response->json('data.id'));

        Carbon::setTestNow();
    }

    public function testCheckInAtSecondWorkplaceWhileFirstStillOpen(): void
    {
        $workplaceB = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Address B',
            'latitude' => 34.0,
            'longitude' => 135.0,
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        // No check-out at A; check in at B (nearest to B coords).
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 34.0, 'longitude' => 135.0])
            ->assertStatus(200)
            ->assertJsonPath('data.workplace_id', $workplaceB->id);

        $this->assertDatabaseCount('admin_attendance_sessions', 2);
    }

    public function testCheckOutAfterMidnightClosesSessionOpenedThePreviousDay(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 23:50:00'));
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-07-05 00:10:00'));
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.work_minutes', 20);
        $this->assertDatabaseCount('admin_attendance_sessions', 1);

        Carbon::setTestNow();
    }

    public function testResubmitNextMorningWithNothingOpenChecksInWithoutTouchingYesterdaysClosedSession(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 09:00:00'));
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-07-04 17:00:00'));
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200)
            ->assertJsonPath('data.work_minutes', 480);

        // Next morning, still within 24h of yesterday's check-in, admin taps the (single) button
        // again before anything is open. With one endpoint this is unambiguous — a fresh
        // check-in for today — and it must not fall back to (and inflate) yesterday's
        // already-closed session.
        Carbon::setTestNow(Carbon::parse('2026-07-05 08:30:00'));
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.work_date', '2026-07-05')
            ->assertJsonPath('data.work_minutes', null);

        $this->assertDatabaseHas('admin_attendance_sessions', [
            'user_id' => $this->admin->id,
            'workplace_id' => $this->workplace->id,
            'work_date' => '2026-07-04',
            'work_minutes' => 480,
        ]);
        $this->assertDatabaseCount('admin_attendance_sessions', 2);

        Carbon::setTestNow();
    }

    public function testCheckOutPrefersWorkplaceWithOpenSessionOverNearerOne(): void
    {
        // Never checked into B, but B is nearer to the check-out coordinates than A.
        Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Address B',
            'latitude' => 35.68955,
            'longitude' => 139.69175,
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.68955,
            'longitude' => 139.69175,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.workplace_id', $this->workplace->id);
    }

    public function testPreviewNearbyReturnsWorkplaceWithinRange(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/attendance/check-in/nearby?latitude=35.6895&longitude=139.6917'
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.workplace.id', $this->workplace->id)
            ->assertJsonPath('data.within_range', true)
            ->assertJsonPath('data.max_distance_meters', 200);
        $this->assertSame(0, $response->json('data.distance_meters'));
    }

    public function testPreviewNearbyReturnsNearestWorkplaceOutOfRangeInsteadOfError(): void
    {
        // Far from the workplace (~hundreds of km away), but still assigned — the preview must
        // still resolve it (with within_range=false) rather than surfacing the "no nearby" error
        // that a real check-in would give; that error is reserved for the actual submit action.
        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/attendance/check-in/nearby?latitude=34.0&longitude=135.0'
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.workplace.id', $this->workplace->id)
            ->assertJsonPath('data.within_range', false);
        $this->assertGreaterThan(200, $response->json('data.distance_meters'));
    }

    public function testPreviewNearbyValidationFailsWithoutCoordinates(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/attendance/check-in/nearby');

        $response->assertStatus(422);
    }

    public function testPreviewNearbyFailsWithoutAttendancePermission(): void
    {
        $adminRole = $this->roleWithPermissions(RoleName::Admin->value, 'Admin', [PermissionName::ManagerAuth->value]);
        $unassigned = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $unassigned->addRole($adminRole);

        $response = $this->actingAs($unassigned, 'api')->getJson(
            '/admin/attendance/check-in/nearby?latitude=35.6895&longitude=139.6917'
        );

        $response->assertStatus(403);
    }

    public function testOvernightCheckOutWithinWindowCreditsOriginalWorkDateWithNoWarning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00'));
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-08-06 07:00:00'));
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.work_date', '2026-08-05')
            ->assertJsonPath('data.work_minutes', 600)
            ->assertJsonMissingPath('warning');

        $this->assertDatabaseCount('admin_attendance_sessions', 1);

        Carbon::setTestNow();
    }

    public function testLateCheckOutPastDeadlineConvertsToNewCheckInAndInvalidatesOriginalSession(): void
    {
        // Covers both former call sites: whether the admin's second tap used to mean "check out"
        // (this scenario) or "check in again" — the merged endpoint routes both through the same
        // `resolveCheckInOrOutTarget()` → persistCheckIn() path once the window has elapsed, so
        // there is nothing left to distinguish between them.
        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00'));
        $checkInResponse = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $originalSessionId = $checkInResponse->json('data.id');

        // Window closed at 08:00; this arrives 30 minutes late.
        Carbon::setTestNow(Carbon::parse('2026-08-06 08:30:00'));
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.work_date', '2026-08-06')
            ->assertJsonPath('data.last_check_out_at', null)
            ->assertJsonPath('data.work_minutes', null)
            ->assertJsonPath('warning.code', 'stale_session_invalidated')
            ->assertJsonPath('warning.invalidated_session_id', $originalSessionId)
            ->assertJsonPath('warning.workplace_id', $this->workplace->id);

        $this->assertDatabaseHas('admin_attendance_sessions', [
            'id' => $originalSessionId,
            'invalidated_reason' => 'late_checkout',
            'work_minutes' => null,
        ]);
        $this->assertDatabaseCount('admin_attendance_sessions', 2);

        Carbon::setTestNow();
    }

    public function testDaytimeCheckInPastMidnightCheckoutIsInvalidatedUnderTheSameDayOnlyRule(): void
    {
        // Under 24h has elapsed, but the same-day-only rule (check-in before 20:00) still
        // invalidates it — the case the old flat rolling-24h window handled incorrectly.
        Carbon::setTestNow(Carbon::parse('2026-08-05 15:00:00'));
        $checkInResponse = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $originalSessionId = $checkInResponse->json('data.id');

        Carbon::setTestNow(Carbon::parse('2026-08-06 00:01:00'));
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.work_date', '2026-08-06')
            ->assertJsonPath('warning.invalidated_session_id', $originalSessionId);

        $this->assertDatabaseHas('admin_attendance_sessions', [
            'id' => $originalSessionId,
            'invalidated_reason' => 'late_checkout',
        ]);

        Carbon::setTestNow();
    }

    public function testCheckoutDeadlineAtForEveningCheckInIsEightAmNextDay(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00'));
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.checkout_deadline_at', '2026-08-06T08:00:00+09:00')
            ->assertJsonPath('data.is_overnight', true);

        Carbon::setTestNow();
    }

    public function testCheckoutDeadlineAtForDaytimeCheckInIsMidnightNextDay(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 15:00:00'));
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.checkout_deadline_at', '2026-08-06T00:00:00+09:00')
            ->assertJsonPath('data.is_overnight', false);

        Carbon::setTestNow();
    }

    public function testCheckoutDeadlineAtBoundaryAtExactlyTwentyHoursResolvesToOvernightBranch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 20:00:00'));
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.checkout_deadline_at', '2026-08-06T08:00:00+09:00')
            ->assertJsonPath('data.is_overnight', true);

        Carbon::setTestNow();
    }

    public function testOvernightWindowAppliesToLatestCheckInNotFirstCheckInOfTheDay(): void
    {
        // The day's first check-in (09:00) is ordinary daytime, but a later same-day re-check-in
        // (23:00, after an earlier checkout) reopens the SAME session row (Requirement 8's
        // Checkpoint_Commit). The overnight grace must key off that latest check-in, not the
        // session's first_check_in_at, or a genuine night-shift continuation like this one would
        // be wrongly invalidated at 00:00 instead of getting the 08:00 deadline.
        Carbon::setTestNow(Carbon::parse('2026-08-05 09:00:00'));
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-08-05 23:00:00'));
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        // Deadline reflects the 23:00 re-check-in (08:00 next day), not the day's first check-in
        // at 09:00 (which alone would close at 00:00).
        $response->assertStatus(200)->assertJsonPath('data.checkout_deadline_at', '2026-08-06T08:00:00+09:00');

        Carbon::setTestNow(Carbon::parse('2026-08-06 03:00:00'));
        $checkOutResponse = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        // 09:00-10:00 (60 min) and 23:00-03:00 (240 min) are both credited to the same session; no
        // Stale_Session_Warning, since the window never actually elapsed. Credited to the
        // original 08-05 Work_Date (the day the shift started), not 08-06 (when it happened to
        // be closed) — a night shift's hours belong to the day it began, not the day it ended.
        $checkOutResponse
            ->assertStatus(200)
            ->assertJsonPath('data.work_date', '2026-08-05')
            ->assertJsonPath('data.work_minutes', 300)
            ->assertJsonMissingPath('warning');
        $this->assertDatabaseCount('admin_attendance_sessions', 1);

        Carbon::setTestNow();
    }

    public function testCurrentSessionReturnsOpenSessionWithCheckoutDeadlineWithinWindow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00'));
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-08-06 07:00:00'));
        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/admin/attendance/current-session?workplace_id={$this->workplace->id}"
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('next_action', 'check_out')
            ->assertJsonPath('data.workplace_id', $this->workplace->id)
            ->assertJsonPath('data.checkout_deadline_at', '2026-08-06T08:00:00+09:00')
            ->assertJsonPath('data.is_overnight', true)
            ->assertJsonPath('stale_session', null);

        Carbon::setTestNow();
    }

    public function testCurrentSessionReportsStaleSessionWhenOnlyOpenSessionHasExpired(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00'));
        $checkInResponse = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $sessionId = $checkInResponse->json('data.id');

        Carbon::setTestNow(Carbon::parse('2026-08-06 08:30:00'));
        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/admin/attendance/current-session?workplace_id={$this->workplace->id}"
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('next_action', 'check_in')
            ->assertJsonPath('data', null)
            ->assertJsonPath('stale_session.id', $sessionId)
            ->assertJsonPath('stale_session.workplace_id', $this->workplace->id)
            ->assertJsonPath('stale_session.checkout_deadline_at', '2026-08-06T08:00:00+09:00');

        Carbon::setTestNow();
    }

    public function testStaleSessionReportsTheReCheckInNotTheDaysFirstCheckInAfterCheckpointCommit(): void
    {
        // Checkpoint_Commit (Requirement 8): checkout+re-check-in reopen the SAME session, so
        // its first_check_in_at (09:00, daytime) and its latest check-in (21:00, overnight-
        // eligible) diverge. Once this session goes stale, every surface reporting "the check-in
        // that expired" — current-session's stale_session AND the next check-in's warning — must
        // point at 21:00, not 09:00, or they'd pair a daytime check-in with an overnight deadline.
        Carbon::setTestNow(Carbon::parse('2026-08-05 09:00:00'));
        $checkInResponse = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $sessionId = $checkInResponse->json('data.id');

        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00'));
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        // Past the 21:00 check-in's 08:00-next-day deadline; never checked out.
        Carbon::setTestNow(Carbon::parse('2026-08-06 08:30:00'));

        $currentSessionResponse = $this->actingAs($this->admin, 'api')->getJson(
            "/admin/attendance/current-session?workplace_id={$this->workplace->id}"
        );
        $currentSessionResponse
            ->assertStatus(200)
            ->assertJsonPath('stale_session.id', $sessionId)
            ->assertJsonPath('stale_session.first_check_in_at', '2026-08-05T21:00:00+09:00')
            ->assertJsonPath('stale_session.checkout_deadline_at', '2026-08-06T08:00:00+09:00');

        $checkInAgainResponse = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $checkInAgainResponse
            ->assertStatus(200)
            ->assertJsonPath('warning.invalidated_session_id', $sessionId)
            ->assertJsonPath('warning.invalidated_check_in_at', '2026-08-05T21:00:00+09:00');

        Carbon::setTestNow();
    }

    public function testCurrentSessionReturnsBothNullWhenNothingOpen(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/admin/attendance/current-session?workplace_id={$this->workplace->id}"
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('next_action', 'check_in')
            ->assertJsonPath('data', null)
            ->assertJsonPath('stale_session', null);
    }

    public function testCurrentSessionAtThreeAmWithNoPriorActivityShowsCheckIn(): void
    {
        // No check-in anywhere, ever, for this admin — opening the app at 03:00 (the hour a
        // genuine overnight shift would still be open) must not surface anything left over from
        // `recentSessions()`'s "since start of yesterday" candidate window; it must read as a
        // plain, confirmed check-in with no stale session either.
        Carbon::setTestNow(Carbon::parse('2026-08-06 03:00:00'));

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/admin/attendance/current-session?workplace_id={$this->workplace->id}"
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('next_action', 'check_in')
            ->assertJsonPath('data', null)
            ->assertJsonPath('stale_session', null);

        Carbon::setTestNow();
    }

    public function testCurrentSessionDoesNotReReportAnAlreadyInvalidatedStaleSession(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00'));
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        // Past the window: this checkout auto-invalidates the 08-05 session and opens a fresh
        // one for 08-06 instead.
        Carbon::setTestNow(Carbon::parse('2026-08-06 08:30:00'));
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        // Close the fresh 08-06 session normally, on time, so nothing is open anywhere.
        Carbon::setTestNow(Carbon::parse('2026-08-06 17:00:00'));
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/attendance/check-in-out', ['latitude' => 35.6895, 'longitude' => 139.6917])
            ->assertStatus(200);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/admin/attendance/current-session?workplace_id={$this->workplace->id}"
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('next_action', 'check_in')
            ->assertJsonPath('data', null)
            ->assertJsonPath('stale_session', null);

        $this->assertDatabaseCount('admin_attendance_sessions', 2);

        Carbon::setTestNow();
    }

    public function testCurrentSessionIsSideEffectFreeAndDoesNotInvalidateStaleSession(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00'));
        $checkInResponse = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $sessionId = $checkInResponse->json('data.id');

        Carbon::setTestNow(Carbon::parse('2026-08-06 08:30:00'));

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/attendance/current-session?workplace_id={$this->workplace->id}")
            ->assertStatus(200)
            ->assertJsonPath('stale_session.id', $sessionId);

        $this->assertDatabaseHas('admin_attendance_sessions', ['id' => $sessionId, 'invalidated_reason' => null]);

        // Calling it again reports the identical stale session — the warning persists rather
        // than flashing once, since a GET never resolves it.
        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/attendance/current-session?workplace_id={$this->workplace->id}")
            ->assertStatus(200)
            ->assertJsonPath('stale_session.id', $sessionId);

        $this->assertDatabaseHas('admin_attendance_sessions', ['id' => $sessionId, 'invalidated_reason' => null]);

        Carbon::setTestNow();
    }

    public function testCurrentSessionValidationFailsWithoutWorkplaceId(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/attendance/current-session');

        $response->assertStatus(422);
    }

    public function testCurrentSessionFailsWithoutAttendancePermission(): void
    {
        $adminRole = $this->roleWithPermissions(RoleName::Admin->value, 'Admin', [PermissionName::ManagerAuth->value]);
        $unassigned = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $unassigned->addRole($adminRole);

        $response = $this->actingAs($unassigned, 'api')->getJson(
            "/admin/attendance/current-session?workplace_id={$this->workplace->id}"
        );

        $response->assertStatus(403);
    }

    public function testStaleSessionMoreThanOneDayOldIsStillInvalidatedOnNextCheckIn(): void
    {
        // The Stale_Session lookup must not share `findOpenSession()`'s bounded "yesterday"
        // lookback: a check-in with a full day of no activity in between (e.g. a day off) must
        // still be found and invalidated on the next submission, however many days later.
        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00'));
        $checkInResponse = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $originalSessionId = $checkInResponse->json('data.id');

        // Nothing happens on 08-06 at all; the next submission arrives on 08-07.
        Carbon::setTestNow(Carbon::parse('2026-08-07 09:00:00'));
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.work_date', '2026-08-07')
            ->assertJsonPath('warning.code', 'stale_session_invalidated')
            ->assertJsonPath('warning.invalidated_session_id', $originalSessionId);

        $this->assertDatabaseHas('admin_attendance_sessions', [
            'id' => $originalSessionId,
            'invalidated_reason' => 'late_checkout',
        ]);
        $this->assertDatabaseCount('admin_attendance_sessions', 2);

        Carbon::setTestNow();
    }

    public function testCheckInInvalidatesStaleSessionAtFartherNearbyWorkplaceInsteadOfLeavingItDangling(): void
    {
        // Mirrors testCheckOutPrefersWorkplaceWithOpenSessionOverNearerOne, but for the stale
        // (rather than open) branch: the admin's next submission lands closer to A, which has no
        // session at all, while B (also in range) still has the dangling Stale_Session. The
        // resolver must check candidates in the same nearest-first order it uses for open
        // sessions, so it invalidates B instead of silently checking in at A and leaving B stale
        // forever.
        $workplaceB = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Address B',
            'latitude' => 35.68955,
            'longitude' => 139.69175,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00'));
        $checkInResponse = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.68955,
            'longitude' => 139.69175,
        ]);
        $checkInResponse->assertJsonPath('data.workplace_id', $workplaceB->id);
        $originalSessionId = $checkInResponse->json('data.id');

        // Past B's window; submit from a position exactly on A (nearer than B) with nothing open
        // or ever checked-in at A.
        Carbon::setTestNow(Carbon::parse('2026-08-06 08:30:00'));
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/attendance/check-in-out', [
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.workplace_id', $workplaceB->id)
            ->assertJsonPath('warning.code', 'stale_session_invalidated')
            ->assertJsonPath('warning.invalidated_session_id', $originalSessionId)
            ->assertJsonPath('warning.workplace_id', $workplaceB->id);

        $this->assertDatabaseHas('admin_attendance_sessions', [
            'id' => $originalSessionId,
            'invalidated_reason' => 'late_checkout',
        ]);
        $this->assertDatabaseMissing('admin_attendance_sessions', ['workplace_id' => $this->workplace->id]);

        Carbon::setTestNow();
    }

    private function roleWithPermissions(string $name, string $displayName, array $permissions): Role
    {
        $role = Role::firstOrCreate(['name' => $name], ['display_name' => $displayName]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['display_name' => $permission]);
        }
        $role->permissions()->syncWithoutDetaching(Permission::whereIn('name', $permissions)->pluck('id')->all());

        return $role;
    }
}
