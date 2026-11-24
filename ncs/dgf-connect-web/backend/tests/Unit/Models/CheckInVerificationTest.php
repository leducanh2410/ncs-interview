<?php

namespace Tests\Unit\Models;

use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Enums\VerificationStatus;
use App\Models\CheckIn;
use App\Models\CheckInVerification;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserFace;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckInVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function testCheckInVerificationRelationships()
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

        $verification = CheckInVerification::create([
            'check_in_id' => $checkIn->id,
            'is_check_in' => true,
            'image_path' => 'path/to/image.jpg',
            'bounding_box' => '[10, 10, 110, 110]',
            'status' => VerificationStatus::Verified,
            'is_spoofed' => false,
            'spoofed_confidence' => 0.05,
        ]);

        $this->assertInstanceOf(CheckIn::class, $verification->checkIn);
        $this->assertEquals($checkIn->id, $verification->checkIn->id);
        $this->assertTrue($verification->is_check_in);
        $this->assertEquals(VerificationStatus::Verified, $verification->status);
    }

    public function testUserFaceRelationships()
    {
        $user = User::factory()->create();

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $userPaperItem = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'face_image',
        ]);

        $userFace = UserFace::create([
            'user_id' => $user->id,
            'user_paper_item_id' => $userPaperItem->id,
            'vector_id' => 'vec123',
            'provider' => 'runpod',
        ]);

        $this->assertInstanceOf(User::class, $userFace->user);
        $this->assertEquals($user->id, $userFace->user->id);
        $this->assertInstanceOf(UserPaperItem::class, $userFace->userPaperItem);
        $this->assertEquals($userPaperItem->id, $userFace->userPaperItem->id);
    }
}
