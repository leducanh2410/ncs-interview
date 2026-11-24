<?php

namespace Tests\Feature\Manage\WorkHistoryEditRequest;

use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use App\Models\WorkHistoryEditRequest;
use App\Models\WorkHistoryEditRequestItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class RejectTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private Workplace $workplace;

    private User $requester;

    private CheckIn $checkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requester = User::factory()->create();
        $employee = User::factory()->create();

        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Staff']);
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->requester->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
        WorkplaceUser::create(['user_id' => $employee->id, 'workplace_id' => $this->workplace->id]);
        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $this->checkIn = CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $employee->id,
            'check_in_time' => '2026-05-10 09:00:00',
            'check_out_time' => '2026-05-10 18:00:00',
            'break_minutes' => 60,
            'work_minutes' => 480,
            'work_minutes_normal' => 480,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
    }

    private function createRequest(): WorkHistoryEditRequest
    {
        $request = WorkHistoryEditRequest::create([
            'check_in_id' => $this->checkIn->id,
            'workplace_id' => $this->workplace->id,
            'requested_by_id' => $this->requester->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
        WorkHistoryEditRequestItem::create([
            'work_history_edit_request_id' => $request->id,
            'field' => 'check_in_time',
            'old_value' => '2026-05-10 09:00:00',
            'new_value' => '2026-05-10 09:05:00',
            'reason' => 'Máy chấm công lỗi',
        ]);

        return $request;
    }

    public function testRequiresProcessPermission(): void
    {
        $manager = User::factory()->create();
        $request = $this->createRequest();

        $response = $this->actingAs($manager, 'api')->postJson(
            "/manage/work-history-edit-requests/{$request->id}/reject",
            ['reject_reason' => 'Không hợp lệ']
        );

        $response->assertForbidden();
    }

    public function testRequiresRejectReason(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $request = $this->createRequest();

        $response = $this->actingAs($manager, 'api')->postJson(
            "/manage/work-history-edit-requests/{$request->id}/reject",
            []
        );

        $response->assertStatus(422);
    }

    public function testDoesNotChangeCheckInOnRejection(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $request = $this->createRequest();

        $response = $this->actingAs($manager, 'api')->postJson(
            "/manage/work-history-edit-requests/{$request->id}/reject",
            ['reject_reason' => 'Giờ đề xuất không khớp camera chấm công']
        );

        $response->assertOk();
        $this->assertDatabaseHas('check_ins', [
            'id' => $this->checkIn->id,
            'check_in_time' => '2026-05-10 09:00:00',
        ]);
        $this->assertDatabaseHas('work_history_edit_requests', [
            'id' => $request->id,
            'status' => WorkHistoryEditRequestStatus::Rejected->value,
            'reject_reason' => 'Giờ đề xuất không khớp camera chấm công',
        ]);
    }

    public function testReturns409ForAlreadyResolvedRequest(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
        $request = $this->createRequest();
        $request->update(['status' => WorkHistoryEditRequestStatus::Rejected->value]);

        $response = $this->actingAs($manager, 'api')->postJson(
            "/manage/work-history-edit-requests/{$request->id}/reject",
            ['reject_reason' => 'Lần 2']
        );

        $response->assertStatus(409);
    }
}
