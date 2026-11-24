<?php

namespace Tests\Feature\User;

use App\Actions\Common\CheckIn\CoreCheckInAction;
use App\Actions\Common\CheckIn\CoreCheckOutAction;
use App\Actions\Common\CheckIn\NotifyAttendanceReasonAction;
use App\Actions\Manage\Shift\ToggleAbsentAction;
use App\Contracts\FaceRecognitionProviderInterface;
use App\Enums\NotificationType;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperType;
use App\Models\CheckIn;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserFace;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Services\FaceRecognition\Responses\VerifyResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AttendanceReasonApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workplace $workplace;

    private Shift $shift;

    private ShiftUser $shiftUser;

    private Role $shiftManagerRole;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['work.check_in.anti_spoofing_enabled' => true]);

        $provider = Mockery::mock(FaceRecognitionProviderInterface::class);
        $provider->shouldReceive('supportsRealtimeAntiSpoofing')->zeroOrMoreTimes()->andReturn(true);
        $provider
            ->shouldReceive('verify')
            ->zeroOrMoreTimes()
            ->andReturnUsing(
                fn() => new VerifyResponse(
                    verified: true,
                    userId: (string) $this->user->id,
                    confidence: 0.99,
                    boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
                )
            );
        $this->app->instance(FaceRecognitionProviderInterface::class, $provider);

        $this->user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $paperItem = UserPaperItem::create([
            'user_paper_id' => UserPaper::create(['user_id' => $this->user->id])->id,
            'type' => UserPaperType::PortraitImage->value,
            'file_name' => 'portrait.jpg',
            'file_path' => 'test/portrait.jpg',
            'approved_at' => now(),
        ]);
        UserFace::create([
            'user_id' => $this->user->id,
            'user_paper_item_id' => $paperItem->id,
            'vector_id' => 'test-vector-id',
            'provider' => config('services.face_recognition.provider'),
        ]);

        $this->workplace = Workplace::create([
            'name' => 'Reason Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
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
        $this->shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->user->id,
            'date' => '2026-06-22',
            'adjusted_date' => '2026-06-22',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        $this->shiftUser = ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $shiftPermission = Permission::create(['name' => 'shift.list', 'display_name' => 'List Shifts']);
        $this->shiftManagerRole = Role::create(['name' => 'shift_manager', 'display_name' => 'Shift Manager']);
        $this->shiftManagerRole->permissions()->attach($shiftPermission);
        Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testLateCheckInRequiresReasonAndSubmittingNotifiesWorkplaceShiftAdmins(): void
    {
        $workplaceAdmin = $this->registerShiftAdmin($this->workplace);
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $otherAdmin = $this->registerShiftAdmin($otherWorkplace);

        $checkInResponse = $this->checkInAt(9, 15);
        $checkInResponse
            ->assertOk()
            ->assertJsonPath('data.late_minutes', 15)
            ->assertJsonPath('data.late_reason_required', true)
            ->assertJsonPath('data.early_reason_required', false);
        $checkInId = $checkInResponse->json('data.id');

        $this->submitReason($checkInId, 'late', 'Train delay')
            ->assertOk()
            ->assertJsonPath('data.late_reason', 'Train delay')
            ->assertJsonPath('data.late_reason_required', false);

        $notifications = Notification::query()
            ->where('type', NotificationType::ATTENDANCE_REASON_SUBMITTED->value)
            ->get();
        $this->assertSame([$workplaceAdmin->id], $notifications->pluck('user_id')->all());
        $this->assertNotContains($otherAdmin->id, $notifications->pluck('user_id')->all());
        $this->assertEquals(
            [
                'shift_id' => $this->shift->id,
                'workplace_id' => $this->workplace->id,
                'check_in_id' => $checkInId,
                'employee_id' => $this->user->id,
                'reason_type' => 'late',
                'minutes' => 15,
                'reason' => 'Train delay',
            ],
            $notifications->first()->data
        );
        $this->assertStringContainsString('Train delay', $notifications->first()->content);
    }

    public function testNotificationBaseContentUsesBaseLocaleEvenWhenWorkerUsesAnotherLanguage(): void
    {
        $this->registerShiftAdmin($this->workplace);
        $this->user->update(['settings' => ['lang' => 'ne']]);
        $checkInId = $this->checkInAt(9, 15)->assertOk()->json('data.id');

        $this->submitReason($checkInId, 'late', 'Train delay')->assertOk();

        $baseLocale = config('app.base_locale');
        $notification = Notification::query()
            ->where('type', NotificationType::ATTENDANCE_REASON_SUBMITTED->value)
            ->firstOrFail();
        $this->assertSame(__('lang.attendance_reason.late.title', locale: $baseLocale), $notification->title);
        $translatedLocales = $notification->translations()->pluck('lang')->all();
        $this->assertContains('ne', $translatedLocales);
        $this->assertNotContains($baseLocale, $translatedLocales);
    }

    public function testMaxLengthReasonIsSavedAndNotificationShowsShortenedPreview(): void
    {
        $this->registerShiftAdmin($this->workplace);
        $reason = mb_substr(str_repeat('Xe buýt hỏng giữa đường, phải chờ xe thay thế. ', 20), 0, 499) . '!';
        $checkInId = $this->checkInAt(9, 15)->assertOk()->json('data.id');

        $this->submitReason($checkInId, 'late', $reason)->assertOk()->assertJsonPath('data.late_reason', $reason);

        $notification = Notification::query()
            ->where('type', NotificationType::ATTENDANCE_REASON_SUBMITTED->value)
            ->firstOrFail();
        $this->assertSame($reason, $notification->data['reason']);
        $this->assertStringContainsString(mb_substr($reason, 0, 40), $notification->content);
        $this->assertStringEndsWith('...', $notification->content);
        $this->assertStringNotContainsString($reason, $notification->content);
        $this->assertLessThanOrEqual(255, mb_strlen($notification->content));
    }

    public function testNotificationContentFitsItsColumnWithLongWorkplaceNameInEveryLanguage(): void
    {
        $this->registerShiftAdmin($this->workplace);
        $this->workplace->update(['name' => str_repeat('長い作業場名', 40)]);
        $reason = mb_substr(str_repeat('Xe buýt hỏng giữa đường, phải chờ xe thay thế. ', 20), 0, 499) . '!';
        $checkInId = $this->checkInAt(9, 15)->assertOk()->json('data.id');

        $this->submitReason($checkInId, 'late', $reason)->assertOk();

        $notification = Notification::query()
            ->where('type', NotificationType::ATTENDANCE_REASON_SUBMITTED->value)
            ->firstOrFail();
        $this->assertLessThanOrEqual(255, mb_strlen($notification->content));
        $translations = $notification->translations()->get();
        $this->assertNotEmpty($translations);
        foreach ($translations as $translation) {
            $this->assertLessThanOrEqual(255, mb_strlen($translation->content));
        }
    }

    public function testNotificationContentShowsShortReasonInFull(): void
    {
        $this->registerShiftAdmin($this->workplace);
        $checkInId = $this->checkInAt(9, 15)->assertOk()->json('data.id');

        $this->submitReason($checkInId, 'late', 'Train delay')->assertOk();

        $notification = Notification::query()
            ->where('type', NotificationType::ATTENDANCE_REASON_SUBMITTED->value)
            ->firstOrFail();
        $this->assertStringEndsWith('Train delay', $notification->content);
    }

    public function testShiftDetailTellsAdminsWhichReasonsAreStillExpected(): void
    {
        $admin = $this->registerShiftAdmin($this->workplace);
        $checkInId = $this->checkInAt(9, 15)->assertOk()->json('data.id');

        $this->actingAs($admin, 'api')
            ->getJson("/manage/shifts/{$this->shift->id}")
            ->assertOk()
            ->assertJsonPath('data.shift_users.0.check_in.late_minutes', 15)
            ->assertJsonPath('data.shift_users.0.check_in.late_reason_required', true)
            ->assertJsonPath('data.shift_users.0.check_in.early_reason_required', false)
            ->assertJsonMissingPath('data.shift_users.0.check_in.shift_user');

        $this->submitReason($checkInId, 'late', 'Train delay')->assertOk();

        $this->actingAs($admin, 'api')
            ->getJson("/manage/shifts/{$this->shift->id}")
            ->assertOk()
            ->assertJsonPath('data.shift_users.0.check_in.late_reason', 'Train delay')
            ->assertJsonPath('data.shift_users.0.check_in.late_reason_required', false);
    }

    public function testAbsentShiftUserIsNotAskedForReason(): void
    {
        $checkInId = $this->checkInAt(9, 15)->assertOk()->json('data.id');

        app(ToggleAbsentAction::class)($this->shiftUser->id);

        $this->actingAs($this->user, 'api')
            ->getJson("/user/check-in/history/{$checkInId}")
            ->assertOk()
            ->assertJsonPath('data.late_reason_required', false);
        $this->submitReason($checkInId, 'late', 'Train delay')
            ->assertStatus(400)
            ->assertJsonPath('error', 'ATTENDANCE_REASON_NOT_REQUIRED');
        $this->assertNull(CheckIn::query()->find($checkInId)->late_reason);
    }

    public function testReasonIsRolledBackWhenNotifyingAdminsFails(): void
    {
        $this->registerShiftAdmin($this->workplace);
        $checkInId = $this->checkInAt(9, 15)->assertOk()->json('data.id');
        $this->mock(
            NotifyAttendanceReasonAction::class,
            fn(MockInterface $mock) => $mock->shouldReceive('__invoke')->andThrow(new RuntimeException('Notify failed'))
        );

        $this->submitReason($checkInId, 'late', 'Train delay')->assertStatus(500);

        $this->assertNull(CheckIn::query()->find($checkInId)->late_reason);
        $this->assertSame(0, Notification::query()->count());
        $this->actingAs($this->user, 'api')
            ->getJson("/user/check-in/history/{$checkInId}")
            ->assertOk()
            ->assertJsonPath('data.late_reason_required', true);
    }

    public function testReasonIsNoLongerRequiredOutsideTheWindow(): void
    {
        config(['work.check_in.attendance_reason_window_day' => 7]);
        $checkInId = $this->checkInAt(9, 15)->assertOk()->json('data.id');
        $this->checkOutAt(17, 0)->assertOk()->assertJsonPath('data.early_reason_required', true);

        Carbon::setTestNow('2026-06-29 17:01:00');

        $this->actingAs($this->user, 'api')
            ->getJson("/user/check-in/history/{$checkInId}")
            ->assertOk()
            ->assertJsonPath('data.late_reason_required', false)
            ->assertJsonPath('data.early_reason_required', false);
        $this->submitReason($checkInId, 'late', 'Too old')
            ->assertStatus(400)
            ->assertJsonPath('error', 'ATTENDANCE_REASON_NOT_REQUIRED');
        $this->assertSame(0, Notification::query()->count());
    }

    public function testOnTimeCheckInDoesNotRequireReasonAndRejectsSubmission(): void
    {
        $checkInId = $this->checkInAt(8, 55)
            ->assertOk()
            ->assertJsonPath('data.late_minutes', 0)
            ->assertJsonPath('data.late_reason_required', false)
            ->json('data.id');

        $this->submitReason($checkInId, 'late', 'Not late')
            ->assertStatus(400)
            ->assertJsonPath('error', 'ATTENDANCE_REASON_NOT_REQUIRED');
        $this->assertNull(CheckIn::query()->find($checkInId)->late_reason);
    }

    public function testEarlyCheckOutRequiresReasonThroughFullFlow(): void
    {
        $this->registerShiftAdmin($this->workplace);
        $checkInId = $this->checkInAt(9, 0)->assertOk()->json('data.id');

        $this->checkOutAt(17, 30)
            ->assertOk()
            ->assertJsonPath('data.early_minutes', 30)
            ->assertJsonPath('data.early_reason_required', true)
            ->assertJsonPath('data.late_reason_required', false);

        $this->submitReason($checkInId, 'early_leave', 'Sick')
            ->assertOk()
            ->assertJsonPath('data.early_reason', 'Sick')
            ->assertJsonPath('data.early_reason_required', false);

        $this->assertSame(
            1,
            Notification::query()->where('type', NotificationType::ATTENDANCE_REASON_SUBMITTED->value)->count()
        );
    }

    public function testEarlyReasonCannotBeSubmittedBeforeCheckOut(): void
    {
        $checkInId = $this->checkInAt(9, 0)->assertOk()->json('data.id');

        $this->submitReason($checkInId, 'early_leave', 'Too soon')
            ->assertStatus(400)
            ->assertJsonPath('error', 'ATTENDANCE_REASON_NOT_REQUIRED');
    }

    public function testSecondSubmissionIsRejectedAndKeepsFirstReason(): void
    {
        $this->registerShiftAdmin($this->workplace);
        $checkInId = $this->checkInAt(9, 20)->assertOk()->json('data.id');
        $this->submitReason($checkInId, 'late', 'First')->assertOk();

        $this->submitReason($checkInId, 'late', 'Second')
            ->assertStatus(400)
            ->assertJsonPath('error', 'ATTENDANCE_REASON_ALREADY_SUBMITTED');

        $this->assertSame('First', CheckIn::query()->find($checkInId)->late_reason);
        $this->assertSame(
            1,
            Notification::query()->where('type', NotificationType::ATTENDANCE_REASON_SUBMITTED->value)->count()
        );
    }

    public function testRepeatedEarlyCheckOutKeepsSubmittedReasonAndDoesNotAskAgain(): void
    {
        $checkInId = $this->checkInAt(9, 0)->assertOk()->json('data.id');
        $this->checkOutAt(16, 0)->assertOk()->assertJsonPath('data.early_reason_required', true);
        $this->submitReason($checkInId, 'early_leave', 'Doctor')->assertOk();

        $this->checkOutAt(17, 0)
            ->assertOk()
            ->assertJsonPath('data.early_minutes', 60)
            ->assertJsonPath('data.early_reason', 'Doctor')
            ->assertJsonPath('data.early_reason_required', false);
    }

    public function testUnreportedEarlyCheckOutStillRequiresReasonAfterRepeatedCheckOut(): void
    {
        $this->checkInAt(9, 0)->assertOk();
        $this->checkOutAt(16, 0)->assertOk()->assertJsonPath('data.early_minutes', 120);

        $this->checkOutAt(17, 50)
            ->assertOk()
            ->assertJsonPath('data.early_minutes', 10)
            ->assertJsonPath('data.early_reason_required', true);
    }

    public function testCannotSubmitReasonForAnotherUsersCheckIn(): void
    {
        $checkInId = $this->checkInAt(9, 30)->assertOk()->json('data.id');
        $stranger = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        $this->actingAs($stranger, 'api')
            ->postJson("/user/check-in/{$checkInId}/reason", ['type' => 'late', 'reason' => 'x'])
            ->assertNotFound();
        $this->assertNull(CheckIn::query()->find($checkInId)->late_reason);
    }

    public function testSubmitReasonValidation(): void
    {
        $checkInId = $this->checkInAt(9, 30)->assertOk()->json('data.id');

        $this->submitReason($checkInId, 'absent', 'x')->assertUnprocessable();
        $this->submitReason($checkInId, 'late', '')->assertUnprocessable();
        $this->submitReason($checkInId, 'late', str_repeat('a', 501))->assertUnprocessable();
        $this->assertNull(CheckIn::query()->find($checkInId)->late_reason);
    }

    public function testNonNumericCheckInIdIsNotFound(): void
    {
        $this->submitReason(0, 'late', 'x')->assertNotFound();
        $this->actingAs($this->user, 'api')
            ->postJson('/user/check-in/abc/reason', ['type' => 'late', 'reason' => 'x'])
            ->assertNotFound();
        $this->actingAs($this->user, 'api')->getJson('/user/check-in/history/abc')->assertNotFound();
    }

    public function testSubmitReasonRequiresAuthentication(): void
    {
        $this->postJson('/user/check-in/1/reason', ['type' => 'late', 'reason' => 'x'])->assertUnauthorized();
    }

    public function testAdminProxyCheckInAndCheckOutDoNotRequireReason(): void
    {
        $admin = $this->registerShiftAdmin($this->workplace);
        $data = [
            'shift_user_id' => $this->shiftUser->id,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ];

        Carbon::setTestNow('2026-06-22 09:30:00');
        app(CoreCheckInAction::class)($data, $this->user->id, false, $admin->id);
        Carbon::setTestNow('2026-06-22 17:00:00');
        app(CoreCheckOutAction::class)($data, $this->user->id, false, $admin->id);

        $checkIn = CheckIn::query()->where('shift_user_id', $this->shiftUser->id)->firstOrFail();
        $this->assertSame(30, $checkIn->late_minutes);
        $this->assertSame(60, $checkIn->early_minutes);
        $this->assertSame($admin->id, $checkIn->checked_in_by);
        $this->assertSame($admin->id, $checkIn->checked_out_by);

        $this->submitReason($checkIn->id, 'late', 'x')
            ->assertStatus(400)
            ->assertJsonPath('error', 'ATTENDANCE_REASON_NOT_REQUIRED');
        $this->submitReason($checkIn->id, 'early_leave', 'x')
            ->assertStatus(400)
            ->assertJsonPath('error', 'ATTENDANCE_REASON_NOT_REQUIRED');
    }

    public function testHistoryAndDetailExposePendingReasonFlags(): void
    {
        $checkInId = $this->checkInAt(9, 10)->assertOk()->json('data.id');
        $this->checkOutAt(18, 0)->assertOk();

        $this->actingAs($this->user, 'api')
            ->getJson('/user/check-in/history?month=2026-06')
            ->assertOk()
            ->assertJsonPath('data.items.0.late_minutes', 10)
            ->assertJsonPath('data.items.0.late_reason_required', true)
            ->assertJsonPath('data.items.0.early_reason_required', false);

        $this->submitReason($checkInId, 'late', 'Bus')->assertOk();

        $this->actingAs($this->user, 'api')
            ->getJson("/user/check-in/history/{$checkInId}")
            ->assertOk()
            ->assertJsonPath('data.late_reason', 'Bus')
            ->assertJsonPath('data.late_reason_required', false);
    }

    public function testCurrentShiftExposesPendingLateReasonFlag(): void
    {
        $this->checkInAt(9, 15)->assertOk();

        $this->actingAs($this->user, 'api')
            ->getJson('/user/check-in/shift')
            ->assertOk()
            ->assertJsonPath('data.check_in.late_minutes', 15)
            ->assertJsonPath('data.check_in.late_reason_required', true)
            ->assertJsonPath('data.check_in.early_reason_required', false);
    }

    private function registerShiftAdmin(Workplace $workplace): User
    {
        $admin = User::factory()->create();
        $admin->addRole($this->shiftManagerRole, $workplace->id);

        return $admin;
    }

    private function checkInAt(int $hour, int $minute): TestResponse
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22')->setTime($hour, $minute));

        return $this->actingAs($this->user, 'api')->postJson('/user/check-in/start', [
            'shift_user_id' => $this->shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);
    }

    private function checkOutAt(int $hour, int $minute): TestResponse
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22')->setTime($hour, $minute));

        return $this->actingAs($this->user, 'api')->postJson('/user/check-in/end', [
            'shift_user_id' => $this->shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);
    }

    private function submitReason(int $checkInId, string $type, string $reason): TestResponse
    {
        return $this->actingAs($this->user, 'api')->postJson("/user/check-in/{$checkInId}/reason", [
            'type' => $type,
            'reason' => $reason,
        ]);
    }
}
