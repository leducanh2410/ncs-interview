<?php

namespace Tests\Feature\Api\Admin;

use App\Actions\Admin\User\ResolveMissingRequiredDocumentsAction;
use App\Actions\User\Document\ResolveRequiredStepKeysAction;
use App\Enums\PermissionName;
use App\Enums\ResidenceStatus;
use App\Enums\RoleName;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Models\UserPaperStep;
use App\Services\Admin\UserService;
use App\Services\User\DocumentStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Concerns\FakesDocumentOcr;
use Tests\Concerns\ResetsUserDocumentInfo;
use Tests\TestCase;

/**
 * Sending an approved employee back to file documents their residence status requires.
 *
 * The employee here is the one the papers screen has always only been able to warn about:
 * approved under 留学 before the 資格外活動許可 became mandatory, still working, with nothing
 * in the system able to ask for it.
 */
class UserPaperRequestCompletionTest extends TestCase
{
    use FakesDocumentOcr;
    use RefreshDatabase;
    use ResetsUserDocumentInfo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        // .env.testing runs the queue synchronously, so without this an upload comes back
        // with its reading already done and ocr_processing is never a state a test stands in
        Queue::fake();
        Mail::fake();
        Http::fake($this->documentOcrProviderStub());
    }

    public function testApprovedStudyAbroadFileMissingDocumentsIsSentBackToPending(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser();

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(200)
            ->assertJsonPath('data.paper_status', UserPaperStatus::Pending->value)
            ->assertJsonPath('data.missing_steps', [
                UserPaperStepKey::StudentCard->value,
                UserPaperStepKey::WorkPermit->value,
            ]);

        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
    }

    /**
     * The point of the status change: CheckUserPaperStatus opens check-in on paper_status
     * alone, so the employee stops working until the file is complete.
     */
    public function testTheEmployeeLosesTheRoutesTheirApprovalOpened(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser();

        $this->actingAs($user, 'api')->getJson('/user/check-in/shift')->assertStatus(200);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(200);

        // actingAs authenticates the in-memory instance, which still carries the old status
        $this->actingAs($user->refresh(), 'api')->getJson('/user/check-in/shift')->assertStatus(403);
    }

    /**
     * The clause the whole feature rests on — "filing them flips the file back to review
     * through the ordinary completeness check" — walked end to end through the real
     * endpoints, because nothing short of that reaches the states it crosses: the three
     * documents already on file are covered by approved items rather than step rows, the
     * uploads happen while the paper is `pending` so no renewal marker is set, and the
     * approval that follows is what records the set that is finally really there.
     */
    public function testFilingTheRequestedDocumentsCarriesTheFileBackThroughReviewToApproval(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser();

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(200);
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);

        // Only the two documents the admin asked for. The residence card, passport and
        // portrait approved in the previous cycle must not be demanded a second time.
        $studentStep = $this->uploadAndRunOcr($user, UserPaperStepKey::StudentCard);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->assertFalse($user->paper()->firstOrFail()->submission_started_from_approved);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/submit', [
                'steps' => [
                    UserPaperStepKey::StudentCard->value => [
                        'ocr_result_id' => $studentStep->refresh()->ocr_result_id,
                        'student_card_expiry_date' => '2027-03-31',
                    ],
                ],
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);

        $this->approveEveryPendingItem($admin, $user);

        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
        $this->assertSame(
            [
                UserPaperStepKey::ResidenceCard->value,
                UserPaperStepKey::Passport->value,
                UserPaperStepKey::Portrait->value,
                UserPaperStepKey::StudentCard->value,
                UserPaperStepKey::WorkPermit->value,
            ],
            $user->paper()->firstOrFail()->approved_step_keys
        );
        $this->actingAs($user->refresh(), 'api')->getJson('/user/check-in/shift')->assertStatus(200);
    }

    /**
     * The same request answered by an employee who already had a renewal open when the
     * admin pressed the button — the ordinary state for someone renewing a card.
     *
     * The upload happened while the file was still `approved`, so it carries the renewal
     * marker, and the set the approval recorded is what the migration backfilled: every
     * document 留学 demands, including the two that were never actually filed. Left alone
     * that set stands in for exactly the documents the admin just asked for, and a submit
     * carrying only the new card would close the request having filed neither.
     */
    public function testARenewalOpenedBeforeTheRequestStillHasToFileTheMissingDocuments(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser();

        $residenceStep = $this->uploadAndRunOcr($user, UserPaperStepKey::ResidenceCard);
        $this->assertTrue($user->paper()->firstOrFail()->submission_started_from_approved);
        // A renewal that has only uploaded owes nothing yet, so the approval still stands
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(200)
            ->assertJsonPath('data.missing_steps', [
                UserPaperStepKey::StudentCard->value,
                UserPaperStepKey::WorkPermit->value,
            ]);

        // The two documents the admin asked for stop being inherited; the three the last
        // approval really did cover still are
        $this->assertSame(
            [
                UserPaperStepKey::ResidenceCard->value,
                UserPaperStepKey::Passport->value,
                UserPaperStepKey::Portrait->value,
            ],
            $user->paper()->firstOrFail()->approved_step_keys
        );

        $refused = $this->actingAs($user->refresh(), 'api')->postJson('/user/document-steps/submit', [
            'steps' => [
                UserPaperStepKey::ResidenceCard->value => [
                    'ocr_result_id' => $residenceStep->refresh()->ocr_result_id,
                    'residence_number' => 'AB12345678CD',
                    'residence_expiry_date' => '2030-01-01',
                    'residence_status' => ResidenceStatus::StudyAbroad->value,
                    'first_name' => 'VAN A',
                    'last_name' => 'NGUYEN',
                ],
            ],
        ]);

        $refused->assertStatus(422);
        $refused->assertJsonPath('error', 'USER_PAPER_SUBMISSION_INCOMPLETE');
        $this->assertSame(
            [UserPaperStepKey::StudentCard->value, UserPaperStepKey::WorkPermit->value],
            $refused->json('data.missing_steps')
        );
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
    }

    /**
     * The employee finishing a complete submission in the moment between the admin reading
     * the status and the write landing. Overwriting `wait_for_approval` with `pending`
     * strands the file for good — every step row is confirmed, so no upload reopens one,
     * nothing recomputes completeness, and the approve endpoint refuses a file that is not
     * waiting for review.
     */
    public function testAFileThatReachedReviewDuringTheRequestIsLeftAlone(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser();

        // Stand in for the concurrent submit: the resolver has already answered, and the
        // status changes underneath before the action writes
        app()->bind(ResolveMissingRequiredDocumentsAction::class, function () use ($user) {
            return new class ($user) extends ResolveMissingRequiredDocumentsAction {
                public function __construct(private readonly User $racing)
                {
                    parent::__construct(app(ResolveRequiredStepKeysAction::class), app(DocumentStepService::class));
                }

                public function __invoke(User $user): array
                {
                    $missing = parent::__invoke($user);
                    User::query()
                        ->whereKey($this->racing->id)
                        ->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);

                    return $missing;
                }
            };
        });

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(422);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
        // The inherited set is only narrowed together with the status change
        $this->assertContains(UserPaperStepKey::StudentCard->value, $user->paper()->firstOrFail()->approved_step_keys);
    }

    public function testAFileHoldingEveryRequiredDocumentIsRefused(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser(complete: true);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(422)
            ->assertJsonPath('error', 'E_UNPROCESSABLE_ENTITY');

        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
    }

    /**
     * The second reason a file is sent back: every document is there, but the student card
     * carries no expiry date, so the roster cannot tell whether the enrolment has lapsed.
     *
     * Walked end to end, because the state this has to reach is one no fixture describes:
     * the card is covered by approved items, so the submit gate would let a submission that
     * re-files nothing straight through. Only the re-upload row stops it, and only the
     * confirm form that row forces asks for the date.
     */
    public function testAStudentCardWithNoExpiryDateIsSentBackAndAnsweredByReFilingTheCard(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser(complete: true);
        $user->info->update(['student_card_expiry_date' => null]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(200)
            // Nothing is absent, so the admin is not told a document is missing
            ->assertJsonPath('data.missing_steps', [])
            ->assertJsonPath('data.reopened_steps', [UserPaperStepKey::StudentCard->value]);

        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
        $this->assertDatabaseHas('user_paper_steps', [
            'user_paper_id' => $user->paper()->value('id'),
            'step_key' => UserPaperStepKey::StudentCard->value,
            'status' => UserPaperStepStatus::NeedsReupload->value,
        ]);

        // The employee's app is told why a card they can see on their own screen is being
        // asked for again
        $this->actingAs($user->refresh(), 'api')
            ->getJson('/user/document-steps')
            ->assertStatus(200)
            ->assertJsonPath('data.student_card_expiry_missing', true);

        // Re-filing the card is what produces the date, through the form the upload forces
        $studentStep = $this->uploadAndRunOcr($user, UserPaperStepKey::StudentCard);

        $this->actingAs($user, 'api')
            ->postJson('/user/document-steps/submit', [
                'steps' => [
                    UserPaperStepKey::StudentCard->value => [
                        'ocr_result_id' => $studentStep->refresh()->ocr_result_id,
                        'student_card_expiry_date' => '2028-03-31',
                    ],
                ],
            ])
            ->assertStatus(200);

        $user->refresh();
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->paper_status);
        $this->assertSame('2028-03-31', $user->info->student_card_expiry_date);

        // And the approval the date was standing in the way of now goes through
        $this->approveEveryPendingItem($admin, $user);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
    }

    /**
     * The employee was already part-way through filing the card when the admin pressed the
     * button — reachable because an approved employee renewing a document keeps
     * paper_status `approved`, so nothing tells the admin they are mid-upload.
     *
     * Their upload has to survive it. The unconfirmed row holds the cycle back by itself and
     * its confirm asks for the date just the same; resetting it would throw away images they
     * have already taken and send them back to the camera for nothing.
     */
    public function testAnUploadAlreadyInFlightIsNotThrownAwayByTheRequest(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser(complete: true);
        $user->info->update(['student_card_expiry_date' => null]);

        // Filed through the real endpoints, so the row carries a real OCR result
        $inFlight = $this->uploadAndRunOcr($user, UserPaperStepKey::StudentCard);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(200);

        $inFlight->refresh();
        $this->assertSame(UserPaperStepStatus::OcrReady->value, $inFlight->status);
        $this->assertNotNull($inFlight->ocr_result_id, 'the reading they already waited for must survive');

        // And the card they were filing still answers the request
        $this->actingAs($user->refresh(), 'api')
            ->postJson('/user/document-steps/submit', [
                'steps' => [
                    UserPaperStepKey::StudentCard->value => [
                        'ocr_result_id' => $inFlight->ocr_result_id,
                        'student_card_expiry_date' => '2028-03-31',
                    ],
                ],
            ])
            ->assertStatus(200);

        $user->refresh();
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->paper_status);
        $this->assertSame('2028-03-31', $user->info->student_card_expiry_date);
    }

    /**
     * Re-filing nothing must not answer the request, and the employee's screen has to show
     * why: the card's images are still on file and still approved, so without the re-upload
     * row the app would present a step that needs nothing and a submit button the server
     * then refuses.
     */
    public function testTheReopenedStudentCardIsPresentedAsOpenDespiteItsApprovedImages(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser(complete: true);
        $user->info->update(['student_card_expiry_date' => null]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(200);

        $overview = $this->actingAs($user->refresh(), 'api')->getJson('/user/document-steps');
        $overview->assertStatus(200);

        $studentCard = collect($overview->json('data.steps'))->firstWhere('key', UserPaperStepKey::StudentCard->value);
        $this->assertTrue($studentCard['required']);
        $this->assertTrue($studentCard['needs_reupload']);
        $this->assertNull($studentCard['confirmed_at']);
        $this->assertNotEmpty($studentCard['items'], 'the card the employee already filed is still on screen');

        // The approval it was granted under no longer stands in for the card
        $this->assertNotContains(
            UserPaperStepKey::StudentCard->value,
            $user->paper()->firstOrFail()->approved_step_keys
        );
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
    }

    /**
     * A file already waiting for review is the approve modal's business: it can reject the
     * individual documents and reopen exactly those steps, which this cannot.
     */
    public function testAFileWaitingForReviewIsRefused(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser();
        $user->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(422);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    /**
     * Managers are created approved and never file documents, so the resolver would report
     * every required document as absent. Nothing to complete means nothing to ask for.
     */
    public function testAnAccountWithNoDocumentFileIsRefused(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $manager = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $this->resetUserDocumentInfo($manager);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$manager->id}/papers/request-completion")
            ->assertStatus(422);

        $this->assertSame(UserPaperStatus::Approved->value, $manager->refresh()->paper_status);
    }

    public function testTheEndpointNeedsThePaperApprovePermission(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperList);
        $user = $this->makeApprovedStudyAbroadUser();

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(403);

        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
    }

    /**
     * The employee list is the only place an admin meets these files without opening them
     * one by one, so the flag has to point at exactly the files the button can act on.
     */
    public function testTheEmployeeListFlagsOnlyApprovedFilesThatOweDocuments(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserList);

        $owing = $this->makeApprovedStudyAbroadUser();
        $complete = $this->makeApprovedStudyAbroadUser(complete: true);
        $waiting = $this->makeApprovedStudyAbroadUser();
        $waiting->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        // Created approved and never files anything, so every required document reads as absent
        $manager = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $this->resetUserDocumentInfo($manager);

        $rows = collect(
            $this->actingAs($admin, 'api')->getJson('/admin/users?page_size=100')->assertStatus(200)->json('data.data')
        )->keyBy('id');

        $this->assertTrue($rows[$owing->id]['has_missing_required_documents']);
        $this->assertFalse($rows[$complete->id]['has_missing_required_documents']);
        $this->assertFalse($rows[$waiting->id]['has_missing_required_documents']);
        $this->assertFalse($rows[$manager->id]['has_missing_required_documents']);
    }

    /**
     * The file an employee leaves behind when they become an admin.
     *
     * Promotion is a two-step move through the roles screen — the `user` role comes off and
     * an admin role goes on, because the two are mutually exclusive — and the paper row
     * survives it untouched. Nothing asks that account for documents any more, so both
     * badges have to go quiet, including the mismatch its old OCR runs left behind.
     */
    public function testTheEmployeeListStopsFlaggingAnEmployeePromotedToWebAdmin(): void
    {
        $actor = $this->createAdminWithPermissions(PermissionName::UserList, PermissionName::UserAssignRole);
        $employeeRole = Role::query()->create(['name' => RoleName::User->value, 'display_name' => 'User']);
        $adminRole = $this->roleGrantingWebAdminLogin();

        $promoted = $this->makeApprovedStudyAbroadUser();
        $promoted->paper->update(['document_mismatches' => [$this->nameMismatch()]]);
        $promoted->addRole($employeeRole);
        $stillAnEmployee = $this->makeApprovedStudyAbroadUser();
        $stillAnEmployee->paper->update(['document_mismatches' => [$this->nameMismatch()]]);
        $stillAnEmployee->addRole($employeeRole);

        $before = $this->listRows($actor);
        $this->assertTrue($before[$promoted->id]['has_missing_required_documents']);
        $this->assertTrue($before[$promoted->id]['has_document_mismatch']);

        $this->promoteToWebAdmin($actor, $promoted, $adminRole);

        $after = $this->listRows($actor);
        $this->assertFalse($after[$promoted->id]['has_missing_required_documents']);
        $this->assertFalse($after[$promoted->id]['has_document_mismatch']);
        // The same file on an account that stayed an employee is still worth pointing at
        $this->assertTrue($after[$stillAnEmployee->id]['has_missing_required_documents']);
        $this->assertTrue($after[$stillAnEmployee->id]['has_document_mismatch']);
    }

    /**
     * The papers tab draws the same warning from the detail endpoint, so silencing the list
     * alone would just move the badge one screen over.
     *
     * Promoted into a role of the company's own making rather than the seeded `admin`, because
     * admin access is whatever a role carries `manager.auth` on — the check must not be reading
     * role names.
     */
    public function testTheEmployeeDetailStopsFlaggingAnEmployeePromotedToWebAdmin(): void
    {
        $actor = $this->createAdminWithPermissions(PermissionName::UserList, PermissionName::UserAssignRole);
        $employeeRole = Role::query()->create(['name' => RoleName::User->value, 'display_name' => 'User']);
        $adminRole = $this->roleGrantingWebAdminLogin('leader');

        $promoted = $this->makeApprovedStudyAbroadUser();
        $promoted->addRole($employeeRole);

        $this->assertTrue(
            $this->actingAs($actor, 'api')
                ->getJson("/admin/users/{$promoted->id}")
                ->assertStatus(200)
                ->json('data.has_missing_required_documents')
        );

        $this->promoteToWebAdmin($actor, $promoted, $adminRole);

        $this->assertFalse(
            $this->actingAs($actor, 'api')
                ->getJson("/admin/users/{$promoted->id}")
                ->assertStatus(200)
                ->json('data.has_missing_required_documents')
        );
    }

    /**
     * The papers tab is the third surface the warnings reach, and the only one carrying the
     * button that acts on them — silencing the list and the detail screen alone would have
     * moved the badge one tab deeper instead of removing it.
     *
     * Promotion runs through the real roles endpoints, so the account under test is an
     * employee file that survived the move: approved, incomplete, with an old OCR mismatch
     * still on the row.
     */
    public function testThePapersTabStopsWarningAboutAnEmployeePromotedToWebAdmin(): void
    {
        $actor = $this->createAdminWithPermissions(PermissionName::UserPaperList, PermissionName::UserAssignRole);
        $employeeRole = Role::query()->create(['name' => RoleName::User->value, 'display_name' => 'User']);
        $adminRole = $this->roleGrantingWebAdminLogin('leader');

        $promoted = $this->makeApprovedStudyAbroadUser();
        $promoted->paper->update(['document_mismatches' => [$this->nameMismatch()]]);
        $promoted->addRole($employeeRole);

        $before = $this->actingAs($actor, 'api')
            ->getJson("/admin/users/{$promoted->id}/papers")
            ->assertStatus(200)
            ->json('data');
        $this->assertNotEmpty($before['missing_required_steps']);
        $this->assertNotEmpty($before['document_mismatches']);

        $this->promoteToWebAdmin($actor, $promoted, $adminRole);

        $after = $this->actingAs($actor, 'api')
            ->getJson("/admin/users/{$promoted->id}/papers")
            ->assertStatus(200)
            ->json('data');
        $this->assertSame([], $after['missing_required_steps']);
        $this->assertTrue($after['is_web_admin']);
        // The mismatch itself is not silenced: it is what the documents say about each
        // other, which the approve modal reads from this same payload. The flag above is
        // what the tab uses to drop its standing warning.
        $this->assertSame($before['document_mismatches'], $after['document_mismatches']);
        // Silenced for the reader only — the row itself is untouched, so a demotion back to
        // employee finds the same file the promotion left behind
        $this->assertNotEmpty($promoted->paper->refresh()->document_mismatches);
    }

    /**
     * A promotion touches neither paper_status nor the items, so a submission already in
     * review stays in review and an admin still has to approve it. The mismatch warning in
     * the approve modal is part of that decision rather than a reminder aimed at the
     * employee, so it has to survive the promotion the standing warnings do not.
     *
     * `wait_for_approval` carrying recorded mismatches is reached through the real submit
     * flow in DocumentStepSubmitTest::testScanNamingSomeoneElseIsRecordedAsAMismatch; it is
     * set up directly here because what is under test is what the promotion does to it.
     */
    public function testTheApproveModalKeepsItsMismatchWarningForAnEmployeePromotedMidReview(): void
    {
        $actor = $this->createAdminWithPermissions(PermissionName::UserPaperList, PermissionName::UserAssignRole);
        $employeeRole = Role::query()->create(['name' => RoleName::User->value, 'display_name' => 'User']);
        $adminRole = $this->roleGrantingWebAdminLogin('leader');

        $promoted = $this->makeApprovedStudyAbroadUser();
        $promoted->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $promoted->paper->update(['document_mismatches' => [$this->nameMismatch()]]);
        $promoted->addRole($employeeRole);

        $this->promoteToWebAdmin($actor, $promoted, $adminRole);

        $after = $this->actingAs($actor, 'api')
            ->getJson("/admin/users/{$promoted->id}/papers")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $after['status']);
        $this->assertSame([$this->nameMismatch()], $after['document_mismatches']);
        $this->assertTrue($after['is_web_admin']);
        $this->assertSame([], $after['missing_required_steps']);
    }

    /**
     * The complete file is the case the flag is cheapest to get wrong. getPapers() only asks
     * the role query of a file that has something to warn about, and for this account the two
     * document warnings are both quiet — every required document is there and the card
     * carries its expiry date. The mismatch is the only thing left on the tab, so if it did
     * not count as something to warn about the account would read back as an ordinary
     * employee and keep the standing warning the promotion was supposed to drop.
     */
    public function testThePapersTabDropsTheMismatchWarningForAPromotedAccountWhoseFileIsOtherwiseComplete(): void
    {
        $actor = $this->createAdminWithPermissions(PermissionName::UserPaperList, PermissionName::UserAssignRole);
        $employeeRole = Role::query()->create(['name' => RoleName::User->value, 'display_name' => 'User']);
        $adminRole = $this->roleGrantingWebAdminLogin('leader');

        $promoted = $this->makeApprovedStudyAbroadUser(complete: true);
        $promoted->paper->update(['document_mismatches' => [$this->nameMismatch()]]);
        $promoted->addRole($employeeRole);

        $before = $this->actingAs($actor, 'api')
            ->getJson("/admin/users/{$promoted->id}/papers")
            ->assertStatus(200)
            ->json('data');
        // Nothing but the mismatch was ever going to be drawn for this file
        $this->assertSame([], $before['missing_required_steps']);
        $this->assertFalse($before['student_card_expiry_missing']);
        $this->assertNotEmpty($before['document_mismatches']);
        $this->assertNotTrue($before['is_web_admin'] ?? false);

        $this->promoteToWebAdmin($actor, $promoted, $adminRole);

        $after = $this->actingAs($actor, 'api')
            ->getJson("/admin/users/{$promoted->id}/papers")
            ->assertStatus(200)
            ->json('data');

        $this->assertTrue($after['is_web_admin']);
        $this->assertSame($before['document_mismatches'], $after['document_mismatches']);
    }

    /**
     * status, missing_required_steps, student_card_expiry_missing and is_web_admin are
     * display-only and none of them is a column, so leaving them on the model would hand a
     * later save() in the same request either a broken insert or, for document_mismatches, a
     * real column overwritten with a value meant for the screen.
     */
    public function testReadingThePapersTabLeavesNothingPendingOnTheModel(): void
    {
        $user = $this->makeApprovedStudyAbroadUser();
        $user->paper->update(['document_mismatches' => [$this->nameMismatch()]]);

        $paper = app(UserService::class)->getPapers($user->refresh());

        $this->assertSame([], $paper->getDirty());

        $paper->save();
        $this->assertSame([$this->nameMismatch()], $user->paper()->firstOrFail()->document_mismatches);
    }

    /**
     * The button behind the warning has to agree with it. Sending an admin account back to
     * pending would close check-in, shift registration and salary through
     * CheckUserPaperStatus for someone with no employee app to answer the request in.
     */
    public function testCompletionCannotBeRequestedFromAnEmployeePromotedToWebAdmin(): void
    {
        $actor = $this->createAdminWithPermissions(PermissionName::UserPaperApprove, PermissionName::UserAssignRole);
        $employeeRole = Role::query()->create(['name' => RoleName::User->value, 'display_name' => 'User']);
        $adminRole = $this->roleGrantingWebAdminLogin('leader');

        $promoted = $this->makeApprovedStudyAbroadUser();
        $promoted->addRole($employeeRole);
        // The same file on an account that stayed an employee, to show the 422 below comes
        // from the promotion and not from anything else about the file
        $stillAnEmployee = $this->makeApprovedStudyAbroadUser();
        $stillAnEmployee->addRole($employeeRole);

        $this->promoteToWebAdmin($actor, $promoted, $adminRole);

        $this->actingAs($actor, 'api')
            ->postJson("/admin/users/{$promoted->id}/papers/request-completion")
            ->assertStatus(422);
        $this->assertSame(UserPaperStatus::Approved->value, $promoted->refresh()->paper_status);

        $this->actingAs($actor, 'api')
            ->postJson("/admin/users/{$stillAnEmployee->id}/papers/request-completion")
            ->assertStatus(200);
        $this->assertSame(UserPaperStatus::Pending->value, $stillAnEmployee->refresh()->paper_status);
    }

    /**
     * The per-user resolver runs a query for every step it checks, so a page of files would
     * multiply that by every row. Counts only the queries against the tables the flag reads,
     * because the list carries an unrelated per-row query of its own: the face_registered
     * accessor re-queries user_faces instead of using the eager-loaded relation.
     */
    public function testTheListFlagDoesNotQueryPerRow(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserList);
        $this->makeApprovedStudyAbroadUser();

        $documentQueries = 0;
        DB::listen(function ($query) use (&$documentQueries) {
            if (str_contains($query->sql, 'user_paper_steps') || str_contains($query->sql, 'user_paper_items')) {
                $documentQueries++;
            }
        });

        $countForPage = function () use ($admin, &$documentQueries): int {
            $documentQueries = 0;
            $this->actingAs($admin, 'api')->getJson('/admin/users?page_size=100')->assertStatus(200);

            return $documentQueries;
        };

        $one = $countForPage();

        foreach (range(1, 5) as $ignored) {
            $this->makeApprovedStudyAbroadUser();
        }

        $this->assertSame($one, $countForPage(), 'the missing-document flag must not query per row');
    }

    /**
     * Both reasons at once, which is the file this feature was built for: approved under 留学
     * before the 資格外活動許可 became mandatory, with a student card that has been on file
     * the whole time and never carried a date.
     *
     * One request answers both, so both have to come back in `reopened_steps` — the confirm
     * dialog names what the employee is being asked for from exactly this answer, and naming
     * only one of the two would have the admin confirm less than they are about to do.
     */
    public function testAFileMissingBothADocumentAndTheStudentCardExpiryDateReopensBoth(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser(complete: true);
        $user->info->update(['student_card_expiry_date' => null]);
        // The 資格外活動許可 that was not asked for when this file was approved
        UserPaperItem::query()
            ->where('user_paper_id', $user->paper()->value('id'))
            ->where('type', UserPaperType::WorkPermitStamp->value)
            ->delete();

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(200)
            ->assertJsonPath('data.missing_steps', [UserPaperStepKey::WorkPermit->value])
            ->assertJsonPath('data.reopened_steps', [
                UserPaperStepKey::WorkPermit->value,
                UserPaperStepKey::StudentCard->value,
            ]);

        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
        // The card is the one document already in the file: dropping it from the approved
        // set is not enough, the re-upload row is what re-opens the step
        $this->assertDatabaseHas('user_paper_steps', [
            'user_paper_id' => $user->paper()->value('id'),
            'step_key' => UserPaperStepKey::StudentCard->value,
            'status' => UserPaperStepStatus::NeedsReupload->value,
        ]);
        $this->assertEmpty(
            array_intersect($user->paper()->firstOrFail()->approved_step_keys ?? [], [
                UserPaperStepKey::StudentCard->value,
                UserPaperStepKey::WorkPermit->value,
            ])
        );
    }

    /**
     * The overview flag answers "your card is being asked for again", not "your file is
     * missing a date". Until an admin acts, the second is true and the first is not: the
     * file is still approved, the step is still confirmed, and nothing on the employee's
     * screen has changed — so explaining a re-upload that is not happening would only
     * contradict what they can see.
     */
    public function testTheAppIsNotToldTheCardIsBeingAskedForUntilTheStepIsActuallyReopened(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser(complete: true);
        $user->info->update(['student_card_expiry_date' => null]);

        $this->actingAs($user->refresh(), 'api')
            ->getJson('/user/document-steps')
            ->assertStatus(200)
            ->assertJsonPath('data.student_card_expiry_missing', false);

        // The admin's request is what turns the date into a question the employee is asked
        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(200);

        $this->actingAs($user->refresh(), 'api')
            ->getJson('/user/document-steps')
            ->assertStatus(200)
            ->assertJsonPath('data.student_card_expiry_missing', true);
    }

    /**
     * Approved under 留学 with only the documents the old rules asked for, so the student
     * card and the 資格外活動許可 are absent — the exact file the papers screen warns about.
     *
     * `approved_step_keys` carries what the migration backfills for exactly this row: the
     * set the approved 留学 card demands, all five keys, not the four documents that are
     * really on file. That gap is the whole reason a completion request has to narrow the
     * set instead of trusting it.
     */
    private function makeApprovedStudyAbroadUser(bool $complete = false): User
    {
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'SN-' . fake()->unique()->numerify('#####'),
        ]);
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_1' => 'AB12345678CD',
            'residence_expiry_date_1' => now()->addYears(2)->toDateString(),
            // A file that really holds its student card holds the date the confirm form
            // asked for along with it; without it the file owes something after all
            'student_card_expiry_date' => $complete ? '2027-03-31' : null,
        ]);

        $paper = UserPaper::factory()->create([
            'user_id' => $user->id,
            'approved_step_keys' => array_map(
                fn(UserPaperStepKey $key) => $key->value,
                app(ResolveRequiredStepKeysAction::class)(ResidenceStatus::StudyAbroad->value)
            ),
        ]);
        $types = [
            UserPaperType::ForeignerCardFront,
            UserPaperType::ForeignerCardBack,
            UserPaperType::Passport,
            UserPaperType::PortraitImage,
        ];
        if ($complete) {
            $types[] = UserPaperType::StudentCardFront;
            $types[] = UserPaperType::StudentCardBack;
            $types[] = UserPaperType::WorkPermitStamp;
        }

        foreach ($types as $type) {
            UserPaperItem::factory()->create([
                'user_paper_id' => $paper->id,
                'type' => $type->value,
                'approved_at' => now(),
                'rejected_at' => null,
                'deleted_at' => null,
                'folder' => UserPaperItemFolder::Current->value,
            ]);
        }

        return $user->fresh(['info', 'paper']);
    }

    /**
     * Upload a step through the endpoint the app uses.
     */
    private function uploadStep(User $user, UserPaperStepKey $step): UserPaperStep
    {
        $this->actingAs($user->refresh(), 'api')
            ->postJson("/user/document-steps/{$step->value}/upload", ['files' => $this->filesFor($step)])
            ->assertStatus(200);

        return UserPaperStep::query()
            ->whereIn('user_paper_id', $user->paper()->select('id'))
            ->where('step_key', $step->value)
            ->firstOrFail();
    }

    /**
     * Upload and let the queued extraction run, so the step reaches ocr_ready the way it
     * does in production rather than by having its status written by hand.
     */
    private function uploadAndRunOcr(User $user, UserPaperStepKey $step): UserPaperStep
    {
        $stepRow = $this->runQueuedOcrFor($this->uploadStep($user, $step));

        $this->assertSame(UserPaperStepStatus::OcrReady->value, $stepRow->status);

        return $stepRow;
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
            UserPaperStepKey::StudentCard => [
                UserPaperType::StudentCardFront->value => UploadedFile::fake()->image('sc-front.jpg'),
                UserPaperType::StudentCardBack->value => UploadedFile::fake()->image('sc-back.jpg'),
            ],
            UserPaperStepKey::WorkPermit => [
                UserPaperType::WorkPermitStamp->value => UploadedFile::fake()->image('stamp.jpg'),
            ],
            default => throw new LogicException("no fixture files for {$step->value}"),
        };
    }

    /**
     * Approve the whole pending set through the admin endpoint, so the state the renewal
     * starts from is the one a real approval leaves behind.
     */
    private function approveEveryPendingItem(User $admin, User $user): void
    {
        $itemIds = UserPaperItem::query()
            ->whereIn('user_paper_id', $user->paper()->select('id'))
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->whereNull('deleted_at')
            ->pluck('id');

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/approve", [
                'items' => $itemIds->map(fn(int $id) => ['id' => $id, 'approved' => true])->all(),
            ])
            ->assertStatus(200);
    }

    /**
     * @return array<int, array<string, mixed>> list rows keyed by user id
     */
    private function listRows(User $actor): array
    {
        return collect(
            $this->actingAs($actor, 'api')->getJson('/admin/users?page_size=100')->assertStatus(200)->json('data.data')
        )
            ->keyBy('id')
            ->all();
    }

    /**
     * The roles screen's two-step promotion, through the endpoints the screen calls: the
     * `user` role comes off first because AssignRolesAction forbids holding both.
     */
    private function promoteToWebAdmin(User $actor, User $user, Role $adminRole): void
    {
        $this->actingAs($actor, 'api')
            ->deleteJson("/admin/users/{$user->id}/roles", ['role_name' => RoleName::User->value])
            ->assertStatus(200);
        $this->actingAs($actor, 'api')
            ->postJson("/admin/users/{$user->id}/roles", ['role_name' => $adminRole->name])
            ->assertStatus(200);
    }

    /**
     * What both admin clients ask for at login, granted the way production grants it:
     * on a role, never on the user directly.
     */
    private function roleGrantingWebAdminLogin(string $name = RoleName::Admin->value): Role
    {
        $role = Role::query()->create([
            'name' => $name,
            'display_name' => ucfirst($name),
        ]);
        $role->permissions()->attach($this->permission(PermissionName::ManagerAuth));

        return $role;
    }

    /**
     * One disagreeing identity field, in the shape DocumentFieldComparator stores.
     *
     * @return array<string, mixed>
     */
    private function nameMismatch(): array
    {
        return [
            'field' => 'name',
            'values' => [
                ['group' => 'residence_card', 'value' => 'NGUYEN VAN A'],
                ['group' => 'passport', 'value' => 'NGUYEN VAN B'],
            ],
        ];
    }

    private function createAdminWithPermissions(PermissionName ...$permissionNames): User
    {
        $admin = User::factory()->create();
        foreach ($permissionNames as $permissionName) {
            $admin->permissions()->attach($this->permission($permissionName));
        }

        return $admin;
    }

    private function permission(PermissionName $permissionName): Permission
    {
        return Permission::query()->firstOrCreate(
            ['name' => $permissionName->value],
            ['display_name' => $permissionName->value]
        );
    }
}
