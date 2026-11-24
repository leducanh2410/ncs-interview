<?php

namespace Tests\Unit\Jobs;

use App\Enums\BoundingBoxFormat;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Enums\VerificationStatus;
use App\Jobs\ProcessAntiSpoofingBatch;
use App\Mail\AntiSpoofingRetryFailedEmail;
use App\Mail\SpoofingDetectedEmail;
use App\Models\CheckIn;
use App\Models\CheckInVerification;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Services\AntiSpoofing\AntiSpoofingService;
use App\Services\AntiSpoofing\Exceptions\AntiSpoofingRetryExhaustedException;
use App\Services\AntiSpoofing\Responses\BatchAntiSpoofingResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProcessAntiSpoofingBatchTest extends TestCase
{
    use RefreshDatabase;

    private $antiSpoofingService;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the AntiSpoofingService
        $this->antiSpoofingService = Mockery::mock(AntiSpoofingService::class);
        $this->app->instance(AntiSpoofingService::class, $this->antiSpoofingService);

        // Set up test storage disk
        Storage::fake('local');

        // Mock Log facade to avoid errors
        Log::spy();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function testUsesImageUrlWhenFilesystemDiskIsS3()
    {
        // Set filesystem disk to S3
        Config::set('filesystems.default', 's3');
        Config::set('app.cdn_base_url', 'https://cdn.example.com');

        // Create test data
        $verification = $this->createCheckInVerification([
            'image_path' => 'check-ins/test-image.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        // Create a fake image in storage
        Storage::disk('local')->put('check-ins/test-image.jpg', 'fake-image-content');

        // Expected batch item sent to the service
        $expectedItems = [
            [
                'check_in_verification_id' => (string) $verification->id,
                'image_url' => 'https://cdn.example.com/check-ins/test-image.jpg',
                'bbox' => $verification->bounding_box,
                'bbox_format' => $verification->bounding_box_format->value,
            ],
        ];

        // Mock the service response
        $response = new BatchAntiSpoofingResponse(
            results: [
                [
                    'success' => true,
                    'check_in_verification_id' => (string) $verification->id,
                    'is_spoofed' => false,
                    'spoof_confidence' => 0.05,
                ],
            ],
            total: 1,
            successCount: 1,
            errorCount: 0,
            processingTimeMs: 100
        );

        $this->antiSpoofingService->shouldReceive('batchVerify')->once()->with($expectedItems)->andReturn($response);

        // Run the job
        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        // Verify the verification was updated
        $verification->refresh();
        $this->assertEquals(VerificationStatus::Verified, $verification->status);
        $this->assertFalse($verification->is_spoofed);
        $this->assertEquals(0.05, $verification->spoofed_confidence);
    }

    public function testUsesImageBase64WhenFilesystemDiskIsLocal()
    {
        // Set filesystem disk to local
        Config::set('filesystems.default', 'local');

        $imageContent = 'fake-image-content-for-base64';
        $expectedBase64 = base64_encode($imageContent);

        // Create test data
        $verification = $this->createCheckInVerification([
            'image_path' => 'check-ins/test-image-local.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        // Create a fake image in storage
        Storage::disk('local')->put('check-ins/test-image-local.jpg', $imageContent);

        // Expected batch item sent to the service
        $expectedItems = [
            [
                'check_in_verification_id' => (string) $verification->id,
                'image_base64' => $expectedBase64,
                'bbox' => $verification->bounding_box,
                'bbox_format' => $verification->bounding_box_format->value,
            ],
        ];

        // Mock the service response
        $response = new BatchAntiSpoofingResponse(
            results: [
                [
                    'success' => true,
                    'check_in_verification_id' => (string) $verification->id,
                    'is_spoofed' => true,
                    'spoof_confidence' => 0.95,
                ],
            ],
            total: 1,
            successCount: 1,
            errorCount: 0,
            processingTimeMs: 100
        );

        $this->antiSpoofingService->shouldReceive('batchVerify')->once()->with($expectedItems)->andReturn($response);

        // Run the job
        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        // Verify the verification was updated
        $verification->refresh();
        $this->assertEquals(VerificationStatus::Verified, $verification->status);
        $this->assertTrue($verification->is_spoofed);
        $this->assertEquals(0.95, $verification->spoofed_confidence);
    }

    public function testSkipsVerificationWhenImageFileCannotBeRead()
    {
        // Set filesystem disk to local
        Config::set('filesystems.default', 'local');

        // Create test data but DON'T create the actual file
        $verification = $this->createCheckInVerification([
            'image_path' => 'check-ins/non-existent-image.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        // The job should NOT call batchVerify since all items will be skipped
        $this->antiSpoofingService->shouldNotReceive('batchVerify');

        // Run the job
        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        // Verify the verification was NOT updated (still pending)
        $verification->refresh();
        $this->assertEquals(VerificationStatus::Pending, $verification->status);
    }

    public function testHandlesBatchResponseWithMixedSuccessAndFailure()
    {
        Config::set('filesystems.default', 's3');
        Config::set('app.cdn_base_url', 'https://cdn.example.com');

        // Create multiple verifications
        $verification1 = $this->createCheckInVerification([
            'image_path' => 'check-ins/image1.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        $verification2 = $this->createCheckInVerification([
            'image_path' => 'check-ins/image2.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        $verification3 = $this->createCheckInVerification([
            'image_path' => 'check-ins/image3.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        // Create fake images
        Storage::disk('local')->put('check-ins/image1.jpg', 'content1');
        Storage::disk('local')->put('check-ins/image2.jpg', 'content2');
        Storage::disk('local')->put('check-ins/image3.jpg', 'content3');

        // Mock response with mixed results
        $response = new BatchAntiSpoofingResponse(
            results: [
                [
                    'success' => true,
                    'check_in_verification_id' => (string) $verification1->id,
                    'is_spoofed' => false,
                    'spoof_confidence' => 0.1,
                ],
                [
                    'success' => false,
                    'check_in_verification_id' => (string) $verification2->id,
                    'error' => 'Face not detected',
                ],
                [
                    'success' => true,
                    'check_in_verification_id' => (string) $verification3->id,
                    'is_spoofed' => true,
                    'spoof_confidence' => 0.85,
                ],
            ],
            total: 3,
            successCount: 2,
            errorCount: 1,
            processingTimeMs: 150
        );

        $this->antiSpoofingService->shouldReceive('batchVerify')->once()->andReturn($response);

        // Run the job
        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        // Verify results
        $verification1->refresh();
        $this->assertEquals(VerificationStatus::Verified, $verification1->status);
        $this->assertFalse($verification1->is_spoofed);

        $verification2->refresh();
        // Failed verifications should be marked as error
        $this->assertEquals(VerificationStatus::Error, $verification2->status);

        $verification3->refresh();
        $this->assertEquals(VerificationStatus::Verified, $verification3->status);
        $this->assertTrue($verification3->is_spoofed);
    }

    public function testHandlesEmptyVerificationsList()
    {
        Config::set('filesystems.default', 's3');

        // The service should not be called
        $this->antiSpoofingService->shouldNotReceive('batchVerify');

        // Run the job
        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);
    }

    public function testSendsEmailNotificationWhenSpoofingDetected()
    {
        Mail::fake();
        Config::set('filesystems.default', 's3');
        Config::set('app.cdn_base_url', 'https://cdn.example.com');
        Config::set('work.system_notification_emails', ['admin1@example.com', 'admin2@example.com']);

        $verification = $this->createCheckInVerification([
            'image_path' => 'check-ins/spoofed.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        Storage::disk('local')->put('check-ins/spoofed.jpg', 'content');

        $response = new BatchAntiSpoofingResponse(
            results: [
                [
                    'success' => true,
                    'check_in_verification_id' => (string) $verification->id,
                    'is_spoofed' => true,
                    'spoof_confidence' => 0.95,
                ],
            ],
            total: 1,
            successCount: 1,
            errorCount: 0,
            processingTimeMs: 100
        );

        $this->antiSpoofingService->shouldReceive('batchVerify')->once()->andReturn($response);

        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        // Verify emails were sent to both admins
        Mail::assertSent(SpoofingDetectedEmail::class, 2);
        Mail::assertSent(SpoofingDetectedEmail::class, function ($mail) {
            return $mail->hasTo('admin1@example.com');
        });
        Mail::assertSent(SpoofingDetectedEmail::class, function ($mail) {
            return $mail->hasTo('admin2@example.com');
        });
    }

    public function testDoesNotSendEmailWhenNoSpoofingDetected()
    {
        Mail::fake();
        Config::set('filesystems.default', 's3');
        Config::set('work.system_notification_emails', ['admin@example.com']);

        $verification = $this->createCheckInVerification([
            'image_path' => 'check-ins/valid.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        Storage::disk('local')->put('check-ins/valid.jpg', 'content');

        $response = new BatchAntiSpoofingResponse(
            results: [
                [
                    'success' => true,
                    'check_in_verification_id' => (string) $verification->id,
                    'is_spoofed' => false,
                    'spoof_confidence' => 0.05,
                ],
            ],
            total: 1,
            successCount: 1,
            errorCount: 0,
            processingTimeMs: 100
        );

        $this->antiSpoofingService->shouldReceive('batchVerify')->once()->andReturn($response);

        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        Mail::assertNothingSent();
    }

    public function testDoesNotSendEmailWhenEmailConfigEmpty()
    {
        Mail::fake();
        Config::set('filesystems.default', 's3');
        Config::set('work.system_notification_emails', []);

        $verification = $this->createCheckInVerification([
            'image_path' => 'check-ins/spoofed.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        Storage::disk('local')->put('check-ins/spoofed.jpg', 'content');

        $response = new BatchAntiSpoofingResponse(
            results: [
                [
                    'success' => true,
                    'check_in_verification_id' => (string) $verification->id,
                    'is_spoofed' => true,
                    'spoof_confidence' => 0.95,
                ],
            ],
            total: 1,
            successCount: 1,
            errorCount: 0,
            processingTimeMs: 100
        );

        $this->antiSpoofingService->shouldReceive('batchVerify')->once()->andReturn($response);

        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        Mail::assertNothingSent();
    }

    public function testHandlesEmailSendingFailureGracefully()
    {
        Config::set('filesystems.default', 's3');
        Config::set('app.cdn_base_url', 'https://cdn.example.com');
        Config::set('work.system_notification_emails', ['admin@example.com']);

        $verification = $this->createCheckInVerification([
            'image_path' => 'check-ins/spoofed.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        Storage::disk('local')->put('check-ins/spoofed.jpg', 'content');

        $response = new BatchAntiSpoofingResponse(
            results: [
                [
                    'success' => true,
                    'check_in_verification_id' => (string) $verification->id,
                    'is_spoofed' => true,
                    'spoof_confidence' => 0.95,
                ],
            ],
            total: 1,
            successCount: 1,
            errorCount: 0,
            processingTimeMs: 100
        );

        $this->antiSpoofingService->shouldReceive('batchVerify')->once()->andReturn($response);

        // Mock Mail to throw exception
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \Exception('SMTP error'));

        // Job should complete without throwing exception
        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        // Verify the verification was still updated
        $verification->refresh();
        $this->assertEquals(VerificationStatus::Verified, $verification->status);
        $this->assertTrue($verification->is_spoofed);
    }

    public function testSetsErrorStatusWhenRetryExhausted()
    {
        Config::set('filesystems.default', 's3');
        Config::set('app.cdn_base_url', 'https://cdn.example.com');
        Config::set('work.system_notification_emails', []);

        $verification1 = $this->createCheckInVerification([
            'image_path' => 'check-ins/image1.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        $verification2 = $this->createCheckInVerification([
            'image_path' => 'check-ins/image2.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        Storage::disk('local')->put('check-ins/image1.jpg', 'content1');
        Storage::disk('local')->put('check-ins/image2.jpg', 'content2');

        $this->antiSpoofingService
            ->shouldReceive('batchVerify')
            ->once()
            ->andThrow(new AntiSpoofingRetryExhaustedException('Connection failed after 3 attempts'));

        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        // Verify both verifications are marked as error
        $verification1->refresh();
        $verification2->refresh();

        $this->assertEquals(VerificationStatus::Error, $verification1->status);
        $this->assertEquals(VerificationStatus::Error, $verification2->status);
    }

    public function testSendsErrorEmailWhenRetryExhausted()
    {
        Mail::fake();
        Config::set('filesystems.default', 's3');
        Config::set('app.cdn_base_url', 'https://cdn.example.com');
        Config::set('work.system_notification_emails', ['operator1@example.com', 'operator2@example.com']);

        $verification = $this->createCheckInVerification([
            'image_path' => 'check-ins/image.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        Storage::disk('local')->put('check-ins/image.jpg', 'content');

        $this->antiSpoofingService
            ->shouldReceive('batchVerify')
            ->once()
            ->andThrow(new AntiSpoofingRetryExhaustedException('Connection failed after 3 attempts'));

        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        // Verify error emails were sent to both operators
        Mail::assertSent(AntiSpoofingRetryFailedEmail::class, 2);
        Mail::assertSent(AntiSpoofingRetryFailedEmail::class, function ($mail) {
            return $mail->hasTo('operator1@example.com');
        });
        Mail::assertSent(AntiSpoofingRetryFailedEmail::class, function ($mail) {
            return $mail->hasTo('operator2@example.com');
        });
    }

    public function testSetsErrorStatusOnUnexpectedException()
    {
        Config::set('filesystems.default', 's3');
        Config::set('app.cdn_base_url', 'https://cdn.example.com');
        Config::set('work.system_notification_emails', []);

        $verification = $this->createCheckInVerification([
            'image_path' => 'check-ins/image.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        Storage::disk('local')->put('check-ins/image.jpg', 'content');

        $this->antiSpoofingService
            ->shouldReceive('batchVerify')
            ->once()
            ->andThrow(new RuntimeException('Anti-spoofing API request failed with status 400'));

        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        // Verify the verification is marked as error
        $verification->refresh();
        $this->assertEquals(VerificationStatus::Error, $verification->status);
    }

    public function testSendsErrorEmailOnUnexpectedException()
    {
        Mail::fake();
        Config::set('filesystems.default', 's3');
        Config::set('app.cdn_base_url', 'https://cdn.example.com');
        Config::set('work.system_notification_emails', ['operator@example.com']);

        $verification = $this->createCheckInVerification([
            'image_path' => 'check-ins/image.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        Storage::disk('local')->put('check-ins/image.jpg', 'content');

        $this->antiSpoofingService
            ->shouldReceive('batchVerify')
            ->once()
            ->andThrow(new RuntimeException('Anti-spoofing API request failed with status 403'));

        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        // Verify error email was sent
        Mail::assertSent(AntiSpoofingRetryFailedEmail::class, 1);
        Mail::assertSent(AntiSpoofingRetryFailedEmail::class, function ($mail) {
            return $mail->hasTo('operator@example.com');
        });
    }

    public function testDoesNotSendErrorEmailWhenNoOperatorEmailsConfigured()
    {
        Mail::fake();
        Config::set('filesystems.default', 's3');
        Config::set('app.cdn_base_url', 'https://cdn.example.com');
        Config::set('work.system_notification_emails', []);

        $verification = $this->createCheckInVerification([
            'image_path' => 'check-ins/image.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        Storage::disk('local')->put('check-ins/image.jpg', 'content');

        $this->antiSpoofingService
            ->shouldReceive('batchVerify')
            ->once()
            ->andThrow(new AntiSpoofingRetryExhaustedException('Connection failed'));

        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        Mail::assertNothingSent();

        // But verification should still be marked as error
        $verification->refresh();
        $this->assertEquals(VerificationStatus::Error, $verification->status);
    }

    public function testHandlesErrorEmailSendingFailureGracefully()
    {
        Config::set('filesystems.default', 's3');
        Config::set('app.cdn_base_url', 'https://cdn.example.com');
        Config::set('work.system_notification_emails', ['operator@example.com']);

        $verification = $this->createCheckInVerification([
            'image_path' => 'check-ins/image.jpg',
            'status' => VerificationStatus::Pending,
        ]);

        Storage::disk('local')->put('check-ins/image.jpg', 'content');

        $this->antiSpoofingService
            ->shouldReceive('batchVerify')
            ->once()
            ->andThrow(new AntiSpoofingRetryExhaustedException('Connection failed'));

        // Mock Mail to throw exception
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \Exception('SMTP error'));

        // Job should complete without throwing exception
        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        // Verification should still be marked as error
        $verification->refresh();
        $this->assertEquals(VerificationStatus::Error, $verification->status);
    }

    public function testBatchSizeIsLimitedTo50()
    {
        Config::set('filesystems.default', 's3');
        Config::set('app.cdn_base_url', 'https://cdn.example.com');

        // Create 60 verifications
        $verifications = [];
        for ($i = 0; $i < 60; $i++) {
            $verifications[] = $this->createCheckInVerification([
                'image_path' => "check-ins/image{$i}.jpg",
                'status' => VerificationStatus::Pending,
            ]);
            Storage::disk('local')->put("check-ins/image{$i}.jpg", "content{$i}");
        }

        // Mock the service to capture the items count
        $capturedItems = null;
        $this->antiSpoofingService
            ->shouldReceive('batchVerify')
            ->once()
            ->withArgs(function ($items) use (&$capturedItems) {
                $capturedItems = $items;

                return true;
            })
            ->andReturn(
                new BatchAntiSpoofingResponse(
                    results: [],
                    total: 0,
                    successCount: 0,
                    errorCount: 0,
                    processingTimeMs: 100
                )
            );

        $job = new ProcessAntiSpoofingBatch();
        $job->handle($this->antiSpoofingService);

        // Verify only 50 items were sent
        $this->assertCount(50, $capturedItems);
    }

    private function createCheckInVerification(array $overrides = []): CheckInVerification
    {
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

        $checkIn = CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'check_in_time' => now()->format('Y-m-d H:i:s'),
            'check_in_coordinate' => '35.6895,139.6917',
        ]);

        return CheckInVerification::create(
            array_merge(
                [
                    'check_in_id' => $checkIn->id,
                    'is_check_in' => true,
                    'image_path' => 'check-ins/test-image.jpg',
                    'bounding_box' => [10, 10, 110, 110],
                    'bounding_box_format' => BoundingBoxFormat::Pixel,
                    'status' => VerificationStatus::Pending,
                    'is_spoofed' => false,
                    'spoofed_confidence' => 0.0,
                ],
                $overrides
            )
        );
    }
}
