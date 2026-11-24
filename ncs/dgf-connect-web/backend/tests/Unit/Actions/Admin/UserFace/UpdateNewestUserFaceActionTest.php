<?php

namespace Tests\Unit\Actions\Admin\UserFace;

use App\Actions\Admin\UserFace\UpdateNewestUserFaceAction;
use App\Contracts\FaceRecognitionProviderInterface;
use App\Enums\ErrorCode;
use App\Enums\FaceRecognitionProviderName;
use App\Enums\UserPaperType;
use App\Models\User;
use App\Models\UserFace;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Services\FaceRecognition\Responses\AddUserResponse;
use App\Services\FaceRecognition\Responses\RemoveUserResponse;
use App\Services\FaceRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class UpdateNewestUserFaceActionTest extends TestCase
{
    use RefreshDatabase;

    private $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockProvider = Mockery::mock(FaceRecognitionProviderInterface::class);
        $this->app->instance(FaceRecognitionProviderInterface::class, $this->mockProvider);
        Log::spy();
    }

    /**
     * FIRST-TIME REGISTRATION TESTS
     */
    public function testCreatesNewUserFaceWhenNoExistingRecordAndRegistrationSucceeds()
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $portrait = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
            'file_path' => 'papers/portrait.jpg',
        ]);

        $this->mockProvider
            ->shouldReceive('addUser')
            ->once()
            ->with((string) $user->id, $portrait->file_path)
            ->andReturn(new AddUserResponse(true, 'vector-123'));

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));
        $result = $action($user->id);

        $this->assertNull($result->getError());
        $this->assertEquals(201, $result->getStatus());
        $this->assertArrayHasKey('user_face', $result->getData());
        $this->assertArrayHasKey('failed_portraits', $result->getData());

        $userFace = $result->getData()['user_face'];
        $this->assertEquals($user->id, $userFace->user_id);
        $this->assertEquals($portrait->id, $userFace->user_paper_item_id);
        $this->assertEquals('vector-123', $userFace->vector_id);
        $this->assertEquals(FaceRecognitionProviderName::Rekognition->value, $userFace->provider);

        $this->assertDatabaseHas('user_faces', [
            'user_id' => $user->id,
            'user_paper_item_id' => $portrait->id,
            'vector_id' => 'vector-123',
        ]);
    }

    public function testCreatesNewUserFaceWithFallbackWhenFirstPortraitFails()
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);

        // Create two portraits, newest first (higher ID)
        $portrait1 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
            'file_path' => 'papers/portrait1.jpg',
        ]);
        $portrait2 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
            'file_path' => 'papers/portrait2.jpg',
        ]);

        // First attempt (newest portrait) fails, second succeeds
        $this->mockProvider
            ->shouldReceive('addUser')
            ->once()
            ->with((string) $user->id, $portrait2->file_path)
            ->andReturn(new AddUserResponse(false, null, true));

        $this->mockProvider
            ->shouldReceive('addUser')
            ->once()
            ->with((string) $user->id, $portrait1->file_path)
            ->andReturn(new AddUserResponse(true, 'vector-456'));

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));
        $result = $action($user->id);

        $this->assertNull($result->getError());
        $this->assertEquals(201, $result->getStatus());

        $userFace = $result->getData()['user_face'];
        $this->assertEquals($portrait1->id, $userFace->user_paper_item_id);
        $this->assertEquals('vector-456', $userFace->vector_id);

        $failedPortraits = $result->getData()['failed_portraits'];
        $this->assertContains($portrait2->id, $failedPortraits);
        $this->assertNotContains($portrait1->id, $failedPortraits);
    }

    public function testReturnsErrorWhenNoApprovedPortraitsExist()
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);

        // Create unapproved portrait
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => null,
            'file_path' => 'papers/portrait.jpg',
        ]);

        $this->mockProvider->shouldNotReceive('addUser');

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));
        $result = $action($user->id);

        $this->assertEquals(ErrorCode::UserFaceNoApprovedPortrait, $result->getError());
        $this->assertDatabaseMissing('user_faces', ['user_id' => $user->id]);
    }

    public function testReturnsErrorWhenAllRegistrationAttemptsFailForNewUser()
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);

        $portrait1 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
            'file_path' => 'papers/portrait1.jpg',
        ]);
        $portrait2 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
            'file_path' => 'papers/portrait2.jpg',
        ]);

        // All attempts fail due to invalid images
        $this->mockProvider->shouldReceive('addUser')->twice()->andReturn(new AddUserResponse(false, null, true));

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));
        $result = $action($user->id);

        $this->assertEquals(ErrorCode::UserFaceRegistrationFailed, $result->getError());
        $this->assertEquals(500, $result->getStatus());

        $failedPortraits = $result->getData()['failed_portraits'];
        $this->assertContains($portrait1->id, $failedPortraits);
        $this->assertContains($portrait2->id, $failedPortraits);

        $this->assertDatabaseMissing('user_faces', ['user_id' => $user->id]);

        Log::shouldHaveReceived('error')->once()->with('All face registration attempts failed', Mockery::type('array'));
    }

    /**
     * UPDATE EXISTING USERFACE TESTS
     */
    public function testUpdatesExistingUserFaceWhenNewerPortraitRegistrationSucceeds()
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);

        $oldPortrait = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now()->subDays(2),
            'file_path' => 'papers/old-portrait.jpg',
        ]);

        $existingUserFace = UserFace::factory()->create([
            'user_id' => $user->id,
            'user_paper_item_id' => $oldPortrait->id,
            'vector_id' => 'old-vector-123',
            'provider' => FaceRecognitionProviderName::Rekognition->value,
        ]);

        $newPortrait = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
            'file_path' => 'papers/new-portrait.jpg',
        ]);

        $this->mockProvider
            ->shouldReceive('addUser')
            ->once()
            ->with((string) $user->id, $newPortrait->file_path)
            ->andReturn(new AddUserResponse(true, 'new-vector-456'));

        $this->mockProvider
            ->shouldReceive('removeUser')
            ->once()
            ->with((string) $user->id, 'old-vector-123')
            ->andReturn(new RemoveUserResponse(true, 1));

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));

        DB::transaction(function () use ($action, $user, &$result) {
            $result = $action($user->id);
        });

        $this->assertNull($result->getError());
        $this->assertEquals(200, $result->getStatus());

        $userFace = $result->getData()['user_face'];
        $this->assertEquals($newPortrait->id, $userFace->user_paper_item_id);
        $this->assertEquals('new-vector-456', $userFace->vector_id);

        $this->assertDatabaseHas('user_faces', [
            'user_id' => $user->id,
            'user_paper_item_id' => $newPortrait->id,
            'vector_id' => 'new-vector-456',
        ]);
    }

    public function testUpdatesUserFaceWithFallbackWhenFirstNewerPortraitFails()
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);

        $oldPortrait = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now()->subDays(3),
        ]);

        $existingUserFace = UserFace::factory()->create([
            'user_id' => $user->id,
            'user_paper_item_id' => $oldPortrait->id,
            'vector_id' => 'old-vector',
        ]);

        $newPortrait1 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now()->subDay(),
            'file_path' => 'papers/new1.jpg',
        ]);
        $newPortrait2 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
            'file_path' => 'papers/new2.jpg',
        ]);

        // First newer portrait (newest) fails, second succeeds
        $this->mockProvider
            ->shouldReceive('addUser')
            ->once()
            ->with((string) $user->id, $newPortrait2->file_path)
            ->andReturn(new AddUserResponse(false, null, true));

        $this->mockProvider
            ->shouldReceive('addUser')
            ->once()
            ->with((string) $user->id, $newPortrait1->file_path)
            ->andReturn(new AddUserResponse(true, 'new-vector-789'));

        $this->mockProvider
            ->shouldReceive('removeUser')
            ->once()
            ->with((string) $user->id, 'old-vector')
            ->andReturn(new RemoveUserResponse(true, 1));

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));

        DB::transaction(function () use ($action, $user, &$result) {
            $result = $action($user->id);
        });

        $this->assertNull($result->getError());
        $this->assertEquals(200, $result->getStatus());

        $userFace = $result->getData()['user_face'];
        $this->assertEquals($newPortrait1->id, $userFace->user_paper_item_id);

        $failedPortraits = $result->getData()['failed_portraits'];
        $this->assertContains($newPortrait2->id, $failedPortraits);
    }

    public function testReturnsErrorWhenNoNewerPortraitsAvailable()
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);

        $portrait = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
        ]);

        $existingUserFace = UserFace::factory()->create([
            'user_id' => $user->id,
            'user_paper_item_id' => $portrait->id,
            'vector_id' => 'current-vector',
        ]);

        $this->mockProvider->shouldNotReceive('addUser');

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));
        $result = $action($user->id);

        $this->assertEquals(ErrorCode::UserFaceNoApprovedPortrait, $result->getError());

        // Verify no changes to existing record
        $this->assertDatabaseHas('user_faces', [
            'user_id' => $user->id,
            'user_paper_item_id' => $portrait->id,
            'vector_id' => 'current-vector',
        ]);
    }

    public function testReturnsErrorWhenAllUpdateAttemptsFail()
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);

        $oldPortrait = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now()->subDays(2),
        ]);

        $existingUserFace = UserFace::factory()->create([
            'user_id' => $user->id,
            'user_paper_item_id' => $oldPortrait->id,
            'vector_id' => 'old-vector',
        ]);

        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
        ]);

        // All attempts fail
        $this->mockProvider->shouldReceive('addUser')->once()->andReturn(new AddUserResponse(false, null));

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));
        $result = $action($user->id);

        $this->assertEquals(ErrorCode::UserFaceUpdateFailed, $result->getError());
        $this->assertEquals(500, $result->getStatus());

        // Verify no changes to existing record
        $this->assertDatabaseHas('user_faces', [
            'user_id' => $user->id,
            'user_paper_item_id' => $oldPortrait->id,
            'vector_id' => 'old-vector',
        ]);

        Log::shouldHaveReceived('error')->once()->with('All face registration attempts failed', Mockery::type('array'));
    }

    /**
     * EDGE CASES & BEHAVIOR VERIFICATION TESTS
     */
    public function testOldFaceRemovalScheduledAfterSuccessfulUpdate()
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);

        $oldPortrait = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now()->subDays(2),
        ]);

        UserFace::factory()->create([
            'user_id' => $user->id,
            'user_paper_item_id' => $oldPortrait->id,
            'vector_id' => 'old-vector-to-remove',
        ]);

        $newPortrait = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
        ]);

        $this->mockProvider->shouldReceive('addUser')->once()->andReturn(new AddUserResponse(true, 'new-vector'));

        $this->mockProvider
            ->shouldReceive('removeUser')
            ->once()
            ->with((string) $user->id, 'old-vector-to-remove')
            ->andReturn(new RemoveUserResponse(true, 1));

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));

        DB::transaction(function () use ($action, $user) {
            $action($user->id);
        });
    }

    public function testUsesCorrectProviderFromConfig()
    {
        Config::set('services.face_recognition.provider', 'rekognition');

        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $portrait = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
        ]);

        $this->mockProvider->shouldReceive('addUser')->once()->andReturn(new AddUserResponse(true, 'vector-abc'));

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));
        $result = $action($user->id);

        $userFace = $result->getData()['user_face'];
        $this->assertEquals(FaceRecognitionProviderName::Rekognition->value, $userFace->provider);
    }

    public function testQueriesOnlyApprovedPortraitImages()
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);

        // Create various paper items
        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => null, // Not approved
        ]);

        UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::Passport->value, // Wrong type
            'approved_at' => now(),
        ]);

        $validPortrait = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
            'file_path' => 'papers/valid.jpg',
        ]);

        // Should only call addUser once with the valid portrait
        $this->mockProvider
            ->shouldReceive('addUser')
            ->once()
            ->with((string) $user->id, $validPortrait->file_path)
            ->andReturn(new AddUserResponse(true, 'vector-valid'));

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));
        $result = $action($user->id);

        $this->assertNull($result->getError());
        $this->assertEquals(201, $result->getStatus());
    }

    public function testPortraitsOrderedByIdDescending()
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);

        // Create portraits in sequence (IDs will increment)
        $portrait1 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now()->subDays(2),
            'file_path' => 'papers/portrait1.jpg',
        ]);

        $portrait2 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now()->subDay(),
            'file_path' => 'papers/portrait2.jpg',
        ]);

        $portrait3 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
            'file_path' => 'papers/portrait3.jpg',
        ]);

        // Should try newest first (portrait3), and that's the only call since it succeeds
        $this->mockProvider
            ->shouldReceive('addUser')
            ->once()
            ->with((string) $user->id, $portrait3->file_path)
            ->andReturn(new AddUserResponse(true, 'vector-newest'));

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));
        $result = $action($user->id);

        $userFace = $result->getData()['user_face'];
        $this->assertEquals($portrait3->id, $userFace->user_paper_item_id);
    }

    public function testServiceErrorDoesNotRejectPortrait()
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);

        $portrait = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
            'file_path' => 'papers/portrait.jpg',
        ]);

        // Mock service error (isInvalidImage = false)
        $this->mockProvider
            ->shouldReceive('addUser')
            ->once()
            ->with((string) $user->id, $portrait->file_path)
            ->andReturn(new AddUserResponse(false, null, false));

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));
        $result = $action($user->id);

        // Should return error but portrait should NOT be in failed list
        $this->assertNotNull($result->getError());
        $failedPortraits = $result->getData()['failed_portraits'];
        $this->assertEmpty($failedPortraits);

        // Portrait should remain approved
        $portrait->refresh();
        $this->assertNotNull($portrait->approved_at);
        $this->assertNull($portrait->rejected_at);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function ($message, $context) use ($portrait) {
                return $message === 'Face registration failed due to service error, portrait not rejected' &&
                    $context['portrait_id'] === $portrait->id;
            });
    }

    public function testImageErrorRejectsPortrait()
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);

        $portrait = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::PortraitImage->value,
            'approved_at' => now(),
            'file_path' => 'papers/portrait.jpg',
        ]);

        // Mock image error (isInvalidImage = true)
        $this->mockProvider
            ->shouldReceive('addUser')
            ->once()
            ->with((string) $user->id, $portrait->file_path)
            ->andReturn(new AddUserResponse(false, null, true));

        $action = new UpdateNewestUserFaceAction(app(FaceRecognitionService::class));
        $result = $action($user->id);

        // Should return error and portrait should be in failed list
        $this->assertNotNull($result->getError());
        $failedPortraits = $result->getData()['failed_portraits'];
        $this->assertContains($portrait->id, $failedPortraits);

        // Portrait should be rejected
        $portrait->refresh();
        $this->assertNull($portrait->approved_at);
        $this->assertNotNull($portrait->rejected_at);
    }
}
