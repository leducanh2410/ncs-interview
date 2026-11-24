<?php

namespace Tests\Unit\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Actions\Admin\User\ApprovePapersAction;
use App\Actions\Admin\User\SyncUserAvatarFromPortraitAction;
use App\Actions\Common\Poll\EvaluateUserAfterStaffNoAssignmentAction;
use App\Contracts\FaceRecognitionProviderInterface;
use App\Enums\ErrorCode;
use App\Enums\NotificationType;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Mail\ApprovalNotificationEmail;
use App\Mail\RejectionNotificationEmail;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Models\UserPaperStep;
use App\Services\FaceRecognition\Responses\AddUserResponse;
use App\Services\FaceRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ApprovePapersActionTest extends TestCase
{
    use RefreshDatabase;

    private $faceRecognitionProvider;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Event::fake();
        Storage::fake('public');

        $this->faceRecognitionProvider = Mockery::mock(FaceRecognitionProviderInterface::class);
        $this->app->instance(FaceRecognitionProviderInterface::class, $this->faceRecognitionProvider);

        // Fake all CrossStaff API calls
        Http::fake([
            'getMemberListExternal*' => Http::response([
                'GetMemberListExternal' => [
                    'ResultSet' => [
                        'TotalResult' => 0,
                        'Error' => null,
                    ],
                ],
            ]),
            'insMemberExternal*' => Http::response([
                'InsMemberExternal' => [
                    'ResultSet' => [
                        'Error' => null,
                    ],
                ],
            ]),
            'updMemberExternal*' => Http::response([
                'UpdMemberExternal' => [
                    'ResultSet' => [
                        'Error' => null,
                    ],
                ],
            ]),
        ]);
    }

    public function testAcceptsItemIdsSentAsNumericStrings()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $userPaper = UserPaper::create(['user_id' => $user->id]);
        $item = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/passport.pdf',
            'file_name' => 'passport.pdf',
        ]);

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin);
        $result = $action($user, [['id' => (string) $item->id, 'approved' => true]]);

        $this->assertNull($result->getError());
        $this->assertNotNull($item->refresh()->approved_at);
    }

    public function testDecisionsCannotReachAnotherUsersPaperItems()
    {
        $admin = User::factory()->create();

        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $ownPaper = UserPaper::create(['user_id' => $user->id]);
        $ownItem = UserPaperItem::create([
            'user_paper_id' => $ownPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/passport.pdf',
            'file_name' => 'passport.pdf',
        ]);

        $victim = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $victimPaper = UserPaper::create(['user_id' => $victim->id]);
        $victimItem = UserPaperItem::create([
            'user_paper_id' => $victimPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/victim-passport.pdf',
            'file_name' => 'victim-passport.pdf',
        ]);

        // Same number of decisions as the user has pending items, but the id belongs
        // to somebody else.
        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin);
        $result = $action($user, [['id' => $victimItem->id, 'approved' => true]]);

        $this->assertNotNull($result->getError(), 'a decision on another user\'s item must be refused');
        $this->assertNull($victimItem->refresh()->approved_at, 'another user\'s document must stay untouched');
        $this->assertNull($ownItem->refresh()->approved_at);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    public function testInvokeApprovesAllItemsWhenAllApproved()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $item1 = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/passport.pdf',
            'file_name' => 'passport.pdf',
        ]);

        $item2 = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'other',
            'file_path' => 'path/to/other.pdf',
            'file_name' => 'other.pdf',
        ]);

        $itemDecisions = [['id' => $item1->id, 'approved' => true], ['id' => $item2->id, 'approved' => true]];

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $result = $this->actingAs($admin)->assertNull($action($user, $itemDecisions)->getError());

        // Assert all items are approved
        $this->assertNotNull($item1->fresh()->approved_at);
        $this->assertNull($item1->fresh()->rejected_at);
        $this->assertEquals(UserPaperItemFolder::Current->value, $item1->fresh()->folder);
        $this->assertNotNull($item2->fresh()->approved_at);
        $this->assertNull($item2->fresh()->rejected_at);
        $this->assertEquals(UserPaperItemFolder::Current->value, $item2->fresh()->folder);

        // Assert user status is approved
        $user->refresh();
        $this->assertEquals(UserPaperStatus::Approved->value, $user->paper_status);
    }

    public function testRejectsPaperWhenAnyItemIsRejected()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $item1 = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/passport.pdf',
            'file_name' => 'passport.pdf',
        ]);

        $item2 = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'other',
            'file_path' => 'path/to/other.pdf',
            'file_name' => 'other.pdf',
        ]);

        $itemDecisions = [['id' => $item1->id, 'approved' => true], ['id' => $item2->id, 'approved' => false]];
        $rejectedNote = 'Document is unclear';

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $result = $action($user, $itemDecisions, $rejectedNote);

        $this->assertNull($result->getError());

        // Assert item1 is approved
        $this->assertNotNull($item1->fresh()->approved_at);
        $this->assertNull($item1->fresh()->rejected_at);
        $this->assertEquals(UserPaperItemFolder::Current->value, $item1->fresh()->folder);

        // Assert item2 is rejected
        $this->assertNull($item2->fresh()->approved_at);
        $this->assertNotNull($item2->fresh()->rejected_at);
        $this->assertEquals(UserPaperItemFolder::Rejected->value, $item2->fresh()->folder);

        // Assert user status is rejected
        $user->refresh();
        $this->assertEquals(UserPaperStatus::Rejected->value, $user->paper_status);
        $this->assertEquals($rejectedNote, $userPaper->fresh()->rejected_note);
    }

    public function testInvokeAddsUserFaceIfPortraitImageApproved()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $portraitImage = UploadedFile::fake()->image('portrait.jpg');
        $imagePath = 'path/to/portraits/' . $portraitImage->hashName();
        Storage::disk('public')->put($imagePath, $portraitImage->getContent());

        $userPaperItem = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'portrait_image',
            'file_path' => $imagePath,
            'file_name' => $portraitImage->getClientOriginalName(),
        ]);

        $itemDecisions = [['id' => $userPaperItem->id, 'approved' => true]];

        $this->faceRecognitionProvider
            ->shouldReceive('addUser')
            ->once()
            ->with((string) $user->id, $imagePath)
            ->andReturn(new AddUserResponse(success: true, vectorId: 'test_vector_id'));

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull($action($user, $itemDecisions)->getError());

        $this->assertDatabaseHas('user_faces', [
            'user_id' => $user->id,
            'user_paper_item_id' => $userPaperItem->id,
            'vector_id' => 'test_vector_id',
            'provider' => 'rekognition',
        ]);
    }

    public function testInvokeDoesNotAddUserFaceWhenPortraitRejected()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $userPaperItem = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'portrait_image',
            'file_path' => 'path/to/portrait.jpg',
            'file_name' => 'portrait.jpg',
        ]);

        $itemDecisions = [['id' => $userPaperItem->id, 'approved' => false]];

        // Should not call face recognition
        $this->faceRecognitionProvider->shouldNotReceive('addUser');

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $result = $action($user, $itemDecisions, 'Poor quality');

        $this->assertNull($result->getError());
        $user->refresh();
        $this->assertEquals(UserPaperStatus::Rejected->value, $user->paper_status);
    }

    public function testInvokeSendsRejectionEmailWhenRejected()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $item = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/passport.pdf',
            'file_name' => 'passport.pdf',
        ]);

        $itemDecisions = [['id' => $item->id, 'approved' => false]];
        $rejectedNote = 'Document is blurry';

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull($action($user, $itemDecisions, $rejectedNote)->getError());

        Mail::assertSent(RejectionNotificationEmail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function testInvokeSendsApprovalEmailWhenApproved()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $item = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/passport.pdf',
            'file_name' => 'passport.pdf',
        ]);

        $itemDecisions = [['id' => $item->id, 'approved' => true]];

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull($action($user, $itemDecisions)->getError());

        Mail::assertSent(ApprovalNotificationEmail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function testInvokeThrowsExceptionWhenAllPendingItemsNotIncluded()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $item1 = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/passport.pdf',
            'file_name' => 'passport.pdf',
        ]);

        $item2 = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'other',
            'file_path' => 'path/to/other.pdf',
            'file_name' => 'other.pdf',
        ]);

        // Only include one item, should return validation error
        $itemDecisions = [['id' => $item1->id, 'approved' => true]];

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $result = $action($user, $itemDecisions);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
    }

    public function testInvokeAssignsNewStaffNoWhenFullyApproved()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::WaitForApproval->value,
            'staff_no' => null,
        ]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $item = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/passport.pdf',
            'file_name' => 'passport.pdf',
        ]);

        $itemDecisions = [['id' => $item->id, 'approved' => true]];

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull($action($user, $itemDecisions)->getError());

        $user->refresh();
        $this->assertNotNull($user->staff_no);
    }

    public function testInvokeDoesNotAssignStaffNoWhenRejected()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::WaitForApproval->value,
            'staff_no' => null,
        ]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $item = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/passport.pdf',
            'file_name' => 'passport.pdf',
        ]);

        $itemDecisions = [['id' => $item->id, 'approved' => false]];

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull($action($user, $itemDecisions, 'Rejected')->getError());

        $user->refresh();
        $this->assertNull($user->staff_no);
    }

    public function testInvokeDoesNotAssignStaffNoWhenAlreadyHasStaffNo()
    {
        $admin = User::factory()->create();
        $existingStaffNo = 12345;
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::WaitForApproval->value,
            'staff_no' => $existingStaffNo,
        ]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $item = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/passport.pdf',
            'file_name' => 'passport.pdf',
        ]);

        $itemDecisions = [['id' => $item->id, 'approved' => true]];

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull($action($user, $itemDecisions)->getError());

        $user->refresh();
        $this->assertEquals($existingStaffNo, $user->staff_no);
    }

    public function testInvokeCreatesNotificationForApproval()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $item = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/passport.pdf',
            'file_name' => 'passport.pdf',
        ]);

        $itemDecisions = [['id' => $item->id, 'approved' => true]];

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull($action($user, $itemDecisions)->getError());

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => NotificationType::PAPER_APPROVED->value,
        ]);
    }

    public function testInvokeCreatesNotificationForRejection()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $item = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/passport.pdf',
            'file_name' => 'passport.pdf',
        ]);

        $itemDecisions = [['id' => $item->id, 'approved' => false]];

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull($action($user, $itemDecisions, 'Rejected')->getError());

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => NotificationType::PAPER_REJECTED->value,
        ]);
    }

    public function testInvokeReturnsErrorWhenFaceRegistrationFailsWithNoUserFace()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        // Create a portrait image
        $portraitImage = UploadedFile::fake()->image('portrait.jpg');
        $imagePath = 'path/to/portraits/' . $portraitImage->hashName();
        Storage::disk('public')->put($imagePath, $portraitImage->getContent());

        $userPaperItem = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'portrait_image',
            'file_path' => $imagePath,
            'file_name' => $portraitImage->getClientOriginalName(),
        ]);

        $itemDecisions = [['id' => $userPaperItem->id, 'approved' => true]];

        // Mock face recognition to fail due to invalid image
        $this->faceRecognitionProvider
            ->shouldReceive('addUser')
            ->once()
            ->with((string) $user->id, $imagePath)
            ->andReturn(new AddUserResponse(success: false, vectorId: null, isInvalidImage: true));

        $this->actingAs($admin);
        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $result = $action($user, $itemDecisions);

        // Assert that the transaction was rolled back and error returned
        $this->assertEquals(ErrorCode::UserFaceApprovalFailed, $result->getError());
        $this->assertEquals(422, $result->getStatus());
        $this->assertArrayHasKey('failed_portrait_ids', $result->getData());

        // Assert user status is still wait_for_approval (transaction rolled back)
        $user->refresh();
        $this->assertEquals(UserPaperStatus::WaitForApproval->value, $user->paper_status);

        // Assert the portrait was NOT rejected (transaction rolled back)
        // The admin needs to retry or reject the portrait manually
        $userPaperItem->refresh();
        $this->assertNull($userPaperItem->rejected_at);
        $this->assertNull($userPaperItem->approved_at);

        // Assert no user face was created
        $this->assertDatabaseMissing('user_faces', [
            'user_id' => $user->id,
        ]);

        // The rolled back approval must not leave an avatar behind either
        $this->assertDatabaseHas('users', ['id' => $user->id, 'profile_picture' => null]);
    }

    public function testAPortraitRefusedByFaceRecognitionDoesNotTouchTheAvatar()
    {
        Storage::fake(config('filesystems.default'));

        $admin = User::factory()->create();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $userPaper = UserPaper::create(['user_id' => $user->id]);

        // The employee is using a picture of their own.
        $ownPicture = "users/{$user->id}/EoX2mQ7bTfKcZ1pRs9vLdE4hNu6jYwGi3ArBnKtQ.jpg";
        Storage::put($ownPicture, 'own-picture-bytes');
        $user->update(['profile_picture' => $ownPicture]);

        $oldPortrait = $this->makeApprovedPortrait($userPaper->id, 'old-portrait.jpg', now()->subYear());

        // The renewed portrait is approved by the admin but refused by the provider.
        $newPortraitPath = "users/{$user->id}/papers/new-portrait.jpg";
        Storage::put($newPortraitPath, UploadedFile::fake()->image('new.jpg', 800, 800)->getContent());
        $newPortrait = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'file_path' => $newPortraitPath,
            'file_name' => 'new.jpg',
        ]);

        // Registration falls back to the older portrait, so the batch commits instead
        // of rolling back - and the refusal of the new portrait sticks.
        $this->faceRecognitionProvider
            ->shouldReceive('addUser')
            ->with((string) $user->id, $newPortraitPath)
            ->andReturn(new AddUserResponse(success: false, vectorId: null, isInvalidImage: true));
        $this->faceRecognitionProvider
            ->shouldReceive('addUser')
            ->with((string) $user->id, $oldPortrait->file_path)
            ->andReturn(new AddUserResponse(success: true, vectorId: 'vector-old'));

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin);
        $action($user, [['id' => $newPortrait->id, 'approved' => true]]);

        $this->assertNotNull($newPortrait->refresh()->rejected_at);

        // The only portrait decided in this round was refused, so nothing here should
        // swap the employee's own picture for the year-old portrait.
        $this->assertSame($ownPicture, $user->refresh()->profile_picture);
    }

    public function testRejectionReopensStepsForRejectedItemsOnly()
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $userPaper = UserPaper::create([
            'user_id' => $user->id,
        ]);

        $passportItem = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'passport',
            'file_path' => 'path/to/passport.jpg',
            'file_name' => 'passport.jpg',
        ]);

        $portraitItem = UserPaperItem::create([
            'user_paper_id' => $userPaper->id,
            'type' => 'portrait_image',
            'file_path' => 'path/to/portrait.jpg',
            'file_name' => 'portrait.jpg',
        ]);

        $passportStep = UserPaperStep::create([
            'user_paper_id' => $userPaper->id,
            'step_key' => UserPaperStepKey::Passport->value,
            'status' => UserPaperStepStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);

        $portraitStep = UserPaperStep::create([
            'user_paper_id' => $userPaper->id,
            'step_key' => UserPaperStepKey::Portrait->value,
            'status' => UserPaperStepStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);

        $itemDecisions = [
            ['id' => $passportItem->id, 'approved' => false],
            ['id' => $portraitItem->id, 'approved' => true],
        ];

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull($action($user, $itemDecisions, 'Blurry passport photo')->getError());

        // The rejected passport step retains the state that must be completed before resubmission
        $this->assertDatabaseHas('user_paper_steps', [
            'id' => $passportStep->id,
            'status' => UserPaperStepStatus::NeedsReupload->value,
            'ocr_run_count' => 0,
            'ocr_result_id' => null,
            'confirmed_at' => null,
            'confirmed_fields' => null,
        ]);

        // The approved portrait step stays confirmed
        $this->assertDatabaseHas('user_paper_steps', [
            'id' => $portraitStep->id,
            'status' => UserPaperStepStatus::Confirmed->value,
        ]);

        $this->assertEquals(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);
    }

    /**
     * 留学 permits work only while the student is enrolled, and the roster reads the student
     * card expiry date to see whether that is still true. A card on file whose date was never
     * recorded leaves that check with nothing to test, so the approval is refused.
     */
    public function testStudyAbroadFileWithAStudentCardButNoExpiryDateIsNotApproved()
    {
        $admin = User::factory()->create();
        $user = $this->makeStudyAbroadUser(studentCardExpiryDate: null);
        $items = $this->makePendingItems($user);

        $result = $this->actingAs($admin)->approve($user, $this->approveAll($items));

        $this->assertSame(ErrorCode::UserPaperStudentCardExpiryMissing, $result->getError());
        $this->assertSame(422, $result->getStatus());

        // Refused before anything is written: the file is still waiting, the documents are
        // still pending, and no staff no was handed out
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
        $this->assertNull($user->staff_no);
        $this->assertNull($items[0]->refresh()->approved_at);
        Mail::assertNothingSent();
    }

    public function testStudyAbroadFileIsApprovedOnceTheStudentCardExpiryDateIsFilledIn()
    {
        $admin = User::factory()->create();
        $user = $this->makeStudyAbroadUser(studentCardExpiryDate: '2027-03-31');
        $items = $this->makePendingItems($user);

        $result = $this->actingAs($admin)->approve($user, $this->approveAll($items));

        $this->assertNull($result->getError());
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
        $this->assertNotNull($items[0]->refresh()->approved_at);
    }

    /**
     * An employee who never filed a student card is not missing a date — they are missing a
     * document, which the missing-required-documents warning already reports and which an
     * admin is allowed to approve around (that is what a completion request is for). Holding
     * the approval here would strand every 留学 file approved before the card was mandatory.
     */
    public function testStudyAbroadFileWithNoStudentCardAtAllIsApproved()
    {
        $admin = User::factory()->create();
        $user = $this->makeStudyAbroadUser(studentCardExpiryDate: null);
        $items = $this->makePendingItems($user, withStudentCard: false);

        $result = $this->actingAs($admin)->approve($user, $this->approveAll($items));

        $this->assertNull($result->getError());
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
    }

    /**
     * A student card still waiting to be confirmed is not on file. The confirm form is what
     * asks for the date, so a file that has not reached it owes the document — which the
     * missing-documents warning reports — not the date.
     */
    public function testAnUnconfirmedStudentCardDoesNotHoldTheApprovalBack()
    {
        $admin = User::factory()->create();
        $user = $this->makeStudyAbroadUser(studentCardExpiryDate: null);
        $items = $this->makePendingItems($user);
        UserPaperStep::query()
            ->where('step_key', UserPaperStepKey::StudentCard->value)
            ->update(['status' => UserPaperStepStatus::OcrReady->value, 'confirmed_at' => null]);

        $result = $this->actingAs($admin)->approve($user, $this->approveAll($items));

        $this->assertNull($result->getError());
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
    }

    /**
     * A rejection grants nothing — it sends the documents back — so it must still go through.
     * Otherwise the admin who spots the missing date has no way to act on the file at all.
     */
    public function testStudyAbroadFileCanStillBeRejectedWithoutStudentCardExpiryDate()
    {
        $admin = User::factory()->create();
        $user = $this->makeStudyAbroadUser(studentCardExpiryDate: null);
        $items = $this->makePendingItems($user);
        $decisions = $this->approveAll($items);
        $decisions[0]['approved'] = false;

        $result = $this->actingAs($admin)->approve($user, $decisions, 'Unreadable');

        $this->assertNull($result->getError());
        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);
        $this->assertNotNull($items[0]->refresh()->rejected_at);
    }

    /**
     * Only 留学 is held to it. Every other status carries its work permission on the
     * residence card itself, so there is no student card to expire.
     */
    public function testAnotherResidenceStatusIsApprovedWithoutAStudentCardExpiryDate()
    {
        $admin = User::factory()->create();
        $user = $this->makeStudyAbroadUser(studentCardExpiryDate: null);
        $user->info->update(['residence_status_1' => ResidenceStatus::PermanentResident->value]);
        $items = $this->makePendingItems($user->refresh());

        $result = $this->actingAs($admin)->approve($user, $this->approveAll($items));

        $this->assertNull($result->getError());
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
    }

    /**
     * The status that counts is the one on the newest card. An employee who renewed out of
     * 留学 is not asked for a student card they no longer need, and one who renewed into it is.
     */
    public function testTheNewestResidenceCardDecidesWhetherTheDateIsRequired()
    {
        $admin = User::factory()->create();
        $user = $this->makeStudyAbroadUser(studentCardExpiryDate: null);
        // Older slot still says 留学; the card in force says 永住者
        $user->info->update([
            'residence_expiry_date_1' => now()->subYear()->toDateString(),
            'residence_status_2' => ResidenceStatus::PermanentResident->value,
            'residence_expiry_date_2' => now()->addYears(2)->toDateString(),
        ]);
        $items = $this->makePendingItems($user->refresh());

        $this->assertNull($this->actingAs($admin)->approve($user, $this->approveAll($items))->getError());
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
    }

    private function makeStudyAbroadUser(?string $studentCardExpiryDate): User
    {
        $user = User::factory()->create(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $user->info->update([
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_1' => 'AB12345678CD',
            'residence_expiry_date_1' => now()->addYears(2)->toDateString(),
            'student_card_expiry_date' => $studentCardExpiryDate,
        ]);

        return $user->refresh();
    }

    /**
     * The state a 留学 submission stands in when an admin opens it: every step confirmed,
     * every image still pending review.
     *
     * The confirmed step row is written directly here; DocumentStepSubmitTest reaches the
     * same state through the real upload → OCR → submit endpoints.
     *
     * @return UserPaperItem[] the passport first, then both sides of the student card
     */
    private function makePendingItems(User $user, bool $withStudentCard = true): array
    {
        $userPaper = UserPaper::firstOrCreate(['user_id' => $user->id]);

        $types = [UserPaperType::Passport->value];
        if ($withStudentCard) {
            $types[] = UserPaperType::StudentCardFront->value;
            $types[] = UserPaperType::StudentCardBack->value;

            UserPaperStep::create([
                'user_paper_id' => $userPaper->id,
                'step_key' => UserPaperStepKey::StudentCard->value,
                'status' => UserPaperStepStatus::Confirmed->value,
                'confirmed_at' => now(),
            ]);
        }

        return array_map(
            fn(string $type) => UserPaperItem::create([
                'user_paper_id' => $userPaper->id,
                'type' => $type,
                'file_path' => "path/to/{$type}.jpg",
                'file_name' => "{$type}.jpg",
            ]),
            $types
        );
    }

    /**
     * @param UserPaperItem[] $items
     */
    private function approveAll(array $items): array
    {
        return array_map(fn(UserPaperItem $item) => ['id' => $item->id, 'approved' => true], $items);
    }

    private function approve(User $user, array $itemDecisions, ?string $rejectedNote = null): ActionResult
    {
        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );

        return $action($user, $itemDecisions, $rejectedNote);
    }

    private function makeApprovedPortrait(int $userPaperId, string $name, $approvedAt): UserPaperItem
    {
        $path = "users/{$userPaperId}/papers/{$name}";
        Storage::put($path, UploadedFile::fake()->image($name, 800, 800)->getContent());

        return UserPaperItem::create([
            'user_paper_id' => $userPaperId,
            'type' => UserPaperType::PortraitImage->value,
            'file_path' => $path,
            'file_name' => $name,
            'approved_at' => $approvedAt,
        ]);
    }
}
