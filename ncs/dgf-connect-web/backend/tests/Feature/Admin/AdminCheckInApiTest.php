<?php

namespace Tests\Feature\Admin;

use App\Contracts\FaceRecognitionProviderInterface;
use App\Enums\ErrorCode;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperType;
use App\Models\CheckIn;
use App\Models\CheckInVerification;
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
use Mockery;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class AdminCheckInApiTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    private Shift $shift;

    private ShiftUser $shiftUser;

    private $faceRecognitionProvider;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['work.check_in.anti_spoofing_enabled' => true]);

        $this->faceRecognitionProvider = Mockery::mock(FaceRecognitionProviderInterface::class);
        $this->app->instance(FaceRecognitionProviderInterface::class, $this->faceRecognitionProvider);

        Permission::firstOrCreate(
            ['name' => PermissionName::ShiftCheckIn->value],
            ['display_name' => 'Shift Check In']
        );

        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role
            ->permissions()
            ->syncWithoutDetaching(Permission::where('name', PermissionName::ShiftCheckIn->value)->pluck('id')->all());

        $this->admin = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $this->admin->addRole($role);

        $this->employee = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => '12345',
        ]);

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->workplacePosition = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);

        $this->shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->admin->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $this->shiftUser = ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testAdminGetCheckInShiftWithStaffNo(): void
    {
        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/manage/check-in/shift?staff_no=' . $this->employee->staff_no
        );

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data'));
        $this->assertEquals($this->shiftUser->id, $response->json('data.id'));

        Carbon::setTestNow();
    }

    public function testGetCheckInShiftReturnsNullWhenNoShift(): void
    {
        $employee = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => '99999',
        ]);

        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $response = $this->actingAs($this->admin, 'api')->getJson(
            '/admin/manage/check-in/shift?staff_no=' . $employee->staff_no
        );

        $response->assertStatus(200);
        $this->assertNull($response->json('data'));

        Carbon::setTestNow();
    }

    public function testGetCheckInShiftFailsWithInvalidStaffNo(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/manage/check-in/shift?staff_no=nonexistent');

        $response->assertStatus(422);
    }

    public function testGetCheckInShiftForbiddenWithoutPermission(): void
    {
        $userWithoutPermission = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        $response = $this->actingAs($userWithoutPermission, 'api')->getJson(
            '/admin/manage/check-in/shift?staff_no=' . $this->employee->staff_no
        );

        $response->assertStatus(403);
    }

    public function testGetCheckInShiftVisibleToManagerScopedToThatWorkplace(): void
    {
        $manager = $this->makeScopedCheckInManager($this->workplace->id);
        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $response = $this->actingAs($manager, 'api')->getJson(
            '/admin/manage/check-in/shift?staff_no=' . $this->employee->staff_no
        );

        $response->assertOk();
        $this->assertEquals($this->shiftUser->id, $response->json('data.id'));

        Carbon::setTestNow();
    }

    public function testGetCheckInShiftHiddenFromManagerScopedToAnotherWorkplace(): void
    {
        $manager = $this->makeScopedCheckInManager($this->makeOtherWorkplace()->id);
        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $response = $this->actingAs($manager, 'api')->getJson(
            '/admin/manage/check-in/shift?staff_no=' . $this->employee->staff_no
        );

        $response->assertOk();
        $this->assertNull($response->json('data'));

        Carbon::setTestNow();
    }

    public function testAdminCheckInForbiddenWhenWorkplaceOutOfScope(): void
    {
        $manager = $this->makeScopedCheckInManager($this->makeOtherWorkplace()->id);
        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $response = $this->actingAs($manager, 'api')->postJson('/admin/manage/check-in/start', [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => $this->shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(403)->assertJsonPath('error', ErrorCode::CheckInWorkplaceOutOfScope->value);
        $this->assertDatabaseMissing('check_ins', ['shift_user_id' => $this->shiftUser->id]);

        Carbon::setTestNow();
    }

    public function testAdminCheckOutForbiddenWhenWorkplaceOutOfScope(): void
    {
        $manager = $this->makeScopedCheckInManager($this->makeOtherWorkplace()->id);
        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(18, 5));

        $response = $this->actingAs($manager, 'api')->postJson('/admin/manage/check-in/end', [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => $this->shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(403)->assertJsonPath('error', ErrorCode::CheckInWorkplaceOutOfScope->value);

        Carbon::setTestNow();
    }

    public function testAdminCheckInSucceedsForManagerScopedToThatWorkplace(): void
    {
        $manager = $this->makeScopedCheckInManager($this->workplace->id);
        $this->mockEmployeeFaceVerified();
        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $response = $this->actingAs($manager, 'api')->postJson('/admin/manage/check-in/start', [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => $this->shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(200)->assertJsonPath('data.user_id', $this->employee->id);
        $this->assertDatabaseHas('check_ins', [
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->employee->id,
        ]);

        Carbon::setTestNow();
    }

    public function testAdminCheckOutSucceedsForManagerScopedToThatWorkplace(): void
    {
        $manager = $this->makeScopedCheckInManager($this->workplace->id);
        $this->mockEmployeeFaceVerified();
        CheckIn::create([
            'shift_id' => $this->shift->id,
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->employee->id,
            'check_in_time' => now()->setDateFrom($this->shift->date)->setTime(9, 0)->format('Y-m-d H:i:s'),
            'check_in_coordinate' => '35.6895,139.6917',
        ]);
        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(18, 5));

        $response = $this->actingAs($manager, 'api')->postJson('/admin/manage/check-in/end', [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => $this->shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face_out.jpg'),
        ]);

        $response->assertStatus(200)->assertJsonPath('data.user_id', $this->employee->id);
        $this->assertNotNull($response->json('data.check_out_time'));

        Carbon::setTestNow();
    }

    public function testAdminProxyCheckInAndCheckOutAreAttributedToTheAdminAndRequireNoReason(): void
    {
        $manager = $this->makeScopedCheckInManager($this->workplace->id);
        $this->mockEmployeeFaceVerified();
        $payload = fn(string $image) => [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => $this->shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image($image),
        ];

        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 20));
        $this->actingAs($manager, 'api')->postJson('/admin/manage/check-in/start', $payload('in.jpg'))->assertOk();
        $this->faceRecognitionProvider
            ->shouldReceive('verify')
            ->once()
            ->andReturn(
                new VerifyResponse(
                    verified: true,
                    userId: (string) $this->employee->id,
                    confidence: 0.99,
                    boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
                )
            );
        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(17, 0));
        $this->actingAs($manager, 'api')->postJson('/admin/manage/check-in/end', $payload('out.jpg'))->assertOk();

        $checkIn = CheckIn::query()->where('shift_user_id', $this->shiftUser->id)->sole();
        $this->assertSame([$manager->id, $manager->id], [$checkIn->checked_in_by, $checkIn->checked_out_by]);
        $this->assertSame(20, $checkIn->late_minutes);
        $this->assertFalse($checkIn->isLateReasonRequired());
        $this->assertFalse($checkIn->isEarlyReasonRequired());
        $this->actingAs($this->employee, 'api')
            ->postJson("/user/check-in/{$checkIn->id}/reason", ['type' => 'late', 'reason' => 'x'])
            ->assertStatus(400)
            ->assertJsonPath('error', 'ATTENDANCE_REASON_NOT_REQUIRED');

        Carbon::setTestNow();
    }

    public function testGetCheckInShiftPrefersInScopeWorkplaceWhenEmployeeHasBoth(): void
    {
        $outOfScopeWorkplace = $this->makeOtherWorkplace();
        $outOfScopeShift = WorkplaceShift::create([
            'workplace_id' => $outOfScopeWorkplace->id,
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $outOfScopePosition = WorkplacePosition::create([
            'workplace_id' => $outOfScopeWorkplace->id,
            'name' => 'Staff',
        ]);
        $outOfScopeShiftRecord = Shift::create([
            'workplace_id' => $outOfScopeWorkplace->id,
            'workplace_shift_id' => $outOfScopeShift->id,
            'creator_id' => $this->admin->id,
            'date' => $this->shift->date,
            'adjusted_date' => $this->shift->date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        ShiftUser::create([
            'shift_id' => $outOfScopeShiftRecord->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $outOfScopePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $manager = $this->makeScopedCheckInManager($this->workplace->id);
        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $response = $this->actingAs($manager, 'api')->getJson(
            '/admin/manage/check-in/shift?staff_no=' . $this->employee->staff_no
        );

        $response->assertOk();
        $this->assertEquals($this->shiftUser->id, $response->json('data.id'));
        $this->assertEquals($this->workplace->id, $response->json('data.shift.workplace_id'));

        Carbon::setTestNow();
    }

    public function testGetCheckInShiftPicksEarlierStartWhenMultipleInScopeShiftsOverlap(): void
    {
        $earlierWorkplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $earlierShift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $earlierWorkplaceShift->id,
            'creator_id' => $this->admin->id,
            'date' => $this->shift->date,
            'adjusted_date' => $this->shift->date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        $earlierShiftUser = ShiftUser::create([
            'shift_id' => $earlierShift->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $manager = $this->makeScopedCheckInManager($this->workplace->id);
        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 30));

        $response = $this->actingAs($manager, 'api')->getJson(
            '/admin/manage/check-in/shift?staff_no=' . $this->employee->staff_no
        );

        $response->assertOk();
        $this->assertEquals($earlierShiftUser->id, $response->json('data.id'));

        Carbon::setTestNow();
    }

    public function testAdminCheckInForbiddenWhenShiftUserIsMissing(): void
    {
        $manager = $this->makeScopedCheckInManager($this->workplace->id);
        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $response = $this->actingAs($manager, 'api')->postJson('/admin/manage/check-in/start', [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => 999999,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(403)->assertJsonPath('error', ErrorCode::CheckInWorkplaceOutOfScope->value);

        Carbon::setTestNow();
    }

    public function testAdminCheckInNotFoundWhenShiftUserIsMissingForGlobalAdmin(): void
    {
        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/manage/check-in/start', [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => 999999,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(404)->assertJsonPath('error', ErrorCode::CheckInShiftNotFound->value);

        Carbon::setTestNow();
    }

    public function testAdminCheckInNotFoundWhenShiftUserIsMissingForDirectGlobalPermission(): void
    {
        $manager = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $permission = Permission::where('name', PermissionName::ShiftCheckIn->value)->firstOrFail();
        $manager->givePermission($permission, null);

        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $response = $this->actingAs($manager, 'api')->postJson('/admin/manage/check-in/start', [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => 999999,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(404)->assertJsonPath('error', ErrorCode::CheckInShiftNotFound->value);

        Carbon::setTestNow();
    }

    public function testGlobalAdminAssignedToAnotherWorkplaceCanStillCheckIn(): void
    {
        $role = Role::where('name', RoleName::GlobalAdmin->value)->first();
        $admin = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $admin->addRole($role, $this->makeOtherWorkplace()->id);

        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $get = $this->actingAs($admin, 'api')->getJson(
            '/admin/manage/check-in/shift?staff_no=' . $this->employee->staff_no
        );
        $get->assertOk();
        $this->assertEquals($this->shiftUser->id, $get->json('data.id'));

        $this->mockEmployeeFaceVerified();

        $post = $this->actingAs($admin, 'api')->postJson('/admin/manage/check-in/start', [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => $this->shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $post->assertStatus(200)->assertJsonPath('data.user_id', $this->employee->id);
        $this->assertDatabaseHas('check_ins', [
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->employee->id,
        ]);

        Carbon::setTestNow();
    }

    public function testAdminCheckInSuccess(): void
    {
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

        $verifyResponse = new VerifyResponse(
            verified: true,
            userId: (string) $this->employee->id,
            confidence: 0.99,
            boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
        );

        $this->faceRecognitionProvider
            ->shouldReceive('supportsRealtimeAntiSpoofing')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $this->faceRecognitionProvider->shouldReceive('verify')->once()->andReturn($verifyResponse);

        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/manage/check-in/start', [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => $this->shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.user_id', $this->employee->id)
            ->assertJsonPath('data.shift_id', $this->shift->id);

        $this->assertDatabaseHas('check_ins', [
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->employee->id,
        ]);

        Carbon::setTestNow();
    }

    public function testAdminCheckInSuccessWithoutCoordinates(): void
    {
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

        $verifyResponse = new VerifyResponse(
            verified: true,
            userId: (string) $this->employee->id,
            confidence: 0.99,
            boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
        );

        $this->faceRecognitionProvider
            ->shouldReceive('supportsRealtimeAntiSpoofing')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $this->faceRecognitionProvider->shouldReceive('verify')->once()->andReturn($verifyResponse);

        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/manage/check-in/start', [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => $this->shiftUser->id,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(200)->assertJsonPath('data.user_id', $this->employee->id);

        $this->assertDatabaseHas('check_ins', [
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->employee->id,
            'check_in_coordinate' => null,
        ]);

        Carbon::setTestNow();
    }

    public function testAdminCheckInForbiddenWithoutPermission(): void
    {
        $userWithoutPermission = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(9, 5));

        $response = $this->actingAs($userWithoutPermission, 'api')->postJson('/admin/manage/check-in/start', [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => $this->shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(403);

        Carbon::setTestNow();
    }

    public function testAdminCheckOutSuccess(): void
    {
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

        CheckIn::create([
            'shift_id' => $this->shift->id,
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->employee->id,
            'check_in_time' => now()->setDateFrom($this->shift->date)->setTime(9, 0)->format('Y-m-d H:i:s'),
            'check_in_coordinate' => '35.6895,139.6917',
        ]);

        $verifyResponse = new VerifyResponse(
            verified: true,
            userId: (string) $this->employee->id,
            confidence: 0.99,
            boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
        );

        $this->faceRecognitionProvider
            ->shouldReceive('supportsRealtimeAntiSpoofing')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $this->faceRecognitionProvider->shouldReceive('verify')->once()->andReturn($verifyResponse);

        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(18, 5));

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/manage/check-in/end', [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => $this->shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face_out.jpg'),
        ]);

        $response->assertStatus(200)->assertJsonPath('data.user_id', $this->employee->id);

        $this->assertNotNull($response->json('data.check_out_time'));

        Carbon::setTestNow();
    }

    public function testAdminCheckOutSuccessWithoutCoordinates(): void
    {
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

        CheckIn::create([
            'shift_id' => $this->shift->id,
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->employee->id,
            'check_in_time' => now()->setDateFrom($this->shift->date)->setTime(9, 0)->format('Y-m-d H:i:s'),
            'check_in_coordinate' => null,
        ]);

        $verifyResponse = new VerifyResponse(
            verified: true,
            userId: (string) $this->employee->id,
            confidence: 0.99,
            boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
        );

        $this->faceRecognitionProvider
            ->shouldReceive('supportsRealtimeAntiSpoofing')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $this->faceRecognitionProvider->shouldReceive('verify')->once()->andReturn($verifyResponse);

        Carbon::setTestNow(now()->setDateFrom($this->shift->date)->setTime(18, 5));

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/manage/check-in/end', [
            'staff_no' => $this->employee->staff_no,
            'shift_user_id' => $this->shiftUser->id,
            'image' => UploadedFile::fake()->image('face_out.jpg'),
        ]);

        $response->assertStatus(200)->assertJsonPath('data.user_id', $this->employee->id);

        $this->assertNotNull($response->json('data.check_out_time'));

        $this->assertDatabaseHas('check_ins', [
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->employee->id,
            'check_out_coordinate' => null,
        ]);

        Carbon::setTestNow();
    }

    public function testGetSpoofedCheckInsSuccess(): void
    {
        $spoofPermission = Permission::firstOrCreate(
            ['name' => PermissionName::CheckInSpoofList->value],
            ['display_name' => 'Check In Spoof List']
        );
        $role = Role::where('name', RoleName::GlobalAdmin->value)->first();
        $role->permissions()->syncWithoutDetaching([$spoofPermission->id]);

        $checkIn = CheckIn::create([
            'shift_id' => $this->shift->id,
            'shift_user_id' => $this->shiftUser->id,
            'user_id' => $this->employee->id,
            'check_in_time' => now()->setDateFrom($this->shift->date)->setTime(9, 0)->format('Y-m-d H:i:s'),
        ]);

        CheckInVerification::create([
            'check_in_id' => $checkIn->id,
            'is_check_in' => true,
            'image_path' => 'check-ins/test/face.jpg',
            'bounding_box' => [10, 10, 100, 100],
            'status' => 'verified',
            'is_spoofed' => true,
            'spoofed_confidence' => 0.95,
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/check-in/spoofed');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertTrue($response->json('data.data.0.is_spoofed'));
    }

    public function testGetSpoofedCheckInsForbiddenWithoutPermission(): void
    {
        $userWithoutPermission = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        $response = $this->actingAs($userWithoutPermission, 'api')->getJson('/admin/check-in/spoofed');

        $response->assertStatus(403);
    }

    public function testGetSpoofedCheckInsReturnsShortenedNameForSriLankaUser(): void
    {
        $spoofPermission = Permission::firstOrCreate(
            ['name' => PermissionName::CheckInSpoofList->value],
            ['display_name' => 'Check In Spoof List']
        );
        $role = Role::where('name', RoleName::GlobalAdmin->value)->first();
        $role->permissions()->syncWithoutDetaching([$spoofPermission->id]);

        $sriLankaEmployee = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
            'paper_status' => UserPaperStatus::Approved->value,
        ]);
        $sriLankaEmployee->info->update(['nationality' => Nationality::SriLanka->value]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $sriLankaEmployee->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $checkIn = CheckIn::create([
            'shift_id' => $this->shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $sriLankaEmployee->id,
            'check_in_time' => now()->setDateFrom($this->shift->date)->setTime(9, 0)->format('Y-m-d H:i:s'),
        ]);

        CheckInVerification::create([
            'check_in_id' => $checkIn->id,
            'is_check_in' => true,
            'image_path' => 'check-ins/test/face.jpg',
            'bounding_box' => [10, 10, 100, 100],
            'status' => 'verified',
            'is_spoofed' => true,
            'spoofed_confidence' => 0.95,
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/check-in/spoofed');

        $response->assertStatus(200);
        $items = collect($response->json('data.data'));
        $item = $items->firstWhere('check_in.user_id', $sriLankaEmployee->id);
        $this->assertEquals('THARINDU RAVISHANN', $item['check_in']['user']['name']);
    }

    private function makeScopedCheckInManager(int $workplaceId): User
    {
        $manager = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $this->giveWorkplaceScopedPermission($manager, PermissionName::ShiftCheckIn, $workplaceId);

        return $manager;
    }

    private function mockEmployeeFaceVerified(): void
    {
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

        $verifyResponse = new VerifyResponse(
            verified: true,
            userId: (string) $this->employee->id,
            confidence: 0.99,
            boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
        );

        $this->faceRecognitionProvider
            ->shouldReceive('supportsRealtimeAntiSpoofing')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $this->faceRecognitionProvider->shouldReceive('verify')->once()->andReturn($verifyResponse);
    }

    private function makeOtherWorkplace(): Workplace
    {
        return Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
    }
}
