<?php

namespace Tests\Feature\Manage\WorkHistoryEditRequest;

use App\Contracts\FaceRecognitionProviderInterface;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperType;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Jobs\AutoCheckOut;
use App\Jobs\CollectWorkTime;
use App\Jobs\CreateShiftReport;
use App\Jobs\UpdateUserSalary;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserFace;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use App\Services\FaceRecognition\Responses\VerifyResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class ApprovalAfterCheckOutTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private Workplace $workplace;

    private User $employee;

    private ShiftUser $shiftUser;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('public');
        config(['work.check_in.anti_spoofing_enabled' => true]);

        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Staff']);

        $this->employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        WorkplaceUser::create(['user_id' => $this->employee->id, 'workplace_id' => $this->workplace->id]);
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->employee->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
        $this->shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $paperItem = UserPaperItem::create([
            'user_paper_id' => UserPaper::create(['user_id' => $this->employee->id])->id,
            'type' => UserPaperType::PortraitImage->value,
            'file_name' => 'portrait.jpg',
            'file_path' => 'test/portrait.jpg',
            'approved_at' => now(),
        ]);
        UserFace::create([
            'user_id' => $this->employee->id,
            'user_paper_item_id' => $paperItem->id,
            'vector_id' => 'test-vector-id',
            'provider' => config('services.face_recognition.provider'),
        ]);
        $faceProvider = Mockery::mock(FaceRecognitionProviderInterface::class);
        $faceProvider->shouldReceive('supportsRealtimeAntiSpoofing')->zeroOrMoreTimes()->andReturn(true);
        $faceProvider
            ->shouldReceive('verify')
            ->zeroOrMoreTimes()
            ->andReturn(
                new VerifyResponse(
                    verified: true,
                    userId: (string) $this->employee->id,
                    confidence: 0.99,
                    boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
                )
            );
        $this->app->instance(FaceRecognitionProviderInterface::class, $faceProvider);

        $this->adminA = User::factory()->create();
        $this->giveWorkplaceScopedPermission($this->adminA, PermissionName::WorkHistoryEdit, $this->workplace->id);
        $this->giveWorkplaceScopedPermission($this->adminA, PermissionName::ShiftCheckIn, $this->workplace->id);

        $this->adminB = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $this->adminB,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testApprovesCheckInFixAfterEmployeeChecksOutNormally(): void
    {
        $checkIn = $this->checkInAt('2026-05-10 09:00:00', '09:47');

        Carbon::setTestNow('2026-05-10 11:00:00');
        $requestId = $this->submitEditRequest($checkIn, [
            'check_in_time' => '09:00:00',
            'check_in_reason' => 'Lỗi máy',
        ]);

        Carbon::setTestNow('2026-05-10 18:02:00');
        $this->employeeChecksOut()->assertOk();
        $this->assertSame('2026-05-10 18:02:00', $checkIn->fresh()->check_out_time);

        Queue::fake();
        Carbon::setTestNow('2026-05-11 09:00:00');
        $this->approve($requestId)->assertOk();

        $checkIn->refresh();
        $this->assertSame('2026-05-10 09:00:00', $checkIn->check_in_time);
        $this->assertSame('2026-05-10 18:02:00', $checkIn->check_out_time);
        $this->assertSame('2026-05-10 09:00:00', $checkIn->check_in_time_rounded);
        $this->assertSame('2026-05-10 18:00:00', $checkIn->check_out_time_rounded);
        $this->assertSame(540, (int) $checkIn->work_minutes);
        $this->assertRequestStatus($requestId, WorkHistoryEditRequestStatus::Approved);
        Queue::assertPushed(CreateShiftReport::class);
        Queue::assertPushed(UpdateUserSalary::class);
        Queue::assertPushed(CollectWorkTime::class);
    }

    public function testApprovesCheckInFixAfterAutoCheckOut(): void
    {
        $checkIn = $this->checkInAt('2026-05-10 09:00:00', '09:47');

        Carbon::setTestNow('2026-05-10 11:00:00');
        $requestId = $this->submitEditRequest($checkIn, [
            'check_in_time' => '09:00:00',
            'check_in_reason' => 'Lỗi máy',
        ]);

        Carbon::setTestNow('2026-05-10 20:05:00');
        (new AutoCheckOut())->handle();
        $this->assertSame('2026-05-10 18:00:00', $checkIn->fresh()->check_out_time);

        Carbon::setTestNow('2026-05-11 09:00:00');
        $this->approve($requestId)->assertOk();

        $checkIn->refresh();
        $this->assertSame('2026-05-10 09:00:00', $checkIn->check_in_time);
        $this->assertSame('2026-05-10 18:00:00', $checkIn->check_out_time);
        $this->assertSame('2026-05-10 09:00:00', $checkIn->check_in_time_rounded);
        $this->assertSame(540, (int) $checkIn->work_minutes);
        $this->assertRequestStatus($requestId, WorkHistoryEditRequestStatus::Approved);
    }

    public function testRejectsCheckInFixThatWouldLandAfterTheActualCheckOut(): void
    {
        $checkIn = $this->checkInAt('2026-05-10 09:00:00', '09:00');

        Carbon::setTestNow('2026-05-10 11:00:00');
        $requestId = $this->submitEditRequest($checkIn, ['check_in_time' => '19:00:00', 'check_in_reason' => 'Sai ca']);

        Carbon::setTestNow('2026-05-10 18:02:00');
        $this->employeeChecksOut()->assertOk();

        $this->approve($requestId)
            ->assertStatus(409)
            ->assertJsonPath('error', 'WORK_HISTORY_EDIT_REQUEST_INVALID_TIME_ORDER');

        $checkIn->refresh();
        $this->assertSame('2026-05-10 09:00:00', $checkIn->check_in_time);
        $this->assertSame('2026-05-10 18:02:00', $checkIn->check_out_time);
        $this->assertRequestStatus($requestId, WorkHistoryEditRequestStatus::Pending);
    }

    public function testApprovesForgottenCheckOutFixWhileAutoCheckOutSkipsThePendingCheckIn(): void
    {
        $checkIn = $this->checkInAt('2026-05-10 09:00:00', '09:47');

        Carbon::setTestNow('2026-05-10 11:00:00');
        $checkInFixId = $this->submitEditRequest($checkIn, [
            'check_in_time' => '09:00:00',
            'check_in_reason' => 'Lỗi máy',
        ]);
        $this->approve($checkInFixId)->assertOk();

        Carbon::setTestNow('2026-05-10 18:30:00');
        $checkOutFixId = $this->submitEditRequest($checkIn, [
            'check_in_time' => '09:00:00',
            'check_out_date' => '2026-05-10',
            'check_out_time' => '17:30:00',
            'check_out_reason' => 'Quên chấm công ra',
            'break_minutes' => 15,
            'break_minutes_reason' => 'Nghỉ giữa ca',
        ]);

        Carbon::setTestNow('2026-05-10 20:05:00');
        (new AutoCheckOut())->handle();
        $checkIn->refresh();
        $this->assertNull($checkIn->check_out_time);

        Queue::fake();
        Carbon::setTestNow('2026-05-11 09:00:00');
        $this->approve($checkOutFixId)->assertOk();

        $checkIn->refresh();
        $this->assertSame('2026-05-10 09:00:00', $checkIn->check_in_time);
        $this->assertSame('2026-05-10 17:30:00', $checkIn->check_out_time);
        $this->assertSame('2026-05-10 17:30:00', $checkIn->check_out_time_rounded);
        $this->assertSame($this->adminA->id, (int) $checkIn->checked_out_by);
        $this->assertSame(15, (int) $checkIn->break_minutes);
        $this->assertSame(495, (int) $checkIn->work_minutes);
        $this->assertRequestStatus($checkInFixId, WorkHistoryEditRequestStatus::Approved);
        $this->assertRequestStatus($checkOutFixId, WorkHistoryEditRequestStatus::Approved);
        Queue::assertPushed(CreateShiftReport::class);
        Queue::assertPushed(UpdateUserSalary::class);
        Queue::assertPushed(CollectWorkTime::class);
    }

    public function testRejectsForgottenCheckOutFixWhenTheEmployeeLaterChecksOutThemselves(): void
    {
        $checkIn = $this->checkInAt('2026-05-10 09:00:00', '09:00');

        Carbon::setTestNow('2026-05-10 17:45:00');
        $requestId = $this->submitEditRequest($checkIn, [
            'check_in_time' => '09:00:00',
            'check_out_date' => '2026-05-10',
            'check_out_time' => '17:30:00',
            'check_out_reason' => 'Quên chấm công ra',
        ]);

        Carbon::setTestNow('2026-05-10 18:02:00');
        $this->employeeChecksOut()->assertOk();

        Carbon::setTestNow('2026-05-10 20:05:00');
        (new AutoCheckOut())->handle();

        $this->approve($requestId)
            ->assertStatus(409)
            ->assertJsonPath('error', 'WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED');

        $checkIn->refresh();
        $this->assertSame('2026-05-10 18:02:00', $checkIn->check_out_time);
        $this->assertRequestStatus($requestId, WorkHistoryEditRequestStatus::Pending);
    }

    private function checkInAt(string $now, string $time): CheckIn
    {
        Carbon::setTestNow($now);
        $this->actingAs($this->adminA, 'api')
            ->postJson("/manage/shifts/shift-users/{$this->shiftUser->id}/check-in", [
                'date' => '2026-05-10',
                'time' => $time,
            ])
            ->assertOk();

        return CheckIn::query()->where('shift_user_id', $this->shiftUser->id)->firstOrFail();
    }

    private function submitEditRequest(CheckIn $checkIn, array $data): int
    {
        $response = $this->actingAs($this->adminA, 'api')->patchJson("/manage/work-histories/{$checkIn->id}", [
            'check_in_date' => '2026-05-10',
            ...$data,
        ]);
        $response->assertStatus(201);

        return $response->json('data.id');
    }

    private function approve(int $requestId)
    {
        return $this->actingAs($this->adminB, 'api')->postJson(
            "/manage/work-history-edit-requests/{$requestId}/approve"
        );
    }

    private function employeeChecksOut()
    {
        return $this->actingAs($this->employee, 'api')->postJson('/user/check-in/end', [
            'shift_user_id' => $this->shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face_out.jpg'),
        ]);
    }

    private function assertRequestStatus(int $requestId, WorkHistoryEditRequestStatus $status): void
    {
        $this->assertDatabaseHas('work_history_edit_requests', ['id' => $requestId, 'status' => $status->value]);
    }
}
