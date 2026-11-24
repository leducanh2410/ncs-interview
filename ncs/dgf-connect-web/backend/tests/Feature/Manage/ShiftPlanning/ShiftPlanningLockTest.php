<?php

namespace Tests\Feature\Manage\ShiftPlanning;

use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;
use App\Models\Workplace;
use App\Services\Manage\ShiftPlanningLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers the Shift Planner cooperative-lock lifecycle: POST/DELETE /manage/shift-planning/lock
 * and the ShiftPlanningLockService renew/takeover paths behind them. Verifies acquire, the
 * 423 conflict (with is_me=false) when held by another user, confirmed force-takeover, owner
 * heartbeat renewal, permission gating, and explicit release. The controller previously had
 * ~8% line coverage (only the finalize path was hit) and renew()/takeover() were untested.
 */
class ShiftPlanningLockTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Workplace $workplace;

    private ShiftPlanningLockService $locks;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-18 08:00:00');

        $this->locks = app(ShiftPlanningLockService::class);

        $this->manager = $this->createPlanner('96001');

        $this->workplace = Workplace::create([
            'name' => 'Planner WP',
            'address' => 'Addr',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'min_rest_hours' => 9,
        ]);
    }

    protected function tearDown(): void
    {
        // Defensive: release any lock that may remain on the workplace (cache is per-test
        // under CACHE_STORE=array, so this is belt-and-braces).
        if ($lock = $this->locks->get($this->workplace->id)) {
            $this->locks->release($this->workplace->id, $lock['user_id']);
        }
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testAcquireReturnsLockWithIsMe(): void
    {
        $response = $this->lockAs($this->manager);

        $response->assertOk();
        $response->assertJsonPath('data.lock.is_me', true);
        $response->assertJsonPath('data.lock.user_id', $this->manager->id);
        $response->assertJsonPath('data.lock.name', $this->manager->name);
        $this->assertTrue($this->locks->isHeldBy($this->workplace->id, $this->manager->id));
    }

    public function testPermissionDeniedWithoutShiftEdit(): void
    {
        $unauthorized = User::factory()->create();

        $response = $this->actingAs($unauthorized, 'api')->postJson('/manage/shift-planning/lock', [
            'workplace_id' => $this->workplace->id,
        ]);

        $response->assertStatus(403);
    }

    public function testLockedWhenHeldByAnotherUser(): void
    {
        $other = $this->createPlanner('96002');
        $this->locks->acquire($this->workplace->id, $other->id, $other->name);

        $response = $this->lockAs($this->manager);

        $response->assertStatus(423);
        $response->assertJsonPath('error', ErrorCode::ShiftPlanningLocked->value);
        // The 423 conflict body carries the current holder's lock at `data` directly
        // (ResponseHelper::locked), not under `data.lock`.
        $response->assertJsonPath('data.user_id', $other->id);
        $response->assertJsonPath('data.is_me', false);
        // The contender did not steal the lock.
        $this->assertTrue($this->locks->isHeldBy($this->workplace->id, $other->id));
    }

    public function testForceTakeoverOverwritesHolder(): void
    {
        $other = $this->createPlanner('96003');
        $this->locks->acquire($this->workplace->id, $other->id, $other->name);

        $response = $this->lockAs($this->manager, force: true);

        $response->assertOk();
        $response->assertJsonPath('data.lock.is_me', true);
        $response->assertJsonPath('data.lock.user_id', $this->manager->id);
        $this->assertFalse($this->locks->isHeldBy($this->workplace->id, $other->id));
        $this->assertTrue($this->locks->isHeldBy($this->workplace->id, $this->manager->id));
    }

    public function testRenewHeartbeatExtendsLockForOwner(): void
    {
        $this->locks->acquire($this->workplace->id, $this->manager->id, $this->manager->name);
        $firstSeen = $this->locks->get($this->workplace->id);

        // Advance time and heartbeat: owner re-POSTs, hitting the renew() branch.
        Carbon::setTestNow(now()->addMinute());
        $response = $this->lockAs($this->manager);

        $response->assertOk();
        $response->assertJsonPath('data.lock.is_me', true);
        $response->assertJsonPath('data.lock.user_id', $this->manager->id);

        $after = $this->locks->get($this->workplace->id);
        $this->assertNotNull($after);
        // renew() preserves acquired_at, bumps last_activity_at, and the TTL refreshes.
        $this->assertSame($firstSeen['acquired_at'], $after['acquired_at']);
        $this->assertNotSame($firstSeen['last_activity_at'], $after['last_activity_at']);
    }

    public function testRenewRejectedForNonOwner(): void
    {
        $other = $this->createPlanner('96004');
        $this->locks->acquire($this->workplace->id, $other->id, $other->name);

        // Non-owner re-POSTs without force: must not silently take over via renew.
        $response = $this->lockAs($this->manager);

        $response->assertStatus(423);
        $this->assertTrue($this->locks->isHeldBy($this->workplace->id, $other->id));
    }

    public function testUnlockReleasesLock(): void
    {
        $this->locks->acquire($this->workplace->id, $this->manager->id, $this->manager->name);

        $response = $this->actingAs($this->manager, 'api')->deleteJson('/manage/shift-planning/lock', [
            'workplace_id' => $this->workplace->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.lock', null);
        $this->assertNull($this->locks->get($this->workplace->id));

        // Another user can now acquire it.
        $other = $this->createPlanner('96005');
        $this->assertTrue($this->locks->acquire($this->workplace->id, $other->id, $other->name));
    }

    // ---- helpers ----------------------------------------------------------

    private function lockAs(User $user, bool $force = false): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'api')->postJson(
            '/manage/shift-planning/lock',
            array_filter([
                'workplace_id' => $this->workplace->id,
                'force' => $force ?: null,
            ])
        );
    }

    private function createPlanner(string $staffNo): User
    {
        $planner = User::factory()->create(['staff_no' => $staffNo]);
        foreach ([PermissionName::ShiftList, PermissionName::ShiftCreate, PermissionName::ShiftEdit] as $name) {
            // firstOrCreate: several tests create a second planner in the same test run,
            // and permission names are globally unique.
            $permission = Permission::firstOrCreate(['name' => $name->value], ['display_name' => $name->value]);
            $planner->givePermission($permission, null);
        }

        return $planner;
    }
}
