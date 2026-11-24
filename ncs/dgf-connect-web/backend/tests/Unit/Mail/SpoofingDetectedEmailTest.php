<?php

namespace Tests\Unit\Mail;

use App\Enums\BoundingBoxFormat;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Enums\VerificationStatus;
use App\Mail\SpoofingDetectedEmail;
use App\Models\CheckIn;
use App\Models\CheckInVerification;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SpoofingDetectedEmailTest extends TestCase
{
    use RefreshDatabase;

    public function testEmailContainsAllRequiredInformation()
    {
        Config::set('app.cdn_base_url', 'https://cdn.example.com');

        $verification = $this->createCheckInVerification([
            'is_spoofed' => true,
            'spoofed_confidence' => 0.95,
            'image_path' => 'check-ins/spoofed-image.jpg',
        ]);

        $email = new SpoofingDetectedEmail(new Collection([$verification]));

        $content = $email->content();

        $this->assertEquals('spoofing.vi.spoofing_detected', $content->markdown);

        $data = $content->with;
        $this->assertArrayHasKey('entries', $data);
        $this->assertCount(1, $data['entries']);

        $entry = $data['entries'][0];
        $this->assertArrayHasKey('userName', $entry);
        $this->assertArrayHasKey('staffNo', $entry);
        $this->assertArrayHasKey('workplaceName', $entry);
        $this->assertArrayHasKey('shiftDate', $entry);
        $this->assertArrayHasKey('shiftTime', $entry);
        $this->assertArrayHasKey('verificationType', $entry);
        $this->assertArrayHasKey('spoofedConfidence', $entry);
        $this->assertArrayHasKey('detectedAt', $entry);
        $this->assertArrayHasKey('imageUrl', $entry);

        $this->assertEquals('Check-in', $entry['verificationType']);
        $this->assertEquals('95.00', $entry['spoofedConfidence']);
        $this->assertStringContainsString('https://cdn.example.com/check-ins/spoofed-image.jpg', $entry['imageUrl']);
    }

    public function testEmailSubjectContainsCount()
    {
        $verification = $this->createCheckInVerification();
        $email = new SpoofingDetectedEmail(new Collection([$verification]));

        $envelope = $email->envelope();

        $this->assertEquals('Cảnh báo: Phát hiện 1 check-in/check-out giả mạo', $envelope->subject);
    }

    public function testEmailIndicatesCheckOut()
    {
        $verification = $this->createCheckInVerification([
            'is_check_in' => false,
        ]);

        $email = new SpoofingDetectedEmail(new Collection([$verification]));
        $content = $email->content();
        $entry = $content->with['entries'][0];

        $this->assertEquals('Check-out', $entry['verificationType']);
    }

    public function testEmailHandlesEmptyImagePath()
    {
        $verification = $this->createCheckInVerification([
            'image_path' => '',
        ]);

        $email = new SpoofingDetectedEmail(new Collection([$verification]));
        $content = $email->content();
        $entry = $content->with['entries'][0];

        $this->assertNull($entry['imageUrl']);
    }

    private function createCheckInVerification(array $overrides = []): CheckInVerification
    {
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'EMP001',
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
                    'status' => VerificationStatus::Verified,
                    'is_spoofed' => true,
                    'spoofed_confidence' => 0.85,
                ],
                $overrides
            )
        );
    }
}
