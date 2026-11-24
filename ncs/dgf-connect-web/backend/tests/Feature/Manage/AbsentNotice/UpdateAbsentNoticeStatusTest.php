<?php

namespace Tests\Feature\Manage\AbsentNotice;

use App\Enums\AbsentNoticeStatus;
use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftAbsentNotice;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UpdateAbsentNoticeStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private ShiftAbsentNotice $notice;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-21 08:00:00');

        $this->manager = User::factory()->create();
        $permission = Permission::create([
            'name' => PermissionName::ShiftEdit->value,
            'display_name' => 'Shift Edit',
        ]);
        $this->manager->givePermission($permission, null);

        $this->notice = $this->createAbsentNotice();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(int $id): string
    {
        return "/manage/absent-notices/{$id}/status";
    }

    public function testMarkingProcessedSucceedsAndRecordsActor(): void
    {
        $response = $this->actingAs($this->manager, 'api')->patchJson($this->url($this->notice->id), [
            'status' => AbsentNoticeStatus::Processed->value,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('shift_absent_notices', [
            'id' => $this->notice->id,
            'status' => AbsentNoticeStatus::Processed->value,
            'processed_by_id' => $this->manager->id,
        ]);
        $this->assertNotNull($this->notice->fresh()->processed_at);
    }

    public function testRevertingToPendingClearsActor(): void
    {
        $this->notice->update([
            'status' => AbsentNoticeStatus::Processed->value,
            'processed_by_id' => $this->manager->id,
            'processed_at' => now(),
        ]);

        $response = $this->actingAs($this->manager, 'api')->patchJson($this->url($this->notice->id), [
            'status' => AbsentNoticeStatus::Pending->value,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('shift_absent_notices', [
            'id' => $this->notice->id,
            'status' => AbsentNoticeStatus::Pending->value,
            'processed_by_id' => null,
            'processed_at' => null,
        ]);
    }

    public function testInvalidStatusReturns422(): void
    {
        $response = $this->actingAs($this->manager, 'api')->patchJson($this->url($this->notice->id), [
            'status' => 'archived',
        ]);

        $response->assertStatus(422);
    }

    public function testMissingNoticeReturns404(): void
    {
        $response = $this->actingAs($this->manager, 'api')->patchJson($this->url(999999), [
            'status' => AbsentNoticeStatus::Processed->value,
        ]);

        $response->assertStatus(404);
    }

    public function testWithoutPermissionReturns403(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')->patchJson($this->url($this->notice->id), [
            'status' => AbsentNoticeStatus::Processed->value,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('shift_absent_notices', [
            'id' => $this->notice->id,
            'status' => AbsentNoticeStatus::Pending->value,
        ]);
    }

    private function createAbsentNotice(): ShiftAbsentNotice
    {
        $user = User::factory()->create();

        $workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => '2026-05-22',
            'adjusted_date' => '2026-05-22',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'status' => 'approved',
        ]);

        return ShiftAbsentNotice::create(['shift_user_id' => $shiftUser->id]);
    }
}
