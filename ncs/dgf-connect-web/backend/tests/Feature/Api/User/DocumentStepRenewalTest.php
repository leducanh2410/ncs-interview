<?php

namespace Tests\Feature\Api\User;

use App\Actions\Admin\User\ApprovePapersAction;
use App\Actions\Admin\User\SyncUserAvatarFromPortraitAction;
use App\Actions\Common\Poll\EvaluateUserAfterStaffNoAssignmentAction;
use App\Contracts\FaceRecognitionProviderInterface;
use App\Enums\ErrorCode;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Models\User;
use App\Models\UserPaperItem;
use App\Models\UserPaperOcrResult;
use App\Models\UserPaperStep;
use App\Services\FaceRecognition\Responses\AddUserResponse;
use App\Services\FaceRecognition\Responses\RemoveUserResponse;
use App\Services\FaceRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\Concerns\FakesDocumentOcr;
use Tests\Concerns\ResetsUserDocumentInfo;
use Tests\TestCase;

/**
 * Drives the full employee document lifecycle through the real endpoints and actions
 * (upload → confirm → admin approval → renewal), so every intermediate state is one
 * the system actually produces — no hand-crafted step rows.
 */
class DocumentStepRenewalTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use FakesDocumentOcr;
    use RefreshDatabase;
    use ResetsUserDocumentInfo;

    private $faceRecognitionProvider;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Queue::fake();
        Mail::fake();
        Event::fake();

        $this->faceRecognitionProvider = Mockery::mock(FaceRecognitionProviderInterface::class);
        $this->app->instance(FaceRecognitionProviderInterface::class, $this->faceRecognitionProvider);

        $this->fakeExternalServices();
    }

    /**
     * The OCR provider answers from $ocrFieldOverrides, which a test staging a document
     * that disagrees sets before it uploads.
     */
    private function fakeExternalServices(): void
    {
        Http::fake([
            'getMemberListExternal*' => Http::response([
                'GetMemberListExternal' => ['ResultSet' => ['TotalResult' => 0, 'Error' => null]],
            ]),
            'insMemberExternal*' => Http::response([
                'InsMemberExternal' => ['ResultSet' => ['Error' => null]],
            ]),
            'updMemberExternal*' => Http::response([
                'UpdMemberExternal' => ['ResultSet' => ['Error' => null]],
            ]),
            ...$this->documentOcrProviderStub(),
        ]);
    }

    public function testApprovedUserCanRenewDocumentsAfterFullLifecycle(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);

        $this->submitAllRequiredSteps($user);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);

        // Admin approves the whole submission through the real action
        $this->decideAllPendingItems($user, approved: true);

        $user->refresh();
        $this->assertSame(UserPaperStatus::Approved->value, $user->paper_status);
        $this->assertFalse($user->paper()->firstOrFail()->submission_started_from_approved);

        // Approval must re-open every step so the employee can renew documents later
        $this->assertSame(0, UserPaperStep::query()->count());

        // Renewal: the approved user replaces an expiring passport
        $approvedPassportIds = UserPaperItem::query()
            ->where('type', UserPaperType::Passport->value)
            ->pluck('id')
            ->all();

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [
                    UserPaperType::Passport->value => [UploadedFile::fake()->image('new-page1.jpg')],
                ],
            ])
            ->assertStatus(200);

        // The renewal OCR run must only look at the new pending pages, never the approved history
        $renewalResult = UserPaperOcrResult::query()->orderByDesc('id')->firstOrFail();
        $this->assertCount(1, $renewalResult->item_ids);
        $this->assertEmpty(array_intersect($approvedPassportIds, $renewalResult->item_ids));

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'D7654321',
                'passport_expiry_date' => '2035-06-01',
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::Passport),
            ])
            ->assertStatus(200);

        // A single renewed document sends the approved user straight back into review
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
        $this->assertTrue($user->paper()->firstOrFail()->submission_started_from_approved);

        $this->decideAllPendingItems($user, approved: true);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
        $this->assertFalse($user->paper()->firstOrFail()->submission_started_from_approved);
    }

    public function testApprovalSetsTheAccountAvatarFromTheApprovedPortraitAcrossRenewals(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);

        $this->submitAllRequiredSteps($user);
        $this->decideAllPendingItems($user, approved: true);

        $firstPortrait = $this->newestPortrait($user);
        $user->refresh();
        $this->assertSame("users/{$user->id}/avatar_{$firstPortrait->id}.jpg", $user->profile_picture);
        $this->assertTrue(Storage::exists($user->profile_picture));

        // The avatar is a resized copy: the portrait document itself must stay in place
        $this->assertNotSame($firstPortrait->file_path, $user->profile_picture);
        $this->assertTrue(Storage::exists($firstPortrait->file_path));

        $firstAvatarPath = $user->profile_picture;

        // Renewal: the approved employee replaces the portrait and the admin approves again
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/portrait/upload', [
                'files' => [
                    UserPaperType::PortraitImage->value => UploadedFile::fake()->image('portrait-renewal.jpg'),
                ],
            ])
            ->assertStatus(200);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);

        // Registering the renewed portrait retires the face registered from the first one
        $this->faceRecognitionProvider->shouldReceive('removeUser')->andReturn(new RemoveUserResponse(success: true));

        $this->decideAllPendingItems($user, approved: true);

        $renewedPortrait = $this->newestPortrait($user);
        $this->assertNotSame($firstPortrait->id, $renewedPortrait->id);

        $user->refresh();
        $this->assertSame("users/{$user->id}/avatar_{$renewedPortrait->id}.jpg", $user->profile_picture);
        $this->assertTrue(Storage::exists($user->profile_picture));
        $this->assertFalse(Storage::exists($firstAvatarPath));
    }

    public function testApprovingARenewalWithoutAPortraitLeavesTheAvatarAlone(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);

        $this->submitAllRequiredSteps($user);
        $this->decideAllPendingItems($user, approved: true);

        // The employee replaces the avatar the approval built with one of their own
        $ownAvatar = "users/{$user->id}/own-avatar.jpg";
        Storage::put($ownAvatar, 'own-avatar-bytes');
        $user->refresh()->update(['profile_picture' => $ownAvatar]);

        // Renewing an unrelated document must not push the portrait back onto the account
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [
                    UserPaperType::Passport->value => [UploadedFile::fake()->image('renewed-page1.jpg')],
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'D7654321',
                'passport_expiry_date' => '2035-06-01',
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::Passport),
            ])
            ->assertStatus(200);

        $this->decideAllPendingItems($user, approved: true);

        $this->assertSame($ownAvatar, $user->refresh()->profile_picture);
        $this->assertTrue(Storage::exists($ownAvatar));
    }

    public function testRejectedSubmissionLeavesTheAccountAvatarUntouched(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);

        $this->submitAllRequiredSteps($user);
        $this->decideAllPendingItems($user, approved: false, note: 'Blurry documents');

        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);
        $this->assertNull($user->profile_picture);
    }

    public function testApprovedPortraitReachesTheAvatarEvenWhenAnotherDocumentWasRejectedFirst(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);

        $this->submitAllRequiredSteps($user);

        // Mixed decision: the portrait is approved, only the passport is sent back.
        $this->decidePendingItemsRejecting($user, UserPaperType::Passport);

        $portrait = $this->newestPortrait($user);
        $this->assertNotNull($portrait->approved_at, 'the portrait is approved even though the batch was rejected');
        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);

        // The employee replaces only the passport, the portrait is already approved.
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [
                    UserPaperType::Passport->value => [UploadedFile::fake()->image('replacement.jpg')],
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'E1112223',
                'passport_expiry_date' => '2036-06-01',
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::Passport),
            ])
            ->assertStatus(200);

        $this->decideAllPendingItems($user, approved: true);

        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
        $this->assertSame("users/{$user->id}/avatar_{$portrait->id}.jpg", $user->profile_picture);
    }

    public function testRenewedPortraitReachesTheAvatarEvenWhenAnotherDocumentWasRejectedFirst(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);

        $this->submitAllRequiredSteps($user);
        $this->decideAllPendingItems($user, approved: true);

        $firstPortrait = $this->newestPortrait($user);
        $this->assertSame("users/{$user->id}/avatar_{$firstPortrait->id}.jpg", $user->refresh()->profile_picture);

        // The employee renews both the portrait and the passport.
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/portrait/upload', [
                'files' => [
                    UserPaperType::PortraitImage->value => UploadedFile::fake()->image('portrait-renewal.jpg'),
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [
                    UserPaperType::Passport->value => [UploadedFile::fake()->image('passport-renewal.jpg')],
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'D7654321',
                'passport_expiry_date' => '2035-06-01',
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::Passport),
            ])
            ->assertStatus(200);

        $this->faceRecognitionProvider->shouldReceive('removeUser')->andReturn(new RemoveUserResponse(success: true));

        // Mixed decision: the renewed portrait is approved, the passport is sent back.
        $this->decidePendingItemsRejecting($user, UserPaperType::Passport);

        $renewedPortrait = $this->newestPortrait($user);
        $this->assertNotSame($firstPortrait->id, $renewedPortrait->id);

        // The employee replaces the passport and the admin approves the rest.
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [
                    UserPaperType::Passport->value => [UploadedFile::fake()->image('replacement.jpg')],
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'E1112223',
                'passport_expiry_date' => '2036-06-01',
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::Passport),
            ])
            ->assertStatus(200);

        $this->decideAllPendingItems($user, approved: true);

        // The account must not be stuck on the portrait from the previous cycle.
        $this->assertSame("users/{$user->id}/avatar_{$renewedPortrait->id}.jpg", $user->refresh()->profile_picture);
    }

    public function testFirstSubmissionWithOptionalAndRequiredStepsCompletes(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/other/upload', [
                'files' => [
                    UserPaperType::Other->value => [UploadedFile::fake()->image('other.jpg')],
                ],
            ])
            ->assertStatus(200);
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);

        $this->submitAllRequiredSteps($user);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    public function testRejectedRenewalReturnsToReviewAfterReplacement(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);

        $this->submitAllRequiredSteps($user);
        $this->decideAllPendingItems($user, approved: true);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);

        // The approved user renews a single passport and lands back in review
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [
                    UserPaperType::Passport->value => [UploadedFile::fake()->image('renewal.jpg')],
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'D7654321',
                'passport_expiry_date' => '2035-06-01',
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::Passport),
            ])
            ->assertStatus(200);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);

        // The admin rejects the renewal, which re-opens only the passport step
        $this->decideAllPendingItems($user, approved: false, note: 'Blurry renewal photo');
        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);
        $this->assertSame(UserPaperStepStatus::NeedsReupload->value, UserPaperStep::query()->firstOrFail()->status);

        // The replacement must return the user to review; the other required documents
        // are still approved from the initial submission and must not hold them back
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [
                    UserPaperType::Passport->value => [UploadedFile::fake()->image('replacement.jpg')],
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'E1112223',
                'passport_expiry_date' => '2036-06-01',
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::Passport),
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    public function testLegacyMultipleRejectedRequiredRenewalsMustAllBeReplaced(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);
        $this->submitAllRequiredSteps($user);
        $this->decideAllPendingItems($user, approved: true);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [
                    UserPaperType::Passport->value => [UploadedFile::fake()->image('passport-renewal.jpg')],
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'D7654321',
                'passport_expiry_date' => '2035-06-01',
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::Passport),
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/portrait/upload', [
                'files' => [
                    UserPaperType::PortraitImage->value => UploadedFile::fake()->image('portrait-renewal.jpg'),
                ],
            ])
            ->assertStatus(200);

        $this->decideAllPendingItems($user, approved: false, note: 'Replace both documents');
        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);
        $this->assertSame(
            [UserPaperStepKey::Passport->value, UserPaperStepKey::Portrait->value],
            UserPaperStep::query()
                ->where('status', UserPaperStepStatus::NeedsReupload->value)
                ->pluck('step_key')
                ->sort()
                ->values()
                ->all()
        );

        // The previous revision deleted rejected rows. Reproduce that persisted legacy
        // representation after reaching the rejection through the real lifecycle.
        UserPaperStep::query()->delete();
        $overview = $this->actingAs($user, 'api')->getJson('/user/document-steps')->assertStatus(200);
        $steps = collect($overview->json('data.steps'))->keyBy('key');
        $this->assertTrue($steps[UserPaperStepKey::Passport->value]['needs_reupload']);
        $this->assertTrue($steps[UserPaperStepKey::Portrait->value]['needs_reupload']);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/portrait/upload', [
                'files' => [
                    UserPaperType::PortraitImage->value => UploadedFile::fake()->image('portrait-replacement.jpg'),
                ],
            ])
            ->assertStatus(200);
        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);

        // The admin must not be able to decide on the half-replaced submission: a second
        // decision would advance user_papers.approved_at, dropping the still-missing
        // passport out of the reconstructed legacy batch.
        $this->assertDecidingAllPendingItemsIsBlocked($user);
        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [
                    UserPaperType::Passport->value => [UploadedFile::fake()->image('passport-replacement.jpg')],
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'E1112223',
                'passport_expiry_date' => '2036-06-01',
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::Passport),
            ])
            ->assertStatus(200);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    public function testMultipleRejectedOptionalRenewalsMustAllBeReplaced(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);
        $this->submitAllRequiredSteps($user);
        $this->decideAllPendingItems($user, approved: true);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/work_permit/upload', [
                'files' => [
                    UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('work-permit.jpg'),
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/other/upload', [
                'files' => [
                    UserPaperType::Other->value => [UploadedFile::fake()->image('other.jpg')],
                ],
            ])
            ->assertStatus(200);

        $this->decideAllPendingItems($user, approved: false, note: 'Replace both optional documents');
        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/work_permit/upload', [
                'files' => [
                    UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('work-permit-new.jpg'),
                ],
            ])
            ->assertStatus(200);
        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);

        // A decision on the half-replaced optional set must be blocked as well
        $this->assertDecidingAllPendingItemsIsBlocked($user);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/other/upload', [
                'files' => [
                    UserPaperType::Other->value => [UploadedFile::fake()->image('other-new.jpg')],
                ],
            ])
            ->assertStatus(200);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    /**
     * A rejection that only touches a document without OCR must not take the mismatch
     * warning with it. Replacing that document runs no extraction and confirms no step,
     * so nothing would ever recompute the verdict, and the second review would happen
     * without the warning while the documents that disagree are still on file.
     */
    public function testRejectingADocumentWithoutOcrKeepsTheMismatchWarning(): void
    {
        // The scanned copy of the residence card names someone else
        $this->ocrFieldOverrides = ['residence_card_scan' => ['name' => 'TRAN THI B']];

        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);

        $this->submitAllRequiredSteps($user);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
        $this->assertSame(['name'], array_column($user->paper()->firstOrFail()->document_mismatches, 'field'));

        // The admin rejects only the portrait; the documents that disagree stay approved
        $this->decidePendingItemsRejecting($user, UserPaperType::PortraitImage);

        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);
        $this->assertSame(['name'], array_column($user->paper()->firstOrFail()->document_mismatches, 'field'));

        // The replacement portrait needs no confirmation, so it takes the paper straight
        // back into the queue — with the warning the second reviewer still needs
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/portrait/upload', [
                'files' => [UserPaperType::PortraitImage->value => UploadedFile::fake()->image('portrait-2.jpg')],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
        $this->assertSame(['name'], array_column($user->paper()->firstOrFail()->document_mismatches, 'field'));
    }

    /**
     * 家族滞在 carries no work permission on the card, so the submission owes a 資格外活動許可.
     * The per-step confirm cannot ask for it up front — the card is written before the
     * client learns what else is needed — so it has to say what is still outstanding
     * instead of answering 200 and leaving the paper sitting in pending for no visible
     * reason.
     */
    public function testPerStepConfirmNamesTheDocumentsTheDeclaredStatusStillOwes(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/upload', [
                'files' => [
                    UserPaperType::ForeignerCardFront->value => UploadedFile::fake()->image('front.jpg'),
                    UserPaperType::ForeignerCardBack->value => UploadedFile::fake()->image('back.jpg'),
                    UserPaperType::ForeignerCardScan->value => UploadedFile::fake()->image('scan.jpg'),
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/confirm', [
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::ResidenceCard),
                'residence_number' => 'AB12345678CD',
                'residence_expiry_date' => '2028-01-31',
                'residence_status' => ResidenceStatus::FamilyStay->value,
                'first_name' => 'Van A',
                'last_name' => 'Nguyen',
                'birthday' => '1998-04-12',
                'gender' => 'male',
                'nationality' => 'vietnam',
                'address' => 'Tokyo Shinjuku 2-8-1',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.missing_steps', [
                UserPaperStepKey::Passport->value,
                UserPaperStepKey::Portrait->value,
                UserPaperStepKey::WorkPermit->value,
            ]);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/portrait/upload', [
                'files' => [UserPaperType::PortraitImage->value => UploadedFile::fake()->image('portrait.jpg')],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [UserPaperType::Passport->value => [UploadedFile::fake()->image('page1.jpg')]],
            ])
            ->assertStatus(200);

        // Every step the old client knows about is done, and the paper still cannot move
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'C1234567',
                'passport_expiry_date' => '2030-06-01',
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::Passport),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.missing_steps', [UserPaperStepKey::WorkPermit->value]);

        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);

        // Uploading the document it named is what completes the submission
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/work_permit/upload', [
                'files' => [UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('stamp.jpg')],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    /**
     * An approval inherited by a renewal covers the documents that approval was granted
     * for, not the ones the status this renewal newly declares brings with it: an employee
     * approved as 定住者 coming back as 留学 has never had a 資格外活動許可 checked, and
     * going straight back into the queue would roster them without one.
     */
    public function testRenewalDeclaringStudyAbroadStillOwesTheStudentCardAndWorkPermit(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);

        $this->submitAllRequiredSteps($user);
        $this->decideAllPendingItems($user, approved: true);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);

        // The renewal declares a status whose paperwork no earlier approval ever saw
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/upload', [
                'files' => [
                    UserPaperType::ForeignerCardFront->value => UploadedFile::fake()->image('front-2.jpg'),
                    UserPaperType::ForeignerCardBack->value => UploadedFile::fake()->image('back-2.jpg'),
                    UserPaperType::ForeignerCardScan->value => UploadedFile::fake()->image('scan-2.jpg'),
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/confirm', [
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::ResidenceCard),
                'residence_number' => 'AB12345678CD',
                'residence_expiry_date' => '2031-01-31',
                'residence_status' => ResidenceStatus::StudyAbroad->value,
                'first_name' => 'Van A',
                'last_name' => 'Nguyen',
                'birthday' => '1998-04-12',
                'gender' => 'male',
                'nationality' => 'vietnam',
                'address' => 'Tokyo Shinjuku 2-8-1',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.missing_steps', [
                UserPaperStepKey::StudentCard->value,
                UserPaperStepKey::WorkPermit->value,
            ]);

        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);

        // Filing both is what sends the renewal back for review
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/student_card/upload', [
                'files' => [
                    UserPaperType::StudentCardFront->value => UploadedFile::fake()->image('sc-front.jpg'),
                    UserPaperType::StudentCardBack->value => UploadedFile::fake()->image('sc-back.jpg'),
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/student_card/confirm', [
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::StudentCard),
                'student_card_expiry_date' => '2027-03-31',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.missing_steps', [UserPaperStepKey::WorkPermit->value]);

        // The card was written two requests ago, so the status now on the profile is 留学.
        // The gate answers from the set the previous approval covered, not from that.
        // The card was written two requests ago, so the status now on the profile is 留学.
        // The gate answers from the set the previous approval covered, not from that —
        // and while it holds, the approval that status never had is not handed back
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/work_permit/upload', [
                'files' => [UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('stamp.jpg')],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    /**
     * The document a renewing employee replaces most often runs no OCR and confirms no
     * step: it flips the paper straight to review from the upload. It must read the same
     * inherited set as the confirm road, or 留学 walks in without either extra document.
     */
    public function testAPortraitReuploadDoesNotReleaseARenewalThatDeclaredStudyAbroad(): void
    {
        $user = $this->createUser();
        $this->declareStudyAbroadOnRenewal($user);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/portrait/upload', [
                'files' => [UserPaperType::PortraitImage->value => UploadedFile::fake()->image('portrait-2.jpg')],
            ])
            ->assertStatus(200);

        // The road a renewing employee takes most often: no OCR, no step confirm. It must
        // read the same gate, so the profile still declaring 留学 keeps the approval off
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
    }

    /**
     * Same gate, one request later on the confirm road: the passport is a step the earlier
     * approval did cover, so confirming it must not stand in for the two it never saw.
     */
    public function testAPassportConfirmDoesNotReleaseARenewalThatDeclaredStudyAbroad(): void
    {
        $user = $this->createUser();
        $this->declareStudyAbroadOnRenewal($user);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [
                    UserPaperType::Passport->value => [
                        UploadedFile::fake()->image('page1-2.jpg'),
                        UploadedFile::fake()->image('page2-2.jpg'),
                    ],
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'C1234567',
                'passport_expiry_date' => '2030-06-01',
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::Passport),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.missing_steps', [
                UserPaperStepKey::StudentCard->value,
                UserPaperStepKey::WorkPermit->value,
            ]);

        // Held back, and the approval goes with it — same gate as the confirm road
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
    }

    /**
     * The point of taking the approval away: CheckUserPaperStatus lets check-in, shift
     * registration and salary through on paper_status alone. While the profile declares a
     * status nobody reviewed, those must be shut, exactly as a completed submission waiting
     * for review already shuts them.
     */
    public function testARenewalOwingDocumentsLosesTheRoutesApprovalOpened(): void
    {
        $user = $this->createUser();
        $this->declareStudyAbroadOnRenewal($user);

        $this->actingAs($user, 'api')->getJson('/user/check-in/shift')->assertStatus(403);

        // Filing the two documents the declared status owes is what opens them again
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/student_card/upload', [
                'files' => [
                    UserPaperType::StudentCardFront->value => UploadedFile::fake()->image('sc-front.jpg'),
                    UserPaperType::StudentCardBack->value => UploadedFile::fake()->image('sc-back.jpg'),
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/student_card/confirm', [
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::StudentCard),
                'student_card_expiry_date' => '2027-03-31',
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/work_permit/upload', [
                'files' => [UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('stamp.jpg')],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);

        $this->decideAllPendingItems($user, approved: true);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
        $this->actingAs($user, 'api')->getJson('/user/check-in/shift')->assertStatus(200);
    }

    /**
     * The other half of the same rule. A renewal that has only uploaded so far has written
     * nothing to the profile — the employee is still exactly the person the last approval
     * described — so the approval stays until they declare something that owes documents.
     */
    public function testARenewalThatHasOnlyUploadedKeepsItsApproval(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);

        $this->submitAllRequiredSteps($user);
        $this->decideAllPendingItems($user, approved: true);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/upload', [
                'files' => [
                    UserPaperType::ForeignerCardFront->value => UploadedFile::fake()->image('front-2.jpg'),
                    UserPaperType::ForeignerCardBack->value => UploadedFile::fake()->image('back-2.jpg'),
                    UserPaperType::ForeignerCardScan->value => UploadedFile::fake()->image('scan-2.jpg'),
                ],
            ])
            ->assertStatus(200);
        // A step with no OCR runs the completeness check on upload, which is the road that
        // would strip the approval if the gate looked at anything wider than owed documents
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/portrait/upload', [
                'files' => [UserPaperType::PortraitImage->value => UploadedFile::fake()->image('portrait-2.jpg')],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
        $this->actingAs($user, 'api')->getJson('/user/check-in/shift')->assertStatus(200);
    }

    /**
     * The set a renewal inherits is recorded by the approval, not read from the profile
     * during the renewal — because the employee can write residence_status_1 themselves
     * through POST /user/profile while approved, and nothing there reopens their status.
     */
    public function testEditingTheResidenceStatusOnTheProfileDoesNotWidenWhatARenewalInherits(): void
    {
        $user = $this->createUser();
        $this->resetUserDocumentInfo($user);

        $this->submitAllRequiredSteps($user);
        $this->decideAllPendingItems($user, approved: true);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);

        // The approval recorded what it covered: 定住者 needs no student card, no work permit
        $this->assertSame(
            [
                UserPaperStepKey::ResidenceCard->value,
                UserPaperStepKey::Passport->value,
                UserPaperStepKey::Portrait->value,
            ],
            $user->paper()->firstOrFail()->approved_step_keys
        );

        // Declare 留学 on the profile before touching a single document. Nothing in
        // UpdateUserInfoRequest gates this field on paper_status, and the action only
        // touches paper_status when it is still `registered`.
        $this->actingAs($user, 'api')
            ->postJson('/user/profile', [
                'birthday' => '1998-04-12',
                'gender' => 'male',
                'nationality' => 'vietnam',
                'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            ])
            ->assertStatus(200);
        $this->assertSame(ResidenceStatus::StudyAbroad->value, $user->refresh()->info->residence_status_1);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/upload', [
                'files' => [
                    UserPaperType::ForeignerCardFront->value => UploadedFile::fake()->image('front-2.jpg'),
                    UserPaperType::ForeignerCardBack->value => UploadedFile::fake()->image('back-2.jpg'),
                    UserPaperType::ForeignerCardScan->value => UploadedFile::fake()->image('scan-2.jpg'),
                ],
            ])
            ->assertStatus(200);

        // The recorded set is untouched by the profile edit, so both documents are still owed
        $this->assertSame(
            [
                UserPaperStepKey::ResidenceCard->value,
                UserPaperStepKey::Passport->value,
                UserPaperStepKey::Portrait->value,
            ],
            $user->paper()->firstOrFail()->approved_step_keys
        );

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/confirm', [
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::ResidenceCard),
                'residence_number' => 'AB12345678CD',
                'residence_expiry_date' => '2031-01-31',
                'residence_status' => ResidenceStatus::StudyAbroad->value,
                'first_name' => 'Van A',
                'last_name' => 'Nguyen',
                'birthday' => '1998-04-12',
                'gender' => 'male',
                'nationality' => 'vietnam',
                'address' => 'Tokyo Shinjuku 2-8-1',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.missing_steps', [
                UserPaperStepKey::StudentCard->value,
                UserPaperStepKey::WorkPermit->value,
            ]);

        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
    }

    /**
     * Runs a full lifecycle to approval under 定住者, then opens a renewal whose residence
     * card declares 留学 — the state every gate above is asked about.
     */
    private function declareStudyAbroadOnRenewal(User $user): void
    {
        $this->resetUserDocumentInfo($user);

        $this->submitAllRequiredSteps($user);
        $this->decideAllPendingItems($user, approved: true);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/upload', [
                'files' => [
                    UserPaperType::ForeignerCardFront->value => UploadedFile::fake()->image('front-2.jpg'),
                    UserPaperType::ForeignerCardBack->value => UploadedFile::fake()->image('back-2.jpg'),
                    UserPaperType::ForeignerCardScan->value => UploadedFile::fake()->image('scan-2.jpg'),
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/confirm', [
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::ResidenceCard),
                'residence_number' => 'AB12345678CD',
                'residence_expiry_date' => '2031-01-31',
                'residence_status' => ResidenceStatus::StudyAbroad->value,
                'first_name' => 'Van A',
                'last_name' => 'Nguyen',
                'birthday' => '1998-04-12',
                'gender' => 'male',
                'nationality' => 'vietnam',
                'address' => 'Tokyo Shinjuku 2-8-1',
            ])
            ->assertStatus(200);

        // Held back, and the approval goes with it: the card on the profile now says 留学,
        // a status no admin has reviewed, so it must not keep opening the check-in routes
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
    }

    /**
     * Approves every pending item except the ones of the given type, the everyday
     * partial rejection.
     */
    private function decidePendingItemsRejecting(User $user, UserPaperType $rejectedType): void
    {
        $this->faceRecognitionProvider
            ->shouldReceive('addUser')
            ->andReturn(new AddUserResponse(success: true, vectorId: 'vector-1'));

        $admin = User::factory()->create();
        $itemDecisions = UserPaperItem::query()
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->whereNull('deleted_at')
            ->get()
            ->map(
                fn(UserPaperItem $item) => [
                    'id' => $item->id,
                    'approved' => $item->type !== $rejectedType->value,
                ]
            )
            ->all();

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull(
            $action($user->refresh(), $itemDecisions, 'Passport unreadable')->getError()
        );
    }

    private function newestPortrait(User $user): UserPaperItem
    {
        return UserPaperItem::query()
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))
            ->where('type', UserPaperType::PortraitImage->value)
            ->whereNotNull('approved_at')
            ->whereNull('rejected_at')
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * Employee completes every required step through the real endpoints.
     */
    private function submitAllRequiredSteps(User $user): void
    {
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/upload', [
                'files' => [
                    UserPaperType::ForeignerCardFront->value => UploadedFile::fake()->image('front.jpg'),
                    UserPaperType::ForeignerCardBack->value => UploadedFile::fake()->image('back.jpg'),
                    UserPaperType::ForeignerCardScan->value => UploadedFile::fake()->image('scan.jpg'),
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/confirm', [
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::ResidenceCard),
                'residence_number' => 'AB12345678CD',
                'residence_expiry_date' => '2028-01-31',
                'residence_status' => ResidenceStatus::LongTermResident->value,
                'first_name' => 'Van A',
                'last_name' => 'Nguyen',
                'birthday' => '1998-04-12',
                'gender' => 'male',
                'nationality' => 'vietnam',
                'address' => 'Tokyo Shinjuku 2-8-1',
            ])
            ->assertStatus(200);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [
                    UserPaperType::Passport->value => [
                        UploadedFile::fake()->image('page1.jpg'),
                        UploadedFile::fake()->image('page2.jpg'),
                    ],
                ],
            ])
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'C1234567',
                'passport_expiry_date' => '2030-06-01',
                'ocr_result_id' => $this->readyOcrResultId(UserPaperStepKey::Passport),
            ])
            ->assertStatus(200);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/portrait/upload', [
                'files' => [
                    UserPaperType::PortraitImage->value => UploadedFile::fake()->image('portrait.jpg'),
                ],
            ])
            ->assertStatus(200);
    }

    /**
     * Admin decides every pending item at once through the real action.
     */
    private function decideAllPendingItems(User $user, bool $approved, ?string $note = null): void
    {
        if ($approved) {
            $this->faceRecognitionProvider
                ->shouldReceive('addUser')
                ->andReturn(new AddUserResponse(success: true, vectorId: 'vector-1'));
        }

        $admin = User::factory()->create();
        $itemDecisions = UserPaperItem::query()
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn(int $id) => ['id' => $id, 'approved' => $approved])
            ->all();

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull($action($user->refresh(), $itemDecisions, $note)->getError());
    }

    /**
     * Admin attempts a decision through the real action and must be rejected because
     * the user is not waiting for approval.
     */
    private function assertDecidingAllPendingItemsIsBlocked(User $user): void
    {
        $admin = User::factory()->create();
        $itemDecisions = UserPaperItem::query()
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn(int $id) => ['id' => $id, 'approved' => true])
            ->all();

        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin);
        $result = $action($user->refresh(), $itemDecisions);
        $this->assertSame(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertSame(422, $result->getStatus());
    }

    /**
     * The reading a confirm form is built from: the queue is faked, so the extraction the
     * upload dispatched is run here first. Confirming before it finishes is refused, which
     * is what a real client sees while it is still polling the step.
     */
    private function readyOcrResultId(UserPaperStepKey $stepKey): int
    {
        $step = UserPaperStep::query()->where('step_key', $stepKey->value)->firstOrFail();
        $this->runQueuedOcrFor($step);

        $this->assertSame(UserPaperStepStatus::OcrReady->value, $step->refresh()->status);

        return $step->ocr_result_id;
    }
}
