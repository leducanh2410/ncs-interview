<?php

namespace Tests\Feature\Api\User;

use App\Enums\PermissionName;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Jobs\ExtractDocumentStepOcrJob;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\UserPaperItem;
use App\Models\UserPaperStep;
use App\Services\Ocr\OcrResultCacheService;
use App\Support\Residence\ResidenceSlotResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\Concerns\FakesDocumentOcr;
use Tests\TestCase;

class DocumentStepSubmitTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use BuildsGroupFixtures;
    use FakesDocumentOcr;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        // The queue is what makes ocr_processing a state a test can actually stand in:
        // .env.testing runs it synchronously, so without this every upload came back with
        // its reading already done and the steps in between were never exercised.
        Queue::fake();
        Mail::fake();
    }

    /**
     * The whole 留学 submission through the real endpoints: every step is uploaded, its OCR
     * runs for real against a faked provider, and the one submit writes the profile.
     */
    public function testStudyAbroadLifecycleUploadsOcrsAndSubmitsInOneCall(): void
    {
        $this->fakeOcr();
        $user = $this->createUser();

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $passportStep = $this->uploadAndRunOcr($user, UserPaperStepKey::Passport);
        $this->uploadStep($user, UserPaperStepKey::Portrait);
        $cardBefore = $user->refresh()->info?->residence_number_1;

        // Choosing 留学 pulls the student card and the work permit into the same submission
        $incomplete = $this->actingAs($user, 'api')->postJson('/user/document-steps/submit', [
            'steps' => [
                'residence_card' => $this->residenceCardFields($residenceStep),
                'passport' => $this->passportFields($passportStep),
            ],
        ]);

        $incomplete->assertStatus(422);
        $incomplete->assertJsonPath('error', 'USER_PAPER_SUBMISSION_INCOMPLETE');
        $this->assertSame(
            [UserPaperStepKey::StudentCard->value, UserPaperStepKey::WorkPermit->value],
            $incomplete->json('data.missing_steps')
        );

        // Nothing was written: a submission that cannot be completed must not half-apply
        $this->assertSame($cardBefore, $user->refresh()->info?->residence_number_1);
        $this->assertSame(
            UserPaperStepStatus::OcrReady->value,
            UserPaperStep::query()->whereKey($residenceStep->id)->value('status')
        );

        $studentStep = $this->uploadAndRunOcr($user, UserPaperStepKey::StudentCard);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/submit', [
            'steps' => [
                'residence_card' => $this->residenceCardFields($residenceStep),
                'passport' => $this->passportFields($passportStep),
                'student_card' => [
                    'ocr_result_id' => $studentStep->refresh()->ocr_result_id,
                    'student_card_expiry_date' => '2027-03-31',
                ],
            ],
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->paper_status);
        // The card lands in the next free slot, so read it back the way production does
        $slot = ResidenceSlotResolver::latestSlot($user->info);
        $this->assertSame('AB12345678CD', $user->info->{"residence_number_{$slot}"});
        $this->assertSame(ResidenceStatus::StudyAbroad->value, ResidenceSlotResolver::latestStatus($user->info));
        $this->assertSame('2027-03-31', $user->info->student_card_expiry_date);
        $this->assertSame([], $response->json('data.mismatches'));
    }

    /**
     * A status that carries 就労制限なし needs neither the student card nor the work permit.
     */
    public function testPermanentResidentSubmitsWithoutTheExtraDocuments(): void
    {
        $this->fakeOcr();
        $user = $this->createUser();

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $passportStep = $this->uploadAndRunOcr($user, UserPaperStepKey::Passport);
        $this->uploadStep($user, UserPaperStepKey::Portrait);

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/submit', [
            'steps' => [
                'residence_card' => $this->residenceCardFields($residenceStep, [
                    'residence_status' => ResidenceStatus::PermanentResident->value,
                ]),
                'passport' => $this->passportFields($passportStep),
            ],
        ]);

        $response->assertStatus(200);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    /**
     * The card in hand and its scanned copy naming different people is exactly what reading
     * them as two runs is for: the submission goes through, carrying the warning.
     */
    public function testScanNamingSomeoneElseIsRecordedAsAMismatch(): void
    {
        $this->fakeOcr(scanName: 'TRAN THI B');
        $user = $this->createUser();

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $passportStep = $this->uploadAndRunOcr($user, UserPaperStepKey::Passport);
        $this->uploadStep($user, UserPaperStepKey::Portrait);

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/submit', [
            'steps' => [
                'residence_card' => $this->residenceCardFields($residenceStep, [
                    'residence_status' => ResidenceStatus::PermanentResident->value,
                ]),
                'passport' => $this->passportFields($passportStep),
            ],
        ]);

        $response->assertStatus(200);

        $mismatches = $response->json('data.mismatches');
        $this->assertCount(1, $mismatches);
        $this->assertSame('name', $mismatches[0]['field']);
        $this->assertSame(
            ['residence_card', 'residence_card_scan', 'passport'],
            array_column($mismatches[0]['values'], 'group')
        );

        // The employee's overview carries the same verdict the admin will see
        $overview = $this->actingAs($user, 'api')->getJson('/user/document-steps');
        $this->assertSame($mismatches, $overview->json('data.mismatches'));
    }

    /**
     * A name printed in a different order is the same person, not a mismatch.
     */
    public function testNamePartsInAnotherOrderDoNotWarn(): void
    {
        $this->fakeOcr(scanName: 'VAN A NGUYEN');
        $user = $this->createUser();

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $passportStep = $this->uploadAndRunOcr($user, UserPaperStepKey::Passport);
        $this->uploadStep($user, UserPaperStepKey::Portrait);

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/submit', [
            'steps' => [
                'residence_card' => $this->residenceCardFields($residenceStep, [
                    'residence_status' => ResidenceStatus::PermanentResident->value,
                ]),
                'passport' => $this->passportFields($passportStep),
            ],
        ]);

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.mismatches'));
    }

    /**
     * One step failing rolls the whole submit back — the point of submitting together.
     */
    public function testAStaleStepRollsTheWholeSubmitBack(): void
    {
        $this->fakeOcr();
        $user = $this->createUser();

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $passportStep = $this->uploadAndRunOcr($user, UserPaperStepKey::Passport);
        $this->uploadStep($user, UserPaperStepKey::Portrait);
        $cardBefore = $user->refresh()->info?->residence_number_1;

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/submit', [
            'steps' => [
                'residence_card' => $this->residenceCardFields($residenceStep, [
                    'residence_status' => ResidenceStatus::PermanentResident->value,
                ]),
                // Built from an OCR run that is no longer the passport step's
                'passport' => $this->passportFields($passportStep, ['ocr_result_id' => 999999]),
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'USER_PAPER_STEP_CHANGED');
        $response->assertJsonPath('data.step', UserPaperStepKey::Passport->value);

        // The residence card was applied before the passport failed and must be rolled back
        $user->refresh();
        $this->assertSame($cardBefore, $user->info?->residence_number_1);
        $this->assertNotContains('AB12345678CD', [
            $user->info?->residence_number_1,
            $user->info?->residence_number_2,
            $user->info?->residence_number_3,
        ]);
        $this->assertSame(UserPaperStatus::Pending->value, $user->paper_status);
        $this->assertSame(
            UserPaperStepStatus::OcrReady->value,
            UserPaperStep::query()->whereKey($residenceStep->id)->value('status')
        );
    }

    /**
     * A full approval deletes every step row so future renewals can start clean. An
     * approved employee renewing one document therefore has neither step rows nor payload
     * entries for the rest — demanding them back would make renewal impossible.
     */
    public function testApprovedEmployeeCanRenewASingleDocument(): void
    {
        $this->fakeOcr();
        $user = $this->createUser(['paper_status' => UserPaperStatus::Approved->value, 'cross_staff_id' => 'CS-1']);

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/submit', [
            'steps' => [
                'residence_card' => $this->residenceCardFields($residenceStep, [
                    'residence_status' => ResidenceStatus::PermanentResident->value,
                ]),
            ],
        ]);

        $response->assertStatus(200);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    /**
     * A step whose reading has not finished cannot be submitted: the client has no fields
     * to send yet, and accepting one is how a submission slipped past a run that was being
     * redone. The step must stay where it is.
     */
    public function testASubmitIsRefusedWhileAStepsOcrIsStillRunning(): void
    {
        $this->fakeOcr();
        $user = $this->createUser();

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $this->uploadStep($user, UserPaperStepKey::Portrait);

        // Uploaded, and its queued OCR genuinely has not run
        $passportStep = $this->uploadStep($user, UserPaperStepKey::Passport);
        $this->assertSame(UserPaperStepStatus::OcrProcessing->value, $passportStep->refresh()->status);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/submit', [
                'steps' => [
                    'residence_card' => $this->residenceCardFields($residenceStep, [
                        'residence_status' => ResidenceStatus::PermanentResident->value,
                    ]),
                    'passport' => $this->passportFields($passportStep),
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'USER_PAPER_STEP_OCR_STILL_PROCESSING')
            ->assertJsonPath('data.step', UserPaperStepKey::Passport->value);

        $this->assertSame(UserPaperStepStatus::OcrProcessing->value, $passportStep->refresh()->status);
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
    }

    /**
     * A run that lands after the step already left ocr_processing still has to feed the
     * verdict. The residence card reads two groups: an unreadable scan fails the step
     * straight away while the card's own run is still out, and tying the recomputation to
     * the status transition left that late answer out of the comparison for good.
     */
    public function testALateRunStillRefreshesTheVerdict(): void
    {
        $this->fakeOcr(
            overrides: [
                // The provider cannot read the scan's card number, which fails its run
                'residence_card_scan' => ['residence_number' => null],
                // ...and the card itself names someone other than the passport
                'residence_card' => ['name' => 'TRAN THI B'],
            ]
        );
        $user = $this->createUser();

        $this->uploadAndRunOcr($user, UserPaperStepKey::Passport);
        $residenceStep = $this->uploadStep($user, UserPaperStepKey::ResidenceCard);
        $runs = $residenceStep->refresh()->ocrResults->keyBy('ocr_group');

        // The scan's run finishes first and takes the step out of ocr_processing
        $this->runOcrJob($residenceStep, $runs['residence_card_scan']->id);
        $this->assertSame(UserPaperStepStatus::OcrFailed->value, $residenceStep->refresh()->status);
        $this->assertSame([], $user->paper()->firstOrFail()->document_mismatches ?? []);

        // The card's own run lands afterwards, and is what the passport disagrees with
        $this->runOcrJob($residenceStep, $runs['residence_card']->id);

        $mismatches = $user->paper()->firstOrFail()->document_mismatches;
        $this->assertSame(['name'], array_column($mismatches, 'field'));
        $this->assertSame(['passport', 'residence_card'], array_column($mismatches[0]['values'], 'group'));
    }

    /**
     * The card number is the field that says whether the scan is a copy of the card in
     * hand — the whole reason the two readings are kept apart.
     */
    public function testAScanOfADifferentCardIsReportedOnTheCardNumber(): void
    {
        $this->fakeOcr(overrides: ['residence_card_scan' => ['residence_number' => 'ZZ99999999ZZ']]);
        $user = $this->createUser();

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $this->uploadStep($user, UserPaperStepKey::Portrait);
        $passportStep = $this->uploadAndRunOcr($user, UserPaperStepKey::Passport);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/submit', [
                'steps' => [
                    'residence_card' => $this->residenceCardFields($residenceStep, [
                        'residence_status' => ResidenceStatus::PermanentResident->value,
                    ]),
                    'passport' => $this->passportFields($passportStep),
                ],
            ])
            ->assertStatus(200);

        $mismatches = $user->paper()->firstOrFail()->document_mismatches;
        $this->assertSame(['residence_number'], array_column($mismatches, 'field'));
        $this->assertSame(['residence_card', 'residence_card_scan'], array_column($mismatches[0]['values'], 'group'));
    }

    public function testRejectsStepsThatCarryNoConfirmableFields(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/submit', [
            'steps' => ['portrait' => ['ocr_result_id' => 1]],
        ]);

        $response->assertStatus(422)->assertJsonPath('error', 'USER_PAPER_STEP_NOT_CONFIRMABLE');
    }

    public function testDateWrittenInLocalDigitsIsReportedOnItsFieldAndWritesNothing(): void
    {
        $this->fakeOcr();
        $user = $this->createUser();

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $passportStep = $this->uploadAndRunOcr($user, UserPaperStepKey::Passport);
        $this->uploadStep($user, UserPaperStepKey::Portrait);
        $passportBefore = $user->refresh()->info?->passport_number;

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/submit', [
            'steps' => [
                'residence_card' => $this->residenceCardFields($residenceStep, [
                    'residence_status' => ResidenceStatus::PermanentResident->value,
                ]),
                'passport' => $this->passportFields($passportStep, ['passport_expiry_date' => '२०३३-११-२२']),
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error', 'USER_PAPER_FIELD_INVALID_DATE');
        $this->assertSame(
            ['steps.passport.passport_expiry_date' => 'USER_PAPER_FIELD_INVALID_DATE'],
            $response->json('data.error_codes')
        );
        $this->assertSame(['steps.passport.passport_expiry_date'], array_keys($response->json('data.errors')));
        $this->assertSame($passportBefore, $user->refresh()->info?->passport_number);
        $this->assertNotSame(UserPaperStatus::WaitForApproval->value, $user->paper_status);
    }

    public function testEachInvalidFieldCarriesItsOwnErrorCode(): void
    {
        $this->fakeOcr();
        $user = $this->createUser();
        $otherUser = $this->createUser();
        UserInfo::factory()->create(['user_id' => $otherUser->id, 'passport_number' => 'TAKEN123']);

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $passportStep = $this->uploadAndRunOcr($user, UserPaperStepKey::Passport);

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/submit', [
            'steps' => [
                'residence_card' => $this->residenceCardFields($residenceStep, [
                    'residence_number' => 'AB123',
                    'residence_status' => 'not-a-status',
                    'first_name' => '',
                    'address' => str_repeat('a', 256),
                ]),
                'passport' => $this->passportFields($passportStep, ['passport_number' => 'TAKEN123']),
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            [
                'steps.residence_card.residence_number' => 'USER_PAPER_RESIDENCE_NUMBER_INVALID_LENGTH',
                'steps.residence_card.residence_status' => 'USER_PAPER_FIELD_INVALID_VALUE',
                'steps.residence_card.first_name' => 'USER_PAPER_FIELD_REQUIRED',
                'steps.residence_card.address' => 'USER_PAPER_FIELD_TOO_LONG',
                'steps.passport.passport_number' => 'REGISTER_PASSPORT_NUMBER_ALREADY_EXISTS',
            ],
            $response->json('data.error_codes')
        );
        $response->assertJsonPath('error', 'USER_PAPER_RESIDENCE_NUMBER_INVALID_LENGTH');
    }

    public function testRequiresAuthentication(): void
    {
        $this->postJson('/user/document-steps/submit')->assertStatus(401);
    }

    /**
     * The renewal that switches the card to 留学 is exactly the case the atomic submit
     * exists for: the prior approval was granted while the card said 永住者, so it says
     * nothing about a student card or a 資格外活動許可. Inheriting it wholesale would let
     * an approved employee walk back into review as a student with neither document.
     */
    public function testApprovedRenewalSwitchingToStudyAbroadMustSupplyTheExtraDocuments(): void
    {
        $this->fakeOcr();
        $user = $this->createUser(['paper_status' => UserPaperStatus::Approved->value, 'cross_staff_id' => 'CS-1']);
        // Approved as 永住者: the card carried 就労制限なし, so no work permit was ever asked for
        $user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2025-01-01',
            'residence_status_1' => ResidenceStatus::PermanentResident->value,
            'residence_number_2' => null,
            'residence_expiry_date_2' => null,
            'residence_status_2' => null,
            'residence_number_3' => null,
            'residence_expiry_date_3' => null,
            'residence_status_3' => null,
        ]);
        // What the migration backfills for a row approved before the column existed: the
        // set the approved 永住者 card called for, which is exactly the three this renewal
        // may carry over — the two 留学 adds are what it must not
        $user
            ->paper()
            ->firstOrCreate([])
            ->update([
                'approved_step_keys' => [
                    UserPaperStepKey::ResidenceCard->value,
                    UserPaperStepKey::Passport->value,
                    UserPaperStepKey::Portrait->value,
                ],
            ]);

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $cardBefore = $user->refresh()->info?->residence_number_1;

        $incomplete = $this->actingAs($user, 'api')->postJson('/user/document-steps/submit', [
            'steps' => [
                'residence_card' => $this->residenceCardFields($residenceStep, [
                    'residence_status' => ResidenceStatus::StudyAbroad->value,
                ]),
            ],
        ]);

        $incomplete->assertStatus(422);
        $incomplete->assertJsonPath('error', 'USER_PAPER_SUBMISSION_INCOMPLETE');
        $this->assertSame(
            [UserPaperStepKey::StudentCard->value, UserPaperStepKey::WorkPermit->value],
            $incomplete->json('data.missing_steps')
        );

        // Nothing was written and the employee keeps the approval they already had
        $user->refresh();
        $this->assertSame($cardBefore, $user->info?->residence_number_1);
        $this->assertSame(UserPaperStatus::Approved->value, $user->paper_status);

        // Supplying them completes the same renewal
        $studentStep = $this->uploadAndRunOcr($user, UserPaperStepKey::StudentCard);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/submit', [
                'steps' => [
                    'residence_card' => $this->residenceCardFields($residenceStep, [
                        'residence_status' => ResidenceStatus::StudyAbroad->value,
                    ]),
                    'student_card' => [
                        'ocr_result_id' => $studentStep->refresh()->ocr_result_id,
                        'student_card_expiry_date' => '2027-03-31',
                    ],
                ],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    /**
     * The boundary of the rule above. An employee already approved as 留学 keeps that
     * approval even when the student card and the work permit predate the step flow and
     * are nowhere on file — the branch surfaces that gap to the admin instead of reopening
     * the employee's status, so a renewal that does not change the status must go through.
     */
    public function testApprovedStudyAbroadRenewalIsNotAskedForDocumentsItNeverHadOnFile(): void
    {
        $this->fakeOcr();
        $user = $this->createUser(['paper_status' => UserPaperStatus::Approved->value, 'cross_staff_id' => 'CS-1']);
        // Approved under the old flow: the card says 留学, but no step rows or items exist
        $user->info->update([
            'residence_number_1' => 'AA11111111AA',
            'residence_expiry_date_1' => '2025-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_2' => null,
            'residence_expiry_date_2' => null,
            'residence_status_2' => null,
            'residence_number_3' => null,
            'residence_expiry_date_3' => null,
            'residence_status_3' => null,
        ]);
        // What the migration backfills for a row approved before the column existed: the set
        // the approved 留学 card called for. That is what carries the approval across this
        // renewal even though neither document was ever filed.
        $user
            ->paper()
            ->firstOrCreate([])
            ->update([
                'approved_step_keys' => [
                    UserPaperStepKey::ResidenceCard->value,
                    UserPaperStepKey::Passport->value,
                    UserPaperStepKey::Portrait->value,
                    UserPaperStepKey::StudentCard->value,
                    UserPaperStepKey::WorkPermit->value,
                ],
            ]);

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/submit', [
                'steps' => [
                    'residence_card' => $this->residenceCardFields($residenceStep, [
                        'residence_status' => ResidenceStatus::StudyAbroad->value,
                    ]),
                ],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    /**
     * The other half of the same rule: a 留学 employee whose student card and work permit
     * were approved in a previous cycle must not be asked for them again when renewing
     * only the residence card. Runs the whole lifecycle — submit, admin approval, renewal —
     * so the approved items are the ones production actually leaves behind.
     */
    public function testApprovedStudyAbroadRenewalKeepsTheDocumentsApprovedLastCycle(): void
    {
        $this->fakeOcr();
        $user = $this->createUser(['cross_staff_id' => 'CS-1']);

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $passportStep = $this->uploadAndRunOcr($user, UserPaperStepKey::Passport);
        $studentStep = $this->uploadAndRunOcr($user, UserPaperStepKey::StudentCard);
        $this->uploadStep($user, UserPaperStepKey::Portrait);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/submit', [
                'steps' => [
                    'residence_card' => $this->residenceCardFields($residenceStep),
                    'passport' => $this->passportFields($passportStep),
                    'student_card' => [
                        'ocr_result_id' => $studentStep->refresh()->ocr_result_id,
                        'student_card_expiry_date' => '2027-03-31',
                    ],
                ],
            ])
            ->assertStatus(200);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);

        $this->approveEveryPendingItem($user);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);

        // Renewing just the card: the extras are on file, so they must not be demanded again
        $renewedCard = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/submit', [
                'steps' => ['residence_card' => $this->residenceCardFields($renewedCard)],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    /**
     * An upload the submission leaves behind holds the whole cycle back, because every step
     * row has to be confirmed before the paper reaches review. Saying so is the difference
     * between a fixable error and a submit that returns 200 and then goes nowhere.
     */
    public function testAnUploadedButUnconfirmedStepBlocksTheSubmitInsteadOfStrandingIt(): void
    {
        $this->fakeOcr();
        $user = $this->createUser();

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $passportStep = $this->uploadAndRunOcr($user, UserPaperStepKey::Passport);
        $this->uploadStep($user, UserPaperStepKey::Portrait);
        // Uploaded while the user was still expecting to declare 留学, then thought better of it
        $studentStep = $this->uploadAndRunOcr($user, UserPaperStepKey::StudentCard);

        $permanentResident = [
            'residence_card' => $this->residenceCardFields($residenceStep, [
                'residence_status' => ResidenceStatus::PermanentResident->value,
            ]),
            'passport' => $this->passportFields($passportStep),
        ];

        $incomplete = $this->actingAs($user, 'api')->postJson('/user/document-steps/submit', [
            'steps' => $permanentResident,
        ]);

        $incomplete->assertStatus(422);
        $incomplete->assertJsonPath('error', 'USER_PAPER_SUBMISSION_INCOMPLETE');
        $this->assertSame([UserPaperStepKey::StudentCard->value], $incomplete->json('data.missing_steps'));
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);

        // Confirming the leftover along with the rest is what unblocks it
        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/submit', [
                'steps' => $permanentResident + [
                    'student_card' => [
                        'ocr_result_id' => $studentStep->refresh()->ocr_result_id,
                        'student_card_expiry_date' => '2027-03-31',
                    ],
                ],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    /**
     * A step still waiting on its OCR is the same kind of leftover: submitting around it
     * would accept a submission the completeness check then refuses to move.
     */
    public function testAStepStillProcessingOcrBlocksTheSubmit(): void
    {
        $this->fakeOcr();
        $user = $this->createUser();

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $this->uploadStep($user, UserPaperStepKey::Portrait);
        // Uploaded, but its queued OCR has not run yet
        $this->uploadStep($user, UserPaperStepKey::Passport);

        $response = $this->actingAs($user, 'api')->postJson('/user/document-steps/submit', [
            'steps' => [
                'residence_card' => $this->residenceCardFields($residenceStep, [
                    'residence_status' => ResidenceStatus::PermanentResident->value,
                ]),
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'USER_PAPER_SUBMISSION_INCOMPLETE');
        $this->assertSame([UserPaperStepKey::Passport->value], $response->json('data.missing_steps'));
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
    }

    /**
     * Approve the whole pending set the way an admin does, so the renewal that follows
     * starts from the state a real approval leaves behind.
     */
    private function approveEveryPendingItem(User $user): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::UserPaperApprove]);
        $itemIds = UserPaperItem::query()
            ->whereIn('user_paper_id', $user->paper()->select('id'))
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->pluck('id');

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/approve", [
                'items' => $itemIds->map(fn($id) => ['id' => $id, 'approved' => true])->all(),
            ])
            ->assertStatus(200);
    }

    private function uploadStep(User $user, UserPaperStepKey $step): UserPaperStep
    {
        $this->actingAs($user, 'api')
            ->postJson("/user/document-steps/{$step->value}/upload", ['files' => $this->filesFor($step)])
            ->assertStatus(200);

        return UserPaperStep::query()
            ->whereIn('user_paper_id', $user->paper()->select('id'))
            ->where('step_key', $step->value)
            ->firstOrFail();
    }

    /**
     * Upload through the endpoint and let the queued OCR jobs run, so the step reaches
     * ocr_ready the same way it does in production.
     */
    private function uploadAndRunOcr(User $user, UserPaperStepKey $step): UserPaperStep
    {
        $stepRow = $this->runQueuedOcrFor($this->uploadStep($user, $step));

        $this->assertSame(UserPaperStepStatus::OcrReady->value, $stepRow->status);

        return $stepRow;
    }

    /**
     * Run one of the step's queued extractions, so a test can choose the order the
     * groups of a multi-group step come back in.
     */
    private function runOcrJob(UserPaperStep $step, int $ocrResultId): void
    {
        (new ExtractDocumentStepOcrJob($ocrResultId, $step->id))->handle(app(OcrResultCacheService::class));
    }

    /**
     * @return array<string, mixed>
     */
    private function filesFor(UserPaperStepKey $step): array
    {
        return match ($step) {
            UserPaperStepKey::ResidenceCard => [
                UserPaperType::ForeignerCardFront->value => UploadedFile::fake()->image('front.jpg'),
                UserPaperType::ForeignerCardBack->value => UploadedFile::fake()->image('back.jpg'),
                UserPaperType::ForeignerCardScan->value => UploadedFile::fake()->image('scan.jpg'),
            ],
            UserPaperStepKey::Passport => [
                UserPaperType::Passport->value => [UploadedFile::fake()->image('passport.jpg')],
            ],
            UserPaperStepKey::StudentCard => [
                UserPaperType::StudentCardFront->value => UploadedFile::fake()->image('sc-front.jpg'),
                UserPaperType::StudentCardBack->value => UploadedFile::fake()->image('sc-back.jpg'),
            ],
            UserPaperStepKey::Portrait => [
                UserPaperType::PortraitImage->value => UploadedFile::fake()->image('portrait.jpg'),
            ],
            UserPaperStepKey::WorkPermit => [
                UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('stamp.jpg'),
            ],
            UserPaperStepKey::Other => [UserPaperType::Other->value => [UploadedFile::fake()->image('other.jpg')]],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function residenceCardFields(UserPaperStep $step, array $overrides = []): array
    {
        return array_merge(
            [
                'ocr_result_id' => $step->refresh()->ocr_result_id,
                'residence_number' => 'AB12345678CD',
                'residence_expiry_date' => '2030-01-01',
                'residence_status' => ResidenceStatus::StudyAbroad->value,
                'first_name' => 'VAN A',
                'last_name' => 'NGUYEN',
            ],
            $overrides
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function passportFields(UserPaperStep $step, array $overrides = []): array
    {
        return array_merge(
            [
                'ocr_result_id' => $step->refresh()->ocr_result_id,
                'passport_number' => 'C1234567',
                'passport_expiry_date' => '2031-05-05',
            ],
            $overrides
        );
    }

    /**
     * Answer each OCR group with the fields that group extracts. The scan's name can be
     * overridden to stage a document that does not belong to the same person, and any
     * group's fields can be replaced outright — a required field set to null is what an
     * image the provider cannot read looks like.
     *
     * @param array<string, array<string, mixed>> $overrides
     */
    private function fakeOcr(string $scanName = 'NGUYEN VAN A', array $overrides = []): void
    {
        $stub = $this->documentOcrProviderStub(
            array_replace_recursive(['residence_card_scan' => ['name' => $scanName]], $overrides)
        );

        // Catch-all rather than the stub map: this test also lets CrossStaff be answered
        // by the same fake, the way it always has
        Http::fake($stub['openrouter.ai/*']);
    }
}
