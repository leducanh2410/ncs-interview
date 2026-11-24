<?php

namespace Tests\Feature\Api\User;

use App\Actions\Admin\User\ApprovePapersAction;
use App\Actions\Admin\User\SyncUserAvatarFromPortraitAction;
use App\Actions\Common\Poll\EvaluateUserAfterStaffNoAssignmentAction;
use App\Enums\OcrResultStatus;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Mail\DocumentUploadCompleteEmail;
use App\Models\User;
use App\Models\UserPaperItem;
use App\Models\UserPaperStep;
use App\Services\FaceRecognitionService;
use App\Services\Ocr\OcrResultCacheService;
use App\Support\Residence\ResidenceSlotResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\Concerns\FakesDocumentOcr;
use Tests\Concerns\ResetsUserDocumentInfo;
use Tests\TestCase;

class DocumentStepConfirmTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use FakesDocumentOcr;
    use RefreshDatabase;
    use ResetsUserDocumentInfo;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake([
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
            ...$this->documentOcrProviderStub(),
        ]);
    }

    public function testConfirmRequiresStepUpload(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user, 'api')->postJson(
            '/user/document-steps/residence_card/confirm',
            $this->residenceCardPayload(['ocr_result_id' => 999])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'USER_PAPER_STEP_NOT_UPLOADED');
    }

    public function testConfirmRejectsInvalidatedOcrResult(): void
    {
        $user = $this->makeUser();
        $originalBirthday = $user->info->birthday;
        $step = $this->makeStep($user, UserPaperStepKey::ResidenceCard);
        $step->ocrResult->update(['invalidated_at' => now()]);

        $response = $this->actingAs($user, 'api')->postJson(
            '/user/document-steps/residence_card/confirm',
            $this->residenceCardPayload(['ocr_result_id' => $step->ocr_result_id])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'USER_PAPER_STEP_CHANGED');
        $this->assertSame($originalBirthday, $user->refresh()->info->birthday);
    }

    public function testConfirmValidatesRequiredCardFields(): void
    {
        $user = $this->makeUser();
        $this->makeStep($user, UserPaperStepKey::ResidenceCard);

        $payload = $this->residenceCardPayload();
        unset($payload['residence_number']);

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/residence_card/confirm', $payload);

        $response->assertStatus(422);
    }

    public function testConfirmRejectsDuplicateResidenceNumber(): void
    {
        $other = User::factory()->create();
        $other->info->update(['residence_number_1' => 'AB12345678CD']);

        $user = $this->makeUser();
        $step = $this->makeStep($user, UserPaperStepKey::ResidenceCard);

        $response = $this->actingAs($user, 'api')->postJson(
            '/user/document-steps/residence_card/confirm',
            $this->residenceCardPayload([
                'residence_number' => 'AB12345678CD',
                'ocr_result_id' => $step->ocr_result_id,
            ])
        );

        $response->assertStatus(422);
    }

    public function testConfirmResidenceCardWritesSlotOneNamesAndManualFields(): void
    {
        $user = $this->makeUser();
        $step = $this->makeStep($user, UserPaperStepKey::ResidenceCard);

        $response = $this->actingAs($user, 'api')->postJson(
            '/user/document-steps/residence_card/confirm',
            $this->residenceCardPayload([
                'ocr_result_id' => $step->ocr_result_id,
                'first_name' => 'Van A',
                'last_name' => 'Nguyen',
                'phone' => '08012345678',
                'postal_code' => '1600023',
            ])
        );

        $response->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('AB12345678CD', $info->residence_number_1);
        $this->assertSame('2028-01-31', $info->residence_expiry_date_1);
        $this->assertSame(ResidenceStatus::StudyAbroad->value, $info->residence_status_1);
        $this->assertSame('08012345678', $info->phone);
        $this->assertSame('1600023', $info->postal_code);
        // Name mutators uppercase stored values
        $this->assertSame('VAN A', $user->first_name);
        $this->assertSame('NGUYEN', $user->last_name);

        $step = UserPaperStep::query()->firstOrFail();
        $this->assertSame(UserPaperStepStatus::Confirmed->value, $step->status);
        $this->assertNotNull($step->confirmed_at);
        $this->assertSame('AB12345678CD', $step->confirmed_fields['residence_number']);
    }

    public function testConfirmCreatesCrossStaffUserOnce(): void
    {
        $user = $this->makeUser();
        $step = $this->makeStep($user, UserPaperStepKey::ResidenceCard);

        $this->actingAs($user, 'api')
            ->postJson(
                '/user/document-steps/residence_card/confirm',
                $this->residenceCardPayload(['ocr_result_id' => $step->ocr_result_id])
            )
            ->assertStatus(200);

        Http::assertSent(fn($request) => str_contains($request->url(), 'insMemberExternal'));
    }

    public function testConfirmDoesNotCreateCrossStaffUserWhenAlreadyLinked(): void
    {
        $user = $this->makeUser(['cross_staff_id' => 'CS-1']);
        $step = $this->makeStep($user, UserPaperStepKey::ResidenceCard);

        $this->actingAs($user, 'api')
            ->postJson(
                '/user/document-steps/residence_card/confirm',
                $this->residenceCardPayload(['ocr_result_id' => $step->ocr_result_id])
            )
            ->assertStatus(200);

        Http::assertNothingSent();
    }

    /**
     * A step whose reading has not finished has no form to confirm — the detail endpoint
     * carries no fields yet — and accepting one anyway is how a submission slipped past a
     * run that was being redone: rotation maintenance replaces one group of the step and
     * leaves ocr_result_id pointing at the untouched, still-valid run of the other.
     *
     * Its own code: nothing was re-uploaded, so the client waits rather than reloads.
     */
    public function testConfirmRefusedWhileOcrStillProcessing(): void
    {
        $user = $this->makeUser();
        $step = $this->makeStep($user, UserPaperStepKey::Passport, UserPaperStepStatus::OcrProcessing);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'C1234567',
                'passport_expiry_date' => '2030-06-01',
                'ocr_result_id' => $step->ocr_result_id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'USER_PAPER_STEP_OCR_STILL_PROCESSING');

        $this->assertSame(UserPaperStepStatus::OcrProcessing->value, $step->refresh()->status);
    }

    public function testConfirmRejectedWhenSubmissionWasReplacedAfterRejection(): void
    {
        Storage::fake(config('filesystems.default'));
        Queue::fake();

        $user = $this->makeUser();

        // First upload: the confirm form the user is looking at is built from this OCR run
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [UserPaperType::Passport->value => [UploadedFile::fake()->image('page1.jpg')]],
            ])
            ->assertStatus(200);
        $staleOcrResultId = UserPaperStep::query()->firstOrFail()->ocr_result_id;

        // An admin rejection reopens the step, and the user re-uploads (other tab/device)
        // while the old confirm form is still open. Decisions are only possible on a
        // complete submission, so move the user into review first.
        $user->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $admin = User::factory()->create();
        $itemDecisions = UserPaperItem::query()
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))
            ->pluck('id')
            ->map(fn(int $id) => ['id' => $id, 'approved' => false])
            ->all();
        $action = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );
        $this->actingAs($admin)->assertNull($action($user->refresh(), $itemDecisions, 'blurry photo')->getError());

        $this->assertSame(UserPaperStepStatus::NeedsReupload->value, UserPaperStep::query()->firstOrFail()->status);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'C1234567',
                'passport_expiry_date' => '2030-06-01',
                'ocr_result_id' => $staleOcrResultId,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'USER_PAPER_STEP_NOT_UPLOADED');

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/upload', [
                'files' => [UserPaperType::Passport->value => [UploadedFile::fake()->image('page1-new.jpg')]],
            ])
            ->assertStatus(200);

        // The stale form must not confirm fields against the new upload
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'C1234567',
                'passport_expiry_date' => '2030-06-01',
                'ocr_result_id' => $staleOcrResultId,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'USER_PAPER_STEP_CHANGED');

        // Confirming against the current run succeeds, once that run has actually read the
        // replacement images — the queue is faked, so the job the re-upload dispatched runs here
        $this->runQueuedOcrFor(UserPaperStep::query()->firstOrFail());

        $currentOcrResultId = UserPaperStep::query()->firstOrFail()->ocr_result_id;
        $this->assertNotSame($staleOcrResultId, $currentOcrResultId);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'C1234567',
                'passport_expiry_date' => '2030-06-01',
                'ocr_result_id' => $currentOcrResultId,
            ])
            ->assertStatus(200);
    }

    public function testConfirmIsLockedWhenAlreadyConfirmed(): void
    {
        $user = $this->makeUser();
        $step = $this->makeStep($user, UserPaperStepKey::ResidenceCard, UserPaperStepStatus::Confirmed);

        $response = $this->actingAs($user, 'api')->postJson(
            '/user/document-steps/residence_card/confirm',
            $this->residenceCardPayload(['ocr_result_id' => $step->ocr_result_id])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'USER_PAPER_STEP_LOCKED');
    }

    public function testPortraitCannotBeConfirmed(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/portrait/confirm');

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'USER_PAPER_STEP_NOT_CONFIRMABLE');
    }

    public function testWorkPermitAndOtherCannotBeConfirmed(): void
    {
        $user = $this->makeUser();

        foreach (['work_permit', 'other'] as $step) {
            $this->actingAs($user, 'api')
                ->postJson("/user/document-steps/{$step}/confirm")
                ->assertStatus(422)
                ->assertJsonPath('error', 'USER_PAPER_STEP_NOT_CONFIRMABLE');
        }
    }

    public function testStatusFlipsWhenAllRequiredStepsConfirmed(): void
    {
        $user = $this->makeUser();
        $this->makeStep($user, UserPaperStepKey::Passport, UserPaperStepStatus::Confirmed);
        $this->makeStep($user, UserPaperStepKey::Portrait, UserPaperStepStatus::Confirmed);
        // 家族滞在 may only work under a 資格外活動許可, so its stamp is part of the submission
        $this->makeStep($user, UserPaperStepKey::WorkPermit, UserPaperStepStatus::Confirmed);
        $step = $this->makeStep($user, UserPaperStepKey::ResidenceCard);

        $this->actingAs($user, 'api')
            ->postJson(
                '/user/document-steps/residence_card/confirm',
                $this->residenceCardPayload([
                    'residence_status' => ResidenceStatus::FamilyStay->value,
                    'ocr_result_id' => $step->ocr_result_id,
                ])
            )
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
        Mail::assertQueued(DocumentUploadCompleteEmail::class, 1);
    }

    public function testStudyAbroadRequiresStudentCardAndWorkPermitBeforeFlip(): void
    {
        $user = $this->makeUser();
        $this->makeStep($user, UserPaperStepKey::Passport, UserPaperStepStatus::Confirmed);
        $this->makeStep($user, UserPaperStepKey::Portrait, UserPaperStepStatus::Confirmed);
        $step = $this->makeStep($user, UserPaperStepKey::ResidenceCard);

        // Study-abroad residence status makes both the student card and the work permit required
        $this->actingAs($user, 'api')
            ->postJson(
                '/user/document-steps/residence_card/confirm',
                $this->residenceCardPayload(['ocr_result_id' => $step->ocr_result_id])
            )
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
        Mail::assertNotQueued(DocumentUploadCompleteEmail::class);

        // The work permit alone is not enough while the student card is still missing
        $this->makeStep($user, UserPaperStepKey::WorkPermit, UserPaperStepStatus::Confirmed);
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);

        $studentStep = $this->makeStep($user, UserPaperStepKey::StudentCard);
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/student_card/confirm', [
                'student_card_expiry_date' => '2027-03-31',
                'ocr_result_id' => $studentStep->ocr_result_id,
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
        $this->assertSame('2027-03-31', $user->info->student_card_expiry_date);
        Mail::assertQueued(DocumentUploadCompleteEmail::class, 1);
    }

    public function testApprovedUserSinglePassportConfirmGoesBackToReview(): void
    {
        $user = $this->makeUser(['paper_status' => UserPaperStatus::Approved->value, 'cross_staff_id' => 'CS-1']);
        $user->info->update(['passport_number' => 'OLD12345', 'passport_expiry_date' => '2026-01-01']);
        $step = $this->makeStep($user, UserPaperStepKey::Passport);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/passport/confirm', [
                'passport_number' => 'C7654321',
                'passport_expiry_date' => '2035-06-01',
                'ocr_result_id' => $step->ocr_result_id,
            ])
            ->assertStatus(200);

        $user->refresh();
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->paper_status);
        $this->assertSame('C7654321', $user->info->passport_number);
        Mail::assertQueued(DocumentUploadCompleteEmail::class, 1);
    }

    public function testRenewalWritesNextEmptySlot(): void
    {
        $user = $this->makeUser(['paper_status' => UserPaperStatus::Approved->value, 'cross_staff_id' => 'CS-1']);
        $user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2025-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
        ]);
        // What the migration backfills for an employee approved before the column existed:
        // the set their approved 留学 card called for
        $user
            ->paper()
            ->first()
            ->update([
                'approved_step_keys' => [
                    UserPaperStepKey::ResidenceCard->value,
                    UserPaperStepKey::Passport->value,
                    UserPaperStepKey::Portrait->value,
                    UserPaperStepKey::StudentCard->value,
                    UserPaperStepKey::WorkPermit->value,
                ],
            ]);
        $step = $this->makeStep($user, UserPaperStepKey::ResidenceCard);

        $this->actingAs($user, 'api')
            ->postJson(
                '/user/document-steps/residence_card/confirm',
                $this->residenceCardPayload(['ocr_result_id' => $step->ocr_result_id])
            )
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('AA11111111AA', $info->residence_number_1);
        $this->assertSame('AB12345678CD', $info->residence_number_2);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->paper_status);
    }

    public function testRenewalRotatesSlotsWhenAllFull(): void
    {
        $user = $this->makeUser(['paper_status' => UserPaperStatus::Approved->value, 'cross_staff_id' => 'CS-1']);
        $user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2022-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'BB22222222BB',
            'residence_expiry_date_2' => '2024-01-01',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
            'residence_number_3' => 'CC33333333CC',
            'residence_expiry_date_3' => '2026-01-01',
            'residence_status_3' => ResidenceStatus::StudyAbroad->value,
        ]);
        $step = $this->makeStep($user, UserPaperStepKey::ResidenceCard);

        $this->actingAs($user, 'api')
            ->postJson(
                '/user/document-steps/residence_card/confirm',
                $this->residenceCardPayload(['ocr_result_id' => $step->ocr_result_id])
            )
            ->assertStatus(200);

        $info = $user->refresh()->info;
        // Oldest card dropped, history shifted, the new card lands in slot 3
        $this->assertSame('BB22222222BB', $info->residence_number_1);
        $this->assertSame('CC33333333CC', $info->residence_number_2);
        $this->assertSame('AB12345678CD', $info->residence_number_3);
        $this->assertSame('2028-01-31', $info->residence_expiry_date_3);
    }

    public function testReconfirmingSameCardAfterRejectionOverwritesItsSlot(): void
    {
        Storage::fake(config('filesystems.default'));
        Queue::fake();

        $user = $this->makeUser();
        $admin = User::factory()->create();
        $approve = new ApprovePapersAction(
            app(FaceRecognitionService::class),
            app(EvaluateUserAfterStaffNoAssignmentAction::class),
            app(SyncUserAvatarFromPortraitAction::class)
        );

        // Upload → confirm → admin rejects → re-upload → confirm the very same card, twice over
        for ($round = 1; $round <= 3; $round++) {
            $this->actingAs($user, 'api')
                ->postJson('/user/document-steps/residence_card/upload', ['files' => $this->residenceCardFiles()])
                ->assertStatus(200);
            $stepRow = $this->runQueuedOcrFor(UserPaperStep::query()->firstOrFail());
            $ocrResultId = $stepRow->ocr_result_id;

            $this->actingAs($user, 'api')
                ->postJson(
                    '/user/document-steps/residence_card/confirm',
                    $this->residenceCardPayload(['ocr_result_id' => $ocrResultId])
                )
                ->assertStatus(200);

            $info = $user->refresh()->info;
            $this->assertSame('AB12345678CD', $info->residence_number_1, "round {$round}");
            $this->assertSame('2028-01-31', $info->residence_expiry_date_1);
            $this->assertNull($info->residence_number_2, "round {$round} must not open slot 2");
            $this->assertNull($info->residence_number_3);

            if ($round === 3) {
                break;
            }

            $user->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
            $itemDecisions = UserPaperItem::query()
                ->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))
                ->whereNull('approved_at')
                ->whereNull('rejected_at')
                ->pluck('id')
                ->map(fn(int $id) => ['id' => $id, 'approved' => false])
                ->all();
            $this->actingAs($admin)->assertNull($approve($user->refresh(), $itemDecisions, 'blurry')->getError());
            $this->assertSame(UserPaperStepStatus::NeedsReupload->value, UserPaperStep::query()->firstOrFail()->status);
        }
    }

    public function testReconfirmingSameCardWithDifferentCaseOverwritesItsSlot(): void
    {
        $user = $this->makeUser(['paper_status' => UserPaperStatus::Approved->value, 'cross_staff_id' => 'CS-1']);
        $user->info->update([
            'residence_number_1' => 'ab12345678cd',
            'residence_expiry_date_1' => '2027-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
        ]);
        $step = $this->makeStep($user, UserPaperStepKey::ResidenceCard);

        $this->actingAs($user, 'api')
            ->postJson(
                '/user/document-steps/residence_card/confirm',
                $this->residenceCardPayload(['ocr_result_id' => $step->ocr_result_id])
            )
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('AB12345678CD', $info->residence_number_1);
        $this->assertSame('2028-01-31', $info->residence_expiry_date_1);
        $this->assertNull($info->residence_number_2);
    }

    public function testReconfirmingSameCardDropsStaleCopyThatWouldOutrankIt(): void
    {
        Storage::fake(config('filesystems.default'));
        Queue::fake();

        // Legacy data from the old flow: the card confirmed with a wrong later expiry took slot 2,
        // the admin rejected it, and the cleanup command left the record alone (copies disagree,
        // no confirmation stands). Slot 3 holds an unrelated older card that must survive.
        $user = $this->makeUser(['paper_status' => UserPaperStatus::Rejected->value, 'cross_staff_id' => 'CS-1']);
        $user->info->update([
            'residence_number_1' => 'AB12345678CD',
            'residence_expiry_date_1' => '2028-01-31',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => 'AB12345678CD',
            'residence_expiry_date_2' => '2030-01-31',
            'residence_status_2' => ResidenceStatus::StudyAbroad->value,
            'residence_number_3' => 'ZZ99999999ZZ',
            'residence_expiry_date_3' => '2024-01-31',
            'residence_status_3' => ResidenceStatus::DesignatedActivities->value,
        ]);
        $this->makeStep($user, UserPaperStepKey::ResidenceCard, UserPaperStepStatus::NeedsReupload);
        $this->assertSame(
            2,
            ResidenceSlotResolver::latestSlot($user->info->refresh()),
            'the stale copy is read as current'
        );

        // Employee re-uploads and confirms the correct expiry date
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/residence_card/upload', ['files' => $this->residenceCardFiles()])
            ->assertStatus(200);
        $stepRow = $this->runQueuedOcrFor(UserPaperStep::query()->firstOrFail());
        $ocrResultId = $stepRow->ocr_result_id;
        $this->actingAs($user, 'api')
            ->postJson(
                '/user/document-steps/residence_card/confirm',
                $this->residenceCardPayload(['ocr_result_id' => $ocrResultId])
            )
            ->assertStatus(200);

        $info = $user->refresh()->info;
        $this->assertSame('AB12345678CD', $info->residence_number_1);
        $this->assertSame('2028-01-31', $info->residence_expiry_date_1);
        // The stale copy is gone and the unrelated card moved up; nothing outranks the confirmed values
        $this->assertSame('ZZ99999999ZZ', $info->residence_number_2);
        $this->assertSame('2024-01-31', $info->residence_expiry_date_2);
        $this->assertSame(ResidenceStatus::DesignatedActivities->value, $info->residence_status_2);
        $this->assertNull($info->residence_number_3);
        $this->assertNull($info->residence_expiry_date_3);
        $this->assertNull($info->residence_status_3);
        $this->assertSame(1, ResidenceSlotResolver::latestSlot($info));
    }

    private function makeUser(array $attributes = []): User
    {
        $user = $this->createUser(array_merge(['paper_status' => UserPaperStatus::Pending->value], $attributes));
        $this->resetUserDocumentInfo($user);
        $user->paper()->create();

        return $user->fresh(['info', 'paper']);
    }

    private function makeStep(
        User $user,
        UserPaperStepKey $stepKey,
        UserPaperStepStatus $status = UserPaperStepStatus::OcrReady
    ): UserPaperStep {
        // A confirmable step always points at the OCR run of its current upload
        $ocrResult = $stepKey->ocrGroup()
            ? app(OcrResultCacheService::class)->createPending(
                $stepKey->ocrGroup(),
                [],
                OcrResultCacheService::SOURCE_EMPLOYEE,
                $user->id
            )
            : null;

        $step = UserPaperStep::query()->create([
            'user_paper_id' => $user->paper->id,
            'step_key' => $stepKey->value,
            'status' => $status->value,
            'ocr_result_id' => $ocrResult?->id,
            'confirmed_at' => $status === UserPaperStepStatus::Confirmed ? now() : null,
        ]);

        // The run has to carry the outcome that put the step in this status — a step past
        // ocr_processing whose run is still pending is a state the flow never produces,
        // and confirming against it is refused. DocumentStepSubmitTest reaches each of
        // these through the real upload → job → confirm sequence.
        $runStatus = match ($status) {
            UserPaperStepStatus::OcrProcessing => OcrResultStatus::Processing,
            UserPaperStepStatus::OcrFailed => OcrResultStatus::Failed,
            default => OcrResultStatus::Completed,
        };
        $ocrResult?->update([
            'user_paper_step_id' => $step->id,
            'status' => $runStatus->value,
            'completed_at' => $runStatus === OcrResultStatus::Processing ? null : now(),
        ]);

        return $step;
    }

    private function residenceCardPayload(array $overrides = []): array
    {
        return array_merge(
            [
                'residence_number' => 'AB12345678CD',
                'residence_expiry_date' => '2028-01-31',
                'residence_status' => ResidenceStatus::StudyAbroad->value,
                'first_name' => 'Van A',
                'last_name' => 'Nguyen',
                'birthday' => '1998-04-12',
                'gender' => 'male',
                'nationality' => 'vietnam',
                'address' => 'Tokyo Shinjuku 2-8-1',
            ],
            $overrides
        );
    }

    private function residenceCardFiles(): array
    {
        return [
            UserPaperType::ForeignerCardFront->value => UploadedFile::fake()->image('front.jpg'),
            UserPaperType::ForeignerCardBack->value => UploadedFile::fake()->image('back.jpg'),
            UserPaperType::ForeignerCardScan->value => UploadedFile::fake()->image('scan.jpg'),
        ];
    }
}
