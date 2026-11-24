<?php

namespace Tests\Feature\Api\User;

use App\Actions\Admin\User\ApprovePapersAction;
use App\Actions\Admin\User\SyncUserAvatarFromPortraitAction;
use App\Actions\Common\Poll\EvaluateUserAfterStaffNoAssignmentAction;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Exceptions\OcrRequiredFieldsMissingException;
use App\Jobs\ExtractDocumentStepOcrJob;
use App\Models\User;
use App\Models\UserPaperItem;
use App\Models\UserPaperOcrResult;
use App\Models\UserPaperStep;
use App\Services\FaceRecognitionService;
use App\Services\Ocr\OcrResultCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\Concerns\ResetsUserDocumentInfo;
use Tests\TestCase;

class DocumentStepUploadTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use RefreshDatabase;
    use ResetsUserDocumentInfo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Queue::fake();
        Mail::fake();
    }

    public function testRequiresAuthentication(): void
    {
        $response = $this->postJson('/user/document-steps/residence_card/upload');

        $response->assertStatus(401);
    }

    public function testRejectsUnknownStep(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/unknown/upload');

        $response->assertStatus(404);
    }

    public function testRejectsIncompleteTypeSet(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/residence_card/upload', [
            'files' => [
                UserPaperType::ForeignerCardFront->value => UploadedFile::fake()->image('front.jpg'),
                UserPaperType::ForeignerCardBack->value => UploadedFile::fake()->image('back.jpg'),
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error', 'USER_PAPER_FILE_REQUIRED');
        $this->assertSame(
            'USER_PAPER_FILE_REQUIRED',
            $response->json('data.error_codes')['files.' . UserPaperType::ForeignerCardScan->value]
        );
    }

    public function testRejectsTypeFromAnotherStep(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/portrait/upload', [
            'files' => [
                UserPaperType::PortraitImage->value => UploadedFile::fake()->image('portrait.jpg'),
                UserPaperType::Passport->value => UploadedFile::fake()->image('passport.jpg'),
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error', 'USER_PAPER_FILE_WRONG_STEP');
    }

    public function testRejectsHeicImageWithInvalidTypeCode(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/portrait/upload', [
            'files' => [
                UserPaperType::PortraitImage->value => UploadedFile::fake()->create('IMG_0001.HEIC', 100, 'image/heic'),
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error', 'USER_PAPER_FILE_INVALID_TYPE');
        $this->assertSame(['files.' . UserPaperType::PortraitImage->value], array_keys($response->json('data.errors')));
        $this->assertNotEmpty($response->json('data.message'));
    }

    public function testRejectsImageOverSizeLimitWithTooLargeCode(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/passport/upload', [
            'files' => [
                UserPaperType::Passport->value => [
                    UploadedFile::fake()->image('page1.jpg'),
                    UploadedFile::fake()->image('page2.jpg')->size(9000),
                ],
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error', 'USER_PAPER_FILE_TOO_LARGE');
        $this->assertSame(
            ['files.' . UserPaperType::Passport->value . '.1' => 'USER_PAPER_FILE_TOO_LARGE'],
            $response->json('data.error_codes')
        );
    }

    public function testRejectsMoreImagesThanASlotAllowsWithTooManyCode(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/passport/upload', [
            'files' => [
                UserPaperType::Passport->value => array_map(
                    fn(int $page) => UploadedFile::fake()->image("page{$page}.jpg"),
                    range(1, 6)
                ),
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error', 'USER_PAPER_FILE_TOO_MANY');
    }

    public function testUnreadableImageIsRejectedAndLeavesNothingBehind(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/residence_card/upload', [
            'files' => [
                UserPaperType::ForeignerCardFront->value => UploadedFile::fake()->image('front.jpg'),
                UserPaperType::ForeignerCardBack->value => UploadedFile::fake()->image('back.jpg'),
                UserPaperType::ForeignerCardScan->value => UploadedFile::fake()->create('scan.jpg', 10, 'image/jpeg'),
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error', 'USER_PAPER_FILE_UNREADABLE');
        $this->assertSame([], Storage::allFiles());
        $this->assertSame(0, UserPaperItem::query()->count());
        $this->assertSame(0, UserPaperStep::query()->count());
        $this->assertSame(UserPaperStatus::Registered->value, $user->refresh()->paper_status);
        Queue::assertNothingPushed();
    }

    public function testRequestBodyOverServerLimitReturnsPayloadTooLargeCode(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->call(
            'POST',
            '/user/document-steps/portrait/upload',
            server: ['CONTENT_LENGTH' => (string) (1024 * 1024 * 1024), 'HTTP_ACCEPT' => 'application/json']
        );

        $response->assertStatus(413)->assertJsonPath('error', 'E_PAYLOAD_TOO_LARGE');
    }

    public function testUploadResidenceCardCreatesItemsStepAndOcrRun(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/residence_card/upload', [
            'files' => $this->residenceCardFiles(),
        ]);

        $response->assertStatus(200);

        $this->assertSame(3, UserPaperItem::query()->count());
        $step = UserPaperStep::query()->firstOrFail();
        $this->assertSame(UserPaperStepKey::ResidenceCard->value, $step->step_key);
        $this->assertSame(UserPaperStepStatus::OcrProcessing->value, $step->status);
        $this->assertNotNull($step->ocr_result_id);

        // The card's two sides and the scanned copy are read separately so the two answers
        // can be compared; the sides' run is the primary one the confirm form is built from
        $runs = UserPaperOcrResult::query()->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame('residence_card', $runs[0]->ocr_group);
        $this->assertCount(2, $runs[0]->item_ids);
        $this->assertSame('residence_card_scan', $runs[1]->ocr_group);
        $this->assertCount(1, $runs[1]->item_ids);
        $this->assertSame($runs[0]->id, $step->ocr_result_id);
        $this->assertSame([$step->id, $step->id], $runs->pluck('user_paper_step_id')->all());

        Queue::assertPushed(ExtractDocumentStepOcrJob::class, 2);

        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
    }

    public function testPassportAcceptsMultipleImages(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/passport/upload', [
            'files' => [
                UserPaperType::Passport->value => [
                    UploadedFile::fake()->image('page1.jpg'),
                    UploadedFile::fake()->image('page2.jpg'),
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertSame(2, UserPaperItem::query()->where('type', UserPaperType::Passport->value)->count());
        $this->assertCount(2, UserPaperOcrResult::query()->firstOrFail()->item_ids);
    }

    public function testResidenceCardRejectsMultipleFilesPerType(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/residence_card/upload', [
            'files' => [
                UserPaperType::ForeignerCardFront->value => [
                    UploadedFile::fake()->image('front1.jpg'),
                    UploadedFile::fake()->image('front2.jpg'),
                ],
                UserPaperType::ForeignerCardBack->value => UploadedFile::fake()->image('back.jpg'),
                UserPaperType::ForeignerCardScan->value => UploadedFile::fake()->image('scan.jpg'),
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error', 'USER_PAPER_FILE_TOO_MANY');
    }

    public function testPortraitUploadConfirmsImmediatelyWithoutOcr(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/portrait/upload', [
            'files' => [
                UserPaperType::PortraitImage->value => UploadedFile::fake()->image('portrait.jpg'),
            ],
        ]);

        $response->assertStatus(200);

        $step = UserPaperStep::query()->firstOrFail();
        $this->assertSame(UserPaperStepStatus::Confirmed->value, $step->status);
        $this->assertNotNull($step->confirmed_at);
        $this->assertNull($step->ocr_result_id);

        Queue::assertNothingPushed();
        $this->assertSame(0, UserPaperOcrResult::query()->count());
    }

    public function testWorkPermitUploadConfirmsImmediatelyWithoutOcr(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/work_permit/upload', [
            'files' => [
                UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('stamp.jpg'),
            ],
        ]);

        $response->assertStatus(200);

        $step = UserPaperStep::query()->firstOrFail();
        $this->assertSame(UserPaperStepKey::WorkPermit->value, $step->step_key);
        $this->assertSame(UserPaperStepStatus::Confirmed->value, $step->status);
        $this->assertNotNull($step->confirmed_at);
        $this->assertNull($step->ocr_result_id);

        Queue::assertNothingPushed();
        $this->assertSame(0, UserPaperOcrResult::query()->count());
        $this->assertSame(1, UserPaperItem::query()->where('type', UserPaperType::WorkPermitStamp->value)->count());
    }

    public function testWorkPermitRejectsMultipleFiles(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/work_permit/upload', [
            'files' => [
                UserPaperType::WorkPermitStamp->value => [
                    UploadedFile::fake()->image('stamp1.jpg'),
                    UploadedFile::fake()->image('stamp2.jpg'),
                ],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function testOtherStepAcceptsMultipleImagesWithoutOcr(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/other/upload', [
            'files' => [
                UserPaperType::Other->value => [
                    UploadedFile::fake()->image('doc1.jpg'),
                    UploadedFile::fake()->image('doc2.jpg'),
                ],
            ],
        ]);

        $response->assertStatus(200);

        $step = UserPaperStep::query()->firstOrFail();
        $this->assertSame(UserPaperStepKey::Other->value, $step->step_key);
        $this->assertSame(UserPaperStepStatus::Confirmed->value, $step->status);
        $this->assertNull($step->ocr_result_id);

        Queue::assertNothingPushed();
        $this->assertSame(0, UserPaperOcrResult::query()->count());
        $this->assertSame(2, UserPaperItem::query()->where('type', UserPaperType::Other->value)->count());
    }

    public function testOptionalStepUploadDoesNotCompleteSubmissionForFirstTimeUser(): void
    {
        $user = $this->createUser();

        // Only the optional step is uploaded; the required steps are still unconfirmed
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/other/upload', [
                'files' => [UserPaperType::Other->value => [UploadedFile::fake()->image('doc.jpg')]],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
    }

    public function testApprovedUserWorkPermitUploadGoesBackToReview(): void
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::Approved->value]);
        // UserInfoFactory rolls a random residence status, and 留学 / 家族滞在 / 特定活動 each
        // owe documents this fixture has no history for. Clear it so the test is about the
        // re-upload road it names.
        $this->resetUserDocumentInfo($user);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/work_permit/upload', [
                'files' => [UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('stamp.jpg')],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    public function testRejectedWorkPermitStepReopensForReupload(): void
    {
        // Seed an approved user from the legacy flow, which did not require the
        // residence-card scan image. The current step flow cannot recreate this
        // historical production state, so it is represented explicitly here.
        $user = $this->createUser(['paper_status' => UserPaperStatus::Approved->value]);
        $userPaper = $user->paper()->create();
        $approvedTypes = UserPaperType::getRequiredTypes();
        foreach ($approvedTypes as $type) {
            UserPaperItem::factory()->create([
                'user_paper_id' => $userPaper->id,
                'type' => $type,
                'folder' => UserPaperItemFolder::Current->value,
                'approved_at' => now(),
            ]);
        }

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/work_permit/upload', [
                'files' => [UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('blurred.jpg')],
            ])
            ->assertStatus(200);

        // Locked while the submission waits for review
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/work_permit/upload', [
                'files' => [UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('retry.jpg')],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'USER_PAPER_STEP_LOCKED');

        // The admin rejection re-opens the step
        $admin = $this->createUser();
        $item = UserPaperItem::query()->where('type', UserPaperType::WorkPermitStamp->value)->firstOrFail();
        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull(
            $action($user->refresh(), [['id' => $item->id, 'approved' => false]], 'Blurry stamp photo')->getError()
        );
        $rejectedStep = UserPaperStep::query()->firstOrFail();
        $this->assertSame(UserPaperStepStatus::NeedsReupload->value, $rejectedStep->status);
        $this->assertSame(0, $rejectedStep->ocr_run_count);
        $this->assertNull($rejectedStep->confirmed_at);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/work_permit/upload', [
                'files' => [UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('clear.jpg')],
            ])
            ->assertStatus(200);

        $step = UserPaperStep::query()->firstOrFail();
        $this->assertSame($rejectedStep->id, $step->id);
        $this->assertSame(UserPaperStepKey::WorkPermit->value, $step->step_key);
        $this->assertSame(UserPaperStepStatus::Confirmed->value, $step->status);

        // The replacement submission must go back to the admin, not strand the user in rejected
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    public function testImportedApprovedUserCanReplaceRejectedOptionalStepWithoutRequiredHistory(): void
    {
        $user = $this->createUser([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'IMPORTED-001',
            'cross_staff_id' => 'cross-imported-001',
        ]);
        // UserInfoFactory picks a random residence status, and 留学 / 家族滞在 / 特定活動 each
        // pull in documents an import has no history for. Clear it so this test is about the
        // optional-step replacement it names, not about which status the factory rolled.
        $this->resetUserDocumentInfo($user);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/work_permit/upload', [
                'files' => [
                    UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('work-permit.jpg'),
                ],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
        $this->assertTrue($user->paper()->firstOrFail()->submission_started_from_approved);
        $this->assertSame(0, UserPaperItem::query()->whereIn('type', UserPaperType::getRequiredTypes())->count());

        $admin = $this->createUser();
        $item = UserPaperItem::query()->where('type', UserPaperType::WorkPermitStamp->value)->firstOrFail();
        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull(
            $action($user->refresh(), [['id' => $item->id, 'approved' => false]], 'Replace this image')->getError()
        );
        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/work_permit/upload', [
                'files' => [
                    UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('replacement.jpg'),
                ],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
        $this->assertSame(0, UserPaperItem::query()->whereIn('type', UserPaperType::getRequiredTypes())->count());
    }

    public function testReuploadBlockedWhileSubmissionInReview(): void
    {
        $user = $this->createUser();

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/upload', ['files' => $this->residenceCardFiles()])
            ->assertStatus(200);

        $oldItems = UserPaperItem::query()->get();
        $this->assertCount(3, $oldItems);

        // Every OCR run costs an LLM call, so one submission per review cycle: no retake
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/upload', ['files' => $this->residenceCardFiles()])
            ->assertStatus(422)
            ->assertJsonPath('error', 'USER_PAPER_STEP_LOCKED');

        // The original submission is untouched and no second OCR run was started
        $this->assertSame(3, UserPaperItem::query()->count());
        foreach ($oldItems as $item) {
            Storage::assertExists($item->file_path);
        }
        $this->assertSame(2, UserPaperOcrResult::query()->count());
        Queue::assertPushed(ExtractDocumentStepOcrJob::class, 2);
    }

    public function testReuploadBlockedEvenAfterOcrFailed(): void
    {
        $user = $this->createUser();

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [UserPaperType::Passport->value => [UploadedFile::fake()->image('page1.jpg')]],
            ])
            ->assertStatus(200);

        // The queue exhausts its attempts and the job's failed() hook marks the step ocr_failed
        $step = UserPaperStep::query()->firstOrFail();
        (new ExtractDocumentStepOcrJob($step->ocr_result_id, $step->id))->failed(new RuntimeException('boom'));
        $this->assertSame(UserPaperStepStatus::OcrFailed->value, $step->refresh()->status);

        // A failed OCR is no loophole for a fresh run — the user confirms manually instead
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [UserPaperType::Passport->value => [UploadedFile::fake()->image('page2.jpg')]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'USER_PAPER_STEP_LOCKED');
    }

    public function testUnreadablePassportCanBeReuploaded(): void
    {
        $user = $this->createUser();

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [UserPaperType::Passport->value => [UploadedFile::fake()->image('blurred.jpg')]],
            ])
            ->assertStatus(200);

        $step = UserPaperStep::query()->firstOrFail();
        $oldResultId = $step->ocr_result_id;
        $oldItem = UserPaperItem::query()->firstOrFail();

        Http::fake(['openrouter.ai/*' => Http::response($this->unreadablePassportResponse())]);

        (new ExtractDocumentStepOcrJob($oldResultId, $step->id))->handle(app(OcrResultCacheService::class));

        $this->assertSame(UserPaperStepStatus::OcrFailed->value, $step->refresh()->status);
        $this->assertDatabaseHas('user_paper_ocr_results', [
            'id' => $oldResultId,
            'status' => 'failed',
            'error' => OcrRequiredFieldsMissingException::ERROR_CODE,
        ]);
        $this->actingAs($user, 'api')
            ->getJson('/user/document-steps/passport')
            ->assertStatus(200)
            ->assertJsonPath('data.ocr.error', OcrRequiredFieldsMissingException::ERROR_CODE)
            ->assertJsonPath('data.can_reupload', true);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'ocr_result_id' => $oldResultId,
                'passport_number' => 'C1234567',
                'passport_expiry_date' => '2030-06-01',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'USER_PAPER_STEP_OCR_REQUIRED_FIELDS_MISSING');

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [UserPaperType::Passport->value => [UploadedFile::fake()->image('clear.jpg')]],
            ])
            ->assertStatus(200);

        $step->refresh();
        $this->assertSame(UserPaperStepStatus::OcrProcessing->value, $step->status);
        $this->assertSame(2, $step->ocr_run_count);
        $this->assertNotSame($oldResultId, $step->ocr_result_id);
        $this->assertDatabaseMissing('user_paper_items', ['id' => $oldItem->id]);
        Storage::assertMissing($oldItem->file_path);
        $this->assertSame(1, UserPaperItem::query()->count());
        $this->assertSame(2, UserPaperOcrResult::query()->count());
        Queue::assertPushed(ExtractDocumentStepOcrJob::class, 2);
    }

    public function testUnreadableUploadBudgetExhaustionUnlocksManualConfirm(): void
    {
        $user = $this->createUser();

        Http::fake(['openrouter.ai/*' => Http::response($this->unreadablePassportResponse())]);

        // Initial upload plus two replacements: OCR keeps missing a required field
        for ($run = 1; $run <= UserPaperStep::MAX_OCR_RUNS_PER_CYCLE; $run++) {
            $this->actingAs($user, 'api')
                ->postJson('/user/document-steps/passport/upload', [
                    'files' => [UserPaperType::Passport->value => [UploadedFile::fake()->image("try{$run}.jpg")]],
                ])
                ->assertStatus(200);

            $step = UserPaperStep::query()->firstOrFail();
            $this->assertSame($run, $step->ocr_run_count);
            (new ExtractDocumentStepOcrJob($step->ocr_result_id, $step->id))->handle(app(OcrResultCacheService::class));
            $this->assertSame(UserPaperStepStatus::OcrFailed->value, $step->refresh()->status);
        }

        // The OCR run budget is spent: no further replacement is offered or accepted...
        $this->actingAs($user, 'api')
            ->getJson('/user/document-steps/passport')
            ->assertStatus(200)
            ->assertJsonPath('data.can_reupload', false);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [UserPaperType::Passport->value => [UploadedFile::fake()->image('extra.jpg')]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'USER_PAPER_STEP_LOCKED');

        // ...and manual confirmation opens up instead of dead-ending the user
        $step = UserPaperStep::query()->firstOrFail();
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'ocr_result_id' => $step->ocr_result_id,
                'passport_number' => 'C1234567',
                'passport_expiry_date' => '2030-06-01',
            ])
            ->assertStatus(200);
        $this->assertSame(UserPaperStepStatus::Confirmed->value, $step->refresh()->status);
        Http::assertSentCount(UserPaperStep::MAX_OCR_RUNS_PER_CYCLE);
    }

    public function testUploadReplacesLegacyFlowLeftovers(): void
    {
        // Items uploaded through the legacy one-page flow have no step row
        $user = $this->createUser(['paper_status' => UserPaperStatus::Pending->value]);
        $userPaper = $user->paper()->create();
        $legacyPath = 'users/' . $user->id . '/papers/legacy-page.png';
        Storage::put($legacyPath, 'legacy');
        $legacyItem = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::Passport->value,
            'file_path' => $legacyPath,
            'approved_at' => null,
            'rejected_at' => null,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [UserPaperType::Passport->value => [UploadedFile::fake()->image('page1.jpg')]],
            ])
            ->assertStatus(200);

        // The un-reviewed legacy set is replaced for good — rows and files
        $this->assertDatabaseMissing('user_paper_items', ['id' => $legacyItem->id]);
        Storage::assertMissing($legacyPath);
        $result = UserPaperOcrResult::query()->firstOrFail();
        $this->assertNotContains($legacyItem->id, $result->item_ids);
    }

    public function testFailedUploadKeepsLegacyFilesAndCleansUpNewOnes(): void
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::Pending->value]);
        $userPaper = $user->paper()->create();
        $legacyPath = 'users/' . $user->id . '/papers/legacy-page.png';
        Storage::put($legacyPath, 'legacy');
        $legacyItem = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'type' => UserPaperType::Passport->value,
            'file_path' => $legacyPath,
            'approved_at' => null,
            'rejected_at' => null,
        ]);

        // Force a failure after the new files were stored but before the transaction commits
        $this->mock(
            OcrResultCacheService::class,
            fn($mock) => $mock->shouldReceive('createPending')->andThrow(new RuntimeException('boom'))
        );

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [UserPaperType::Passport->value => [UploadedFile::fake()->image('page1.jpg')]],
            ])
            ->assertStatus(500);

        // The rollback restored the replaced set, so its row and file must both survive
        $this->assertDatabaseHas('user_paper_items', ['id' => $legacyItem->id]);
        Storage::assertExists($legacyPath);

        // The files written for the failed attempt must not stay behind
        $this->assertSame([$legacyPath], Storage::allFiles('users/' . $user->id . '/papers'));
    }

    public function testRenewalPassportOcrIgnoresApprovedHistory(): void
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::Approved->value]);
        $userPaper = $user->paper()->create();
        $approvedIds = UserPaperItem::factory()
            ->count(2)
            ->create([
                'user_paper_id' => $userPaper->id,
                'type' => UserPaperType::Passport->value,
                'folder' => UserPaperItemFolder::Current->value,
                'approved_at' => now(),
            ])
            ->pluck('id')
            ->all();

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [
                    UserPaperType::Passport->value => [UploadedFile::fake()->image('new-page.jpg')],
                ],
            ])
            ->assertStatus(200);

        // The OCR run must only read the new pending upload, never the approved history
        $result = UserPaperOcrResult::query()->firstOrFail();
        $this->assertCount(1, $result->item_ids);
        $this->assertEmpty(array_intersect($approvedIds, $result->item_ids));
    }

    public function testUploadIsLockedAfterConfirm(): void
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::Pending->value]);
        $userPaper = $user->paper()->create();
        UserPaperStep::query()->create([
            'user_paper_id' => $userPaper->id,
            'step_key' => UserPaperStepKey::ResidenceCard->value,
            'status' => UserPaperStepStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/residence_card/upload', [
            'files' => $this->residenceCardFiles(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'USER_PAPER_STEP_LOCKED');
    }

    public function testApprovedUserPortraitReuploadGoesBackToReview(): void
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::Approved->value]);
        // UserInfoFactory rolls a random residence status, and 留学 / 家族滞在 / 特定活動 each
        // owe documents this fixture has no history for. Clear it so the test is about the
        // re-upload road it names.
        $this->resetUserDocumentInfo($user);

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/portrait/upload', [
            'files' => [
                UserPaperType::PortraitImage->value => UploadedFile::fake()->image('portrait.jpg'),
            ],
        ]);

        $response->assertStatus(200);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    private function residenceCardFiles(): array
    {
        return [
            UserPaperType::ForeignerCardFront->value => UploadedFile::fake()->image('front.jpg'),
            UserPaperType::ForeignerCardBack->value => UploadedFile::fake()->image('back.jpg'),
            UserPaperType::ForeignerCardScan->value => UploadedFile::fake()->image('scan.jpg'),
        ];
    }

    private function unreadablePassportResponse(): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'passport_number' => 'C1234567',
                            'passport_expiry_date' => null,
                        ]),
                    ],
                ],
            ],
        ];
    }
}
