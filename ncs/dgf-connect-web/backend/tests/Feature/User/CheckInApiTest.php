<?php

namespace Tests\Feature\User;

use App\Contracts\FaceRecognitionProviderInterface;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperType;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftReport;
use App\Models\ShiftUser;
use App\Models\SystemConfig;
use App\Models\User;
use App\Models\UserDevice;
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
use Tests\TestCase;

class CheckInApiTest extends TestCase
{
    use RefreshDatabase;

    private $faceRecognitionProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faceRecognitionProvider = Mockery::mock(FaceRecognitionProviderInterface::class);
        $this->app->instance(FaceRecognitionProviderInterface::class, $this->faceRecognitionProvider);
        Storage::fake('public');

        // Enable anti-spoofing for tests
        config(['work.check_in.anti_spoofing_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testCheckInSuccess()
    {
        /** @var User $user */
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
        ]);

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

        $workplacePosition = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $device = UserDevice::create([
            'user_id' => $user->id,
            'device_id' => 'device123',
            'name' => 'Test Device',
            'platform' => 'ios',
        ]);

        // Create a UserPaperItem for the UserFace
        $paperItem = UserPaperItem::create([
            'user_paper_id' => UserPaper::create([
                'user_id' => $user->id,
            ])->id,
            'type' => UserPaperType::PortraitImage->value,
            'file_name' => 'portrait.jpg',
            'file_path' => 'test/portrait.jpg',
            'approved_at' => now(),
        ]);

        // Create UserFace record for the user
        UserFace::create([
            'user_id' => $user->id,
            'user_paper_item_id' => $paperItem->id,
            'vector_id' => 'test-vector-id',
            'provider' => config('services.face_recognition.provider'),
        ]);

        // Mock face recognition verification result
        $verifyResponse = new VerifyResponse(
            verified: true,
            userId: (string) $user->id,
            confidence: 0.99,
            boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
        );

        $this->faceRecognitionProvider
            ->shouldReceive('supportsRealtimeAntiSpoofing')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $this->faceRecognitionProvider->shouldReceive('verify')->once()->andReturn($verifyResponse);

        // Adjust time to be within shift time for test
        Carbon::setTestNow(now()->setDateFrom($shift->date)->setTime(9, 5));

        $image = UploadedFile::fake()->image('face.jpg');

        $response = $this->actingAs($user, 'api')->postJson('/user/check-in/start', [
            'shift_user_id' => $shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => $image,
        ]);

        if ($response->status() !== 200) {
            dump($response->json());
        }

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.shift_id', $shift->id)
            ->assertJsonPath('data.is_yamato', false)
            ->assertJsonPath('data.yamato_url', null);

        $this->assertDatabaseHas('check_ins', [
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('check_in_verifications', [
            'status' => 'verified', // since supportsRealtimeAntiSpoofing is true
            'is_check_in' => true,
        ]);

        Carbon::setTestNow();
    }

    public function testCheckInReturnsYamatoUrlWhenWorkplaceIsYamato()
    {
        $loginUrl = 'https://kintai.matchbb.jp/staff/stampshift';
        SystemConfig::create([
            'max_hour_per_week' => 40,
            'max_shift_per_day' => 3,
            'salary_per_hour' => 1500,
            'login_url' => $loginUrl,
        ]);

        /** @var User $user */
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
        ]);

        $workplace = Workplace::create([
            'name' => 'Yamato Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'is_yamato' => true,
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

        $workplacePosition = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        UserDevice::create([
            'user_id' => $user->id,
            'device_id' => 'device123',
            'name' => 'Test Device',
            'platform' => 'ios',
        ]);

        $paperItem = UserPaperItem::create([
            'user_paper_id' => UserPaper::create(['user_id' => $user->id])->id,
            'type' => UserPaperType::PortraitImage->value,
            'file_name' => 'portrait.jpg',
            'file_path' => 'test/portrait.jpg',
            'approved_at' => now(),
        ]);

        UserFace::create([
            'user_id' => $user->id,
            'user_paper_item_id' => $paperItem->id,
            'vector_id' => 'test-vector-id',
            'provider' => config('services.face_recognition.provider'),
        ]);

        $verifyResponse = new VerifyResponse(
            verified: true,
            userId: (string) $user->id,
            confidence: 0.99,
            boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
        );

        $this->faceRecognitionProvider
            ->shouldReceive('supportsRealtimeAntiSpoofing')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $this->faceRecognitionProvider->shouldReceive('verify')->once()->andReturn($verifyResponse);

        Carbon::setTestNow(now()->setDateFrom($shift->date)->setTime(9, 5));

        $response = $this->actingAs($user, 'api')->postJson('/user/check-in/start', [
            'shift_user_id' => $shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.is_yamato', true)
            ->assertJsonPath('data.yamato_url', $loginUrl);

        Carbon::setTestNow();
    }

    public function testCheckInReturnsDefaultYamatoUrlWhenSystemConfigMissing()
    {
        /** @var User $user */
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
        ]);

        $workplace = Workplace::create([
            'name' => 'Yamato Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'is_yamato' => true,
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

        $workplacePosition = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        UserDevice::create([
            'user_id' => $user->id,
            'device_id' => 'device123',
            'name' => 'Test Device',
            'platform' => 'ios',
        ]);

        $paperItem = UserPaperItem::create([
            'user_paper_id' => UserPaper::create(['user_id' => $user->id])->id,
            'type' => UserPaperType::PortraitImage->value,
            'file_name' => 'portrait.jpg',
            'file_path' => 'test/portrait.jpg',
            'approved_at' => now(),
        ]);

        UserFace::create([
            'user_id' => $user->id,
            'user_paper_item_id' => $paperItem->id,
            'vector_id' => 'test-vector-id',
            'provider' => config('services.face_recognition.provider'),
        ]);

        $verifyResponse = new VerifyResponse(
            verified: true,
            userId: (string) $user->id,
            confidence: 0.99,
            boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
        );

        $this->faceRecognitionProvider
            ->shouldReceive('supportsRealtimeAntiSpoofing')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $this->faceRecognitionProvider->shouldReceive('verify')->once()->andReturn($verifyResponse);

        Carbon::setTestNow(now()->setDateFrom($shift->date)->setTime(9, 5));

        $response = $this->actingAs($user, 'api')->postJson('/user/check-in/start', [
            'shift_user_id' => $shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.is_yamato', true)
            ->assertJsonPath('data.yamato_url', config('yamato.login_url'));

        Carbon::setTestNow();
    }

    public function testCheckOutSuccess()
    {
        /** @var User $user */
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
        ]);

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

        $workplacePosition = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $device = UserDevice::create([
            'user_id' => $user->id,
            'device_id' => 'device123',
            'name' => 'Test Device',
            'platform' => 'ios',
        ]);

        // Create a UserPaperItem for the UserFace
        $paperItem = UserPaperItem::create([
            'user_paper_id' => UserPaper::create([
                'user_id' => $user->id,
            ])->id,
            'type' => UserPaperType::PortraitImage->value,
            'file_name' => 'portrait.jpg',
            'file_path' => 'test/portrait.jpg',
            'approved_at' => now(),
        ]);

        // Create UserFace record for the user
        UserFace::create([
            'user_id' => $user->id,
            'user_paper_item_id' => $paperItem->id,
            'vector_id' => 'test-vector-id',
            'provider' => config('services.face_recognition.provider'),
        ]);

        $checkIn = CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'check_in_time' => now()->setDateFrom($shift->date)->setTime(9, 0)->format('Y-m-d H:i:s'),
            'check_in_coordinate' => '35.6895,139.6917',
        ]);

        // Mock face recognition verification result
        $verifyResponse = new VerifyResponse(
            verified: true,
            userId: (string) $user->id,
            confidence: 0.99,
            boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
        );

        $this->faceRecognitionProvider
            ->shouldReceive('supportsRealtimeAntiSpoofing')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $this->faceRecognitionProvider->shouldReceive('verify')->once()->andReturn($verifyResponse);

        // Adjust time to be within shift time for test
        Carbon::setTestNow(now()->setDateFrom($shift->date)->setTime(18, 5));

        $image = UploadedFile::fake()->image('face_out.jpg');

        $response = $this->actingAs($user, 'api')->postJson('/user/check-in/end', [
            'shift_user_id' => $shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => $image,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.shift_id', $shift->id)
            ->assertJsonPath('data.is_yamato', false)
            ->assertJsonPath('data.yamato_url', null);

        $this->assertNotNull($response->json('data.check_out_time'));

        $this->assertDatabaseHas('check_ins', [
            'id' => $checkIn->id,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('check_in_verifications', [
            'check_in_id' => $checkIn->id,
            'is_check_in' => false,
            'status' => 'verified',
        ]);

        Carbon::setTestNow();
    }

    public function testCheckOutReturnsYamatoUrlWhenWorkplaceIsYamato()
    {
        $loginUrl = 'https://kintai.matchbb.jp/staff/stampshift';
        SystemConfig::create([
            'max_hour_per_week' => 40,
            'max_shift_per_day' => 3,
            'salary_per_hour' => 1500,
            'login_url' => $loginUrl,
        ]);

        /** @var User $user */
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
        ]);

        $workplace = Workplace::create([
            'name' => 'Yamato Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'is_yamato' => true,
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

        $workplacePosition = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        UserDevice::create([
            'user_id' => $user->id,
            'device_id' => 'device123',
            'name' => 'Test Device',
            'platform' => 'ios',
        ]);

        $paperItem = UserPaperItem::create([
            'user_paper_id' => UserPaper::create(['user_id' => $user->id])->id,
            'type' => UserPaperType::PortraitImage->value,
            'file_name' => 'portrait.jpg',
            'file_path' => 'test/portrait.jpg',
            'approved_at' => now(),
        ]);

        UserFace::create([
            'user_id' => $user->id,
            'user_paper_item_id' => $paperItem->id,
            'vector_id' => 'test-vector-id',
            'provider' => config('services.face_recognition.provider'),
        ]);

        $checkIn = CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'check_in_time' => now()->setDateFrom($shift->date)->setTime(9, 0)->format('Y-m-d H:i:s'),
            'check_in_coordinate' => '35.6895,139.6917',
        ]);

        $verifyResponse = new VerifyResponse(
            verified: true,
            userId: (string) $user->id,
            confidence: 0.99,
            boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
        );

        $this->faceRecognitionProvider
            ->shouldReceive('supportsRealtimeAntiSpoofing')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $this->faceRecognitionProvider->shouldReceive('verify')->once()->andReturn($verifyResponse);

        Carbon::setTestNow(now()->setDateFrom($shift->date)->setTime(18, 5));

        $response = $this->actingAs($user, 'api')->postJson('/user/check-in/end', [
            'shift_user_id' => $shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face_out.jpg'),
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.is_yamato', true)
            ->assertJsonPath('data.yamato_url', $loginUrl);

        Carbon::setTestNow();
    }

    public function testCheckOutReturnsDefaultYamatoUrlWhenSystemConfigMissing()
    {
        /** @var User $user */
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
        ]);

        $workplace = Workplace::create([
            'name' => 'Yamato Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'is_yamato' => true,
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

        $workplacePosition = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        UserDevice::create([
            'user_id' => $user->id,
            'device_id' => 'device123',
            'name' => 'Test Device',
            'platform' => 'ios',
        ]);

        $paperItem = UserPaperItem::create([
            'user_paper_id' => UserPaper::create(['user_id' => $user->id])->id,
            'type' => UserPaperType::PortraitImage->value,
            'file_name' => 'portrait.jpg',
            'file_path' => 'test/portrait.jpg',
            'approved_at' => now(),
        ]);

        UserFace::create([
            'user_id' => $user->id,
            'user_paper_item_id' => $paperItem->id,
            'vector_id' => 'test-vector-id',
            'provider' => config('services.face_recognition.provider'),
        ]);

        $checkIn = CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'check_in_time' => now()->setDateFrom($shift->date)->setTime(9, 0)->format('Y-m-d H:i:s'),
            'check_in_coordinate' => '35.6895,139.6917',
        ]);

        $verifyResponse = new VerifyResponse(
            verified: true,
            userId: (string) $user->id,
            confidence: 0.99,
            boundingBox: ['left' => 10, 'top' => 10, 'width' => 100, 'height' => 100]
        );

        $this->faceRecognitionProvider
            ->shouldReceive('supportsRealtimeAntiSpoofing')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $this->faceRecognitionProvider->shouldReceive('verify')->once()->andReturn($verifyResponse);

        Carbon::setTestNow(now()->setDateFrom($shift->date)->setTime(18, 5));

        $response = $this->actingAs($user, 'api')->postJson('/user/check-in/end', [
            'shift_user_id' => $shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face_out.jpg'),
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.is_yamato', true)
            ->assertJsonPath('data.yamato_url', config('yamato.login_url'));

        Carbon::setTestNow();
    }

    public function testCheckInFailsWhenFaceDoesNotMatch()
    {
        /** @var User $user */
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
        ]);

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

        $workplacePosition = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        UserDevice::create([
            'user_id' => $user->id,
            'device_id' => 'device123',
            'name' => 'Test Device',
            'platform' => 'ios',
        ]);

        // Create a UserPaperItem for the UserFace
        $paperItem = UserPaperItem::create([
            'user_paper_id' => UserPaper::create([
                'user_id' => $user->id,
            ])->id,
            'type' => UserPaperType::PortraitImage->value,
            'file_name' => 'portrait.jpg',
            'file_path' => 'test/portrait.jpg',
            'approved_at' => now(),
        ]);

        // Create UserFace record for the user
        UserFace::create([
            'user_id' => $user->id,
            'user_paper_item_id' => $paperItem->id,
            'vector_id' => 'test-vector-id',
            'provider' => config('services.face_recognition.provider'),
        ]);

        // Mock face recognition to return verification failure
        $verifyResponse = new VerifyResponse(
            verified: false, // Face does not match
            userId: (string) $user->id,
            confidence: 0.3
        );

        $this->faceRecognitionProvider
            ->shouldReceive('supportsRealtimeAntiSpoofing')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $this->faceRecognitionProvider->shouldReceive('verify')->once()->andReturn($verifyResponse);

        Carbon::setTestNow(now()->setDateFrom($shift->date)->setTime(9, 5));

        $image = UploadedFile::fake()->image('face.jpg');

        $response = $this->actingAs($user, 'api')->postJson('/user/check-in/start', [
            'shift_user_id' => $shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => $image,
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'CHECK_IN_FACE_VERIFICATION_FAILED');

        // Ensure no check-in record was created
        $this->assertDatabaseMissing('check_ins', [
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
        ]);

        Carbon::setTestNow();
    }

    public function testCheckOutFailsWhenFaceDoesNotMatch()
    {
        /** @var User $user */
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
        ]);

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

        $workplacePosition = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        UserDevice::create([
            'user_id' => $user->id,
            'device_id' => 'device123',
            'name' => 'Test Device',
            'platform' => 'ios',
        ]);

        // Create a UserPaperItem for the UserFace
        $paperItem = UserPaperItem::create([
            'user_paper_id' => UserPaper::create([
                'user_id' => $user->id,
            ])->id,
            'type' => UserPaperType::PortraitImage->value,
            'file_name' => 'portrait.jpg',
            'file_path' => 'test/portrait.jpg',
            'approved_at' => now(),
        ]);

        // Create UserFace record for the user
        UserFace::create([
            'user_id' => $user->id,
            'user_paper_item_id' => $paperItem->id,
            'vector_id' => 'test-vector-id',
            'provider' => config('services.face_recognition.provider'),
        ]);

        // Create existing check-in
        $checkIn = CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'check_in_time' => now()->setDateFrom($shift->date)->setTime(9, 0)->format('Y-m-d H:i:s'),
            'check_in_coordinate' => '35.6895,139.6917',
        ]);

        // Mock face recognition to return verification failure
        $verifyResponse = new VerifyResponse(
            verified: false, // Face does not match
            userId: (string) $user->id,
            confidence: 0.3
        );

        $this->faceRecognitionProvider
            ->shouldReceive('supportsRealtimeAntiSpoofing')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $this->faceRecognitionProvider->shouldReceive('verify')->once()->andReturn($verifyResponse);

        Carbon::setTestNow(now()->setDateFrom($shift->date)->setTime(18, 5));

        $image = UploadedFile::fake()->image('face_out.jpg');

        $response = $this->actingAs($user, 'api')->postJson('/user/check-in/end', [
            'shift_user_id' => $shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => $image,
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'CHECK_OUT_FACE_VERIFICATION_FAILED');

        // Ensure check-out time was NOT updated
        $this->assertDatabaseHas('check_ins', [
            'id' => $checkIn->id,
            'user_id' => $user->id,
            'check_out_time' => null, // Should still be null
        ]);

        Carbon::setTestNow();
    }

    public function testCheckInFailsWhenAlreadyCheckedIn(): void
    {
        [$user, $shift, $shiftUser] = $this->createShiftSetup();

        CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'check_in_time' => now()->setDateFrom($shift->date)->setTime(9, 0)->format('Y-m-d H:i:s'),
            'check_in_coordinate' => '35.6895,139.6917',
        ]);

        Carbon::setTestNow(now()->setDateFrom($shift->date)->setTime(9, 30));

        $response = $this->actingAs($user, 'api')->postJson('/user/check-in/start', [
            'shift_user_id' => $shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'CHECK_IN_ALREADY_CHECKED_IN');

        Carbon::setTestNow();
    }

    public function testCheckInFailsWhenOutsideShiftTime(): void
    {
        [$user, $shift, $shiftUser] = $this->createShiftSetup();

        Carbon::setTestNow(now()->setDateFrom($shift->date)->setTime(20, 0));

        $response = $this->actingAs($user, 'api')->postJson('/user/check-in/start', [
            'shift_user_id' => $shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'CHECK_IN_TOO_SOON_OR_TOO_LATE');

        Carbon::setTestNow();
    }

    public function testCheckInFailsWhenLocationOutOfRange(): void
    {
        [$user, $shift, $shiftUser] = $this->createShiftSetup();

        Carbon::setTestNow(now()->setDateFrom($shift->date)->setTime(9, 5));

        $response = $this->actingAs($user, 'api')->postJson('/user/check-in/start', [
            'shift_user_id' => $shiftUser->id,
            'latitude' => 0.0,
            'longitude' => 0.0,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'CHECK_IN_INVALID_LOCATION');

        Carbon::setTestNow();
    }

    public function testCheckOutFailsWhenNotCheckedIn(): void
    {
        [$user, $shift, $shiftUser] = $this->createShiftSetup();

        Carbon::setTestNow(now()->setDateFrom($shift->date)->setTime(18, 5));

        $response = $this->actingAs($user, 'api')->postJson('/user/check-in/end', [
            'shift_user_id' => $shiftUser->id,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(400)->assertJsonPath('error', 'CHECK_OUT_USER_NOT_CHECKED_IN');

        Carbon::setTestNow();
    }

    public function testCheckInRequiresAuthentication(): void
    {
        $response = $this->postJson('/user/check-in/start', [
            'shift_user_id' => 1,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(401);
    }

    public function testCheckOutRequiresAuthentication(): void
    {
        $response = $this->postJson('/user/check-in/end', [
            'shift_user_id' => 1,
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'image' => UploadedFile::fake()->image('face.jpg'),
        ]);

        $response->assertStatus(401);
    }

    public function testGetCheckInShiftReturnsActiveShift(): void
    {
        [$user, $shift, $shiftUser] = $this->createShiftSetup();

        Carbon::setTestNow(now()->setDateFrom($shift->date)->setTime(9, 5));

        $response = $this->actingAs($user, 'api')->getJson('/user/check-in/shift');

        $response->assertStatus(200)->assertJsonPath('data.id', $shiftUser->id);

        Carbon::setTestNow();
    }

    public function testGetCheckInShiftReturnsNullWhenNoShift(): void
    {
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        $response = $this->actingAs($user, 'api')->getJson('/user/check-in/shift');

        $response->assertStatus(200)->assertJsonPath('data', null);
    }

    public function testGetCheckInShiftPrefersYesterdayOpenCheckInOverTodaysShift(): void
    {
        Carbon::setTestNow('2026-05-11 09:30:00');

        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $overnightShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '22:00:00',
            'end_time' => '07:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $dayShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $position = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $yesterdayShift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $overnightShift->id,
            'creator_id' => $user->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        $yesterdayShiftUser = ShiftUser::create([
            'shift_id' => $yesterdayShift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        CheckIn::create([
            'shift_id' => $yesterdayShift->id,
            'shift_user_id' => $yesterdayShiftUser->id,
            'user_id' => $user->id,
            'check_in_time' => '2026-05-10 22:05:00',
            'check_in_coordinate' => '35.6895,139.6917',
        ]);

        $todayShift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $dayShift->id,
            'creator_id' => $user->id,
            'date' => '2026-05-11',
            'adjusted_date' => '2026-05-11',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        ShiftUser::create([
            'shift_id' => $todayShift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/user/check-in/shift');

        $response->assertStatus(200)->assertJsonPath('data.id', $yesterdayShiftUser->id);

        Carbon::setTestNow();
    }

    public function testGetCheckInShiftRequiresAuthentication(): void
    {
        $response = $this->getJson('/user/check-in/shift');

        $response->assertStatus(401);
    }

    public function testGetHistoryReturnsCheckIns(): void
    {
        [$user, $shift, $shiftUser] = $this->createShiftSetup();

        CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'check_in_time' => Carbon::parse($shift->date . ' 09:00:00')->format('Y-m-d H:i:s'),
            'check_out_time' => Carbon::parse($shift->date . ' 18:00:00')->format('Y-m-d H:i:s'),
            'work_minutes' => 480,
            'check_in_coordinate' => '35.6895,139.6917',
        ]);

        $month = Carbon::parse($shift->date)->format('Y-m');

        $response = $this->actingAs($user, 'api')->getJson('/user/check-in/history?month=' . $month);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertEquals('8.00', $response->json('data.total'));
    }

    public function testGetHistoryRequiresAuthentication(): void
    {
        $response = $this->getJson('/user/check-in/history?month=2026-06');

        $response->assertStatus(401);
    }

    public function testGetHistoryValidationFails(): void
    {
        [$user] = $this->createShiftSetup();

        $response = $this->actingAs($user, 'api')->getJson('/user/check-in/history');

        $response->assertStatus(422);
    }

    public function testGetHistoryReturnsEmptyWhenNoCheckIns(): void
    {
        [$user] = $this->createShiftSetup();

        $response = $this->actingAs($user, 'api')->getJson('/user/check-in/history?month=' . now()->format('Y-m'));

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.items'));
        $this->assertEquals('0.00', $response->json('data.total'));
    }

    public function testGetHistoryExcludesIncompleteCheckIns(): void
    {
        [$user, $shift, $shiftUser] = $this->createShiftSetup();

        // Check-in without work_minutes (not yet checked out) should be excluded
        CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'check_in_time' => Carbon::parse($shift->date . ' 09:00:00')->format('Y-m-d H:i:s'),
            'work_minutes' => null,
        ]);

        $month = Carbon::parse($shift->date)->format('Y-m');

        $response = $this->actingAs($user, 'api')->getJson('/user/check-in/history?month=' . $month);

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.items'));
    }

    public function testGetHistoryOnlyReturnsCurrentUserCheckIns(): void
    {
        [$user, $shift] = $this->createShiftSetup();
        [, $otherShift, $otherShiftUser] = $this->createShiftSetup();
        $otherUser = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        CheckIn::create([
            'shift_id' => $otherShift->id,
            'shift_user_id' => $otherShiftUser->id,
            'user_id' => $otherUser->id,
            'check_in_time' => Carbon::parse($otherShift->date . ' 09:00:00')->format('Y-m-d H:i:s'),
            'check_out_time' => Carbon::parse($otherShift->date . ' 18:00:00')->format('Y-m-d H:i:s'),
            'work_minutes' => 480,
        ]);

        $month = Carbon::parse($shift->date)->format('Y-m');

        $response = $this->actingAs($user, 'api')->getJson('/user/check-in/history?month=' . $month);

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.items'));
    }

    public function testGetCheckInDetailReturnsCheckIn(): void
    {
        [$user, $shift, $shiftUser] = $this->createShiftSetup();

        $checkIn = CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'check_in_time' => now()->setDateFrom($shift->date)->setTime(9, 0)->format('Y-m-d H:i:s'),
            'check_in_coordinate' => '35.6895,139.6917',
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/user/check-in/history/' . $checkIn->id);

        $response->assertStatus(200)->assertJsonPath('data.id', $checkIn->id);
    }

    public function testGetCheckInDetailReturnsNotFoundForOtherUser(): void
    {
        [$user, $shift, $shiftUser] = $this->createShiftSetup();

        $checkIn = CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'check_in_time' => now()->setDateFrom($shift->date)->setTime(9, 0)->format('Y-m-d H:i:s'),
            'check_in_coordinate' => '35.6895,139.6917',
        ]);

        $otherUser = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        $response = $this->actingAs($otherUser, 'api')->getJson('/user/check-in/history/' . $checkIn->id);

        $response->assertStatus(404);
    }

    public function testGetCheckInDetailReturnsNotFoundForNonExistentId(): void
    {
        [$user] = $this->createShiftSetup();

        $response = $this->actingAs($user, 'api')->getJson('/user/check-in/history/99999');

        $response->assertStatus(404);
    }

    public function testGetCheckInDetailRequiresAuthentication(): void
    {
        $response = $this->getJson('/user/check-in/history/1');

        $response->assertStatus(401);
    }

    public function testGetReportsRequiresAuthentication(): void
    {
        $response = $this->getJson('/user/check-in/reports?month=2026-06');

        $response->assertStatus(401);
    }

    public function testGetReportDetailReturnsNotFoundForOtherUser(): void
    {
        [$user, $shift, $shiftUser] = $this->createShiftSetup();

        $report = ShiftReport::create([
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'date' => $shift->date,
            'late_minutes' => 5,
        ]);

        $otherUser = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        $response = $this->actingAs($otherUser, 'api')->getJson('/user/check-in/reports/' . $report->id);

        $response->assertStatus(404);
    }

    public function testGetReportDetailRequiresAuthentication(): void
    {
        $response = $this->getJson('/user/check-in/reports/1');

        $response->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // getReports
    // -------------------------------------------------------------------------

    public function testGetReportsReturnsReports(): void
    {
        [$user, $shift, $shiftUser] = $this->createShiftSetup();

        ShiftReport::create([
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'date' => $shift->date,
            'late_minutes' => 10,
        ]);

        $month = Carbon::parse($shift->date)->format('Y-m');

        $response = $this->actingAs($user, 'api')->getJson("/user/check-in/reports?month={$month}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(10, $response->json('data.0.late_minutes'));
    }

    public function testGetReportsReturnsEmptyWhenNoReports(): void
    {
        [$user] = $this->createShiftSetup();

        $response = $this->actingAs($user, 'api')->getJson('/user/check-in/reports?month=' . now()->format('Y-m'));

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function testGetReportsValidationFails(): void
    {
        [$user] = $this->createShiftSetup();

        $response = $this->actingAs($user, 'api')->getJson('/user/check-in/reports');

        $response->assertStatus(422);
    }

    public function testGetReportsOnlyReturnsCurrentUserReports(): void
    {
        [$user, $shift] = $this->createShiftSetup();
        [$otherUser, , $otherShiftUser] = $this->createShiftSetup();

        ShiftReport::create([
            'shift_user_id' => $otherShiftUser->id,
            'user_id' => $otherUser->id,
            'date' => $shift->date,
            'late_minutes' => 15,
        ]);

        $month = Carbon::parse($shift->date)->format('Y-m');

        $response = $this->actingAs($user, 'api')->getJson("/user/check-in/reports?month={$month}");

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    // -------------------------------------------------------------------------
    // getReportDetail
    // -------------------------------------------------------------------------

    public function testGetReportDetailSuccess(): void
    {
        [$user, $shift, $shiftUser] = $this->createShiftSetup();

        $report = ShiftReport::create([
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'date' => $shift->date,
            'early_minutes' => 5,
        ]);

        $response = $this->actingAs($user, 'api')->getJson("/user/check-in/reports/{$report->id}");

        $response->assertStatus(200);
        $this->assertEquals($report->id, $response->json('data.id'));
        $this->assertEquals(5, $response->json('data.early_minutes'));
        $this->assertNotNull($response->json('data.shift_user'));
    }

    public function testGetReportDetailReturnsNotFoundForNonExistentReport(): void
    {
        [$user] = $this->createShiftSetup();

        $response = $this->actingAs($user, 'api')->getJson('/user/check-in/reports/99999');

        $response->assertStatus(404);
    }

    private function createShiftSetup(): array
    {
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

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

        $workplacePosition = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        UserDevice::create([
            'user_id' => $user->id,
            'device_id' => 'device123',
            'name' => 'Test Device',
            'platform' => 'ios',
        ]);

        return [$user, $shift, $shiftUser];
    }
}
