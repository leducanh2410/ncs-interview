<?php

namespace Tests\Feature\Api\Admin;

use App\Actions\Admin\User\NotifyDocumentsRequiredAction;
use App\Actions\Admin\User\ReopenOwedDocumentStepsAction;
use App\Actions\Admin\User\ReopenPendingDocumentStepsAction;
use App\Actions\User\Document\ResolveRequiredStepKeysAction;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Jobs\ReopenStuckDocumentStepsJob;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Models\UserPaperStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesDocumentOcr;
use Tests\Concerns\ResetsUserDocumentInfo;
use RuntimeException;
use Tests\TestCase;

class ReopenStuckDocumentStepsTest extends TestCase
{
    use FakesDocumentOcr;
    use RefreshDatabase;
    use ResetsUserDocumentInfo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Queue::fake();
        Mail::fake();
        Http::fake($this->documentOcrProviderStub());
    }

    public function testCompletionRequestReopensAStudentCardOnlyHalfApproved(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedUserWithHalfApprovedStudentCard();

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(200)
            ->assertJsonPath('data.missing_steps', [UserPaperStepKey::StudentCard->value]);

        $studentCard = $this->overviewStep($user, UserPaperStepKey::StudentCard);
        $this->assertTrue($studentCard['needs_reupload']);
        $this->assertNotEmpty($studentCard['items']);

        $this->fileStudentCardAndSubmit($user);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);

        $this->approveEveryPendingItem($admin, $user);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
    }

    public function testCompletionRequestLeavesADocumentWithNoImagesAsNotStarted(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedUserWithHalfApprovedStudentCard(withWorkPermit: false);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(200);

        $workPermit = $this->overviewStep($user, UserPaperStepKey::WorkPermit);
        $this->assertFalse($workPermit['needs_reupload']);
        $this->assertNull($workPermit['status']);
        $this->assertEmpty($workPermit['items']);
    }

    public function testAdminReopensAFileStuckByTheEarlierEnforcement(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperList, PermissionName::UserPaperApprove);
        $user = $this->makeStuckPendingUser();

        $this->assertFalse($this->overviewStep($user, UserPaperStepKey::StudentCard)['needs_reupload']);
        $this->actingAs($admin, 'api')
            ->getJson("/admin/users/{$user->id}/papers")
            ->assertStatus(200)
            ->assertJsonPath('data.reopenable_steps', [UserPaperStepKey::StudentCard->value]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/reopen-steps")
            ->assertStatus(200)
            ->assertJsonPath('data.reopened_steps', [UserPaperStepKey::StudentCard->value]);

        $this->assertTrue($this->overviewStep($user, UserPaperStepKey::StudentCard)['needs_reupload']);
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
        $this->assertSame(1, $this->documentsRequiredNotificationCount($user));
        $this->actingAs($admin, 'api')
            ->getJson("/admin/users/{$user->id}/papers")
            ->assertJsonPath('data.reopenable_steps', []);

        $this->fileStudentCardAndSubmit($user);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);

        $this->approveEveryPendingItem($admin, $user);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
    }

    public function testAdminCannotReopenAMissingDocumentThatWasNeverFiled(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperList, PermissionName::UserPaperApprove);
        $user = $this->makePendingUserMissingOnlyAWorkPermitNeverFiled();

        $this->actingAs($admin, 'api')
            ->getJson("/admin/users/{$user->id}/papers")
            ->assertJsonPath('data.missing_required_steps', [UserPaperStepKey::WorkPermit->value])
            ->assertJsonPath('data.reopenable_steps', []);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/reopen-steps")
            ->assertStatus(422);

        $workPermit = $this->overviewStep($user, UserPaperStepKey::WorkPermit);
        $this->assertFalse($workPermit['needs_reupload']);
        $this->assertEmpty($workPermit['items']);
        $this->assertSame(0, UserPaperStep::query()->count());
        $this->assertSame(0, $this->documentsRequiredNotificationCount($user));

        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);

        $this->approveEveryPendingItem($admin, $user);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
    }

    public function testAStepWhoseOnlyImageOnFileWasRejectedIsNotReopenable(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperList, PermissionName::UserPaperApprove);
        $user = $this->makePendingUserMissingOnlyAWorkPermitNeverFiled();
        UserPaperItem::factory()->create([
            'user_paper_id' => $user->paper->id,
            'type' => UserPaperType::WorkPermitStamp->value,
            'approved_at' => null,
            'rejected_at' => now(),
            'deleted_at' => null,
            'folder' => UserPaperItemFolder::Current->value,
        ]);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/users/{$user->id}/papers")
            ->assertJsonPath('data.missing_required_steps', [UserPaperStepKey::WorkPermit->value])
            ->assertJsonPath('data.reopenable_steps', []);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/reopen-steps")
            ->assertStatus(422);
        $this->assertSame(0, UserPaperStep::query()->count());
        $this->assertSame(0, $this->documentsRequiredNotificationCount($user));
    }

    public function testAStuckFileOnAnAccountThatSignsInToTheAdminIsNotReopened(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperList, PermissionName::UserPaperApprove);
        $user = $this->makeStuckPendingUser();
        $role = Role::query()->create(['name' => 'leader', 'display_name' => 'Leader']);
        $role->permissions()->attach($this->permission(PermissionName::ManagerAuth));
        $user->addRole($role);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/users/{$user->id}/papers")
            ->assertJsonPath('data.reopenable_steps', []);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/reopen-steps")
            ->assertStatus(422)
            ->assertJsonPath('data.message', 'This account signs in to the admin and files no documents');
        $this->assertSame(0, UserPaperStep::query()->count());
        $this->assertSame(0, $this->documentsRequiredNotificationCount($user));
    }

    public function testCommandLeavesAloneAMissingDocumentThatWasNeverFiled(): void
    {
        $user = $this->makePendingUserMissingOnlyAWorkPermitNeverFiled();

        $this->artisan('users:reopen-stuck-document-steps')
            ->expectsOutputToContain('No pending employee is stuck on a document already on file.')
            ->assertSuccessful();

        $this->assertSame(0, UserPaperStep::query()->count());
        $this->assertSame(0, $this->documentsRequiredNotificationCount($user));
    }

    public function testReopeningTwiceIsRefusedAndDoesNotNotifyAgain(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeStuckPendingUser();

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/reopen-steps")
            ->assertStatus(200);
        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/reopen-steps")
            ->assertStatus(422);

        $this->assertSame(1, $this->documentsRequiredNotificationCount($user));
    }

    public function testAnUploadInFlightIsNotReopened(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeStuckPendingUser();
        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/reopen-steps")
            ->assertStatus(200);

        $inFlight = $this->uploadStep($user, UserPaperStepKey::StudentCard);
        $statusBefore = $inFlight->status;

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/reopen-steps")
            ->assertStatus(422);
        $this->assertNotSame(UserPaperStepStatus::NeedsReupload->value, $statusBefore);
        $this->assertSame($statusBefore, $inFlight->refresh()->status);
    }

    public function testOnlyAPendingFileCanBeReopened(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedUserWithHalfApprovedStudentCard();

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/reopen-steps")
            ->assertStatus(422);

        $user->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/reopen-steps")
            ->assertStatus(422);

        $this->assertSame(0, UserPaperStep::query()->count());
    }

    public function testTheEndpointNeedsThePaperApprovePermission(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperList);
        $user = $this->makeStuckPendingUser();

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/reopen-steps")
            ->assertStatus(403);

        $this->assertSame(0, UserPaperStep::query()->count());
    }

    public function testDryRunListsStuckEmployeesAndChangesNothing(): void
    {
        $stuck = $this->makeStuckPendingUser();
        $this->makeApprovedUserWithHalfApprovedStudentCard();

        $this->artisan('users:reopen-stuck-document-steps', ['--dry-run' => true])
            ->expectsOutputToContain('1 employees would have their documents reopened.')
            ->assertSuccessful();

        $this->assertSame(0, UserPaperStep::query()->count());
        $this->assertSame(0, $this->documentsRequiredNotificationCount($stuck));
    }

    public function testAFailedNotificationLeavesNoStepReopened(): void
    {
        $user = $this->makeStuckPendingUser();
        $this->failNotificationsFor([$user->id]);

        try {
            app(ReopenPendingDocumentStepsAction::class)($user);
            $this->fail('the notification failure should have surfaced');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, UserPaperStep::query()->count());
        $this->assertSame(0, $this->documentsRequiredNotificationCount($user));
        $this->assertFalse($this->overviewStep($user, UserPaperStepKey::StudentCard)['needs_reupload']);
    }

    public function testOnlyStepsActuallyInsertedAreReportedAsReopened(): void
    {
        $user = $this->makeStuckPendingUser();
        $paperId = $user->paper()->value('id');
        UserPaperStep::query()->create([
            'user_paper_id' => $paperId,
            'step_key' => UserPaperStepKey::StudentCard->value,
            'status' => UserPaperStepStatus::Uploaded->value,
            'ocr_run_count' => 1,
        ]);

        $reopened = app(ReopenOwedDocumentStepsAction::class)($paperId, [
            UserPaperStepKey::StudentCard->value,
            UserPaperStepKey::WorkPermit->value,
        ]);

        $this->assertSame([UserPaperStepKey::WorkPermit->value], $reopened);
        $this->assertSame(
            UserPaperStepStatus::Uploaded->value,
            UserPaperStep::query()->where('step_key', UserPaperStepKey::StudentCard->value)->value('status')
        );
    }

    public function testCommandSkipsDisabledAccounts(): void
    {
        $disabled = $this->makeStuckPendingUser();
        $disabled->update(['is_disabled' => true]);

        $this->artisan('users:reopen-stuck-document-steps')
            ->expectsOutputToContain('No pending employee is stuck on a document already on file.')
            ->assertSuccessful();

        $this->assertSame(0, UserPaperStep::query()->count());
        $this->assertSame(0, $this->documentsRequiredNotificationCount($disabled));
    }

    public function testCommandCarriesOnPastAFailingEmployeeAndRetriesThemOnTheNextRun(): void
    {
        $failing = $this->makeStuckPendingUser();
        $healthy = $this->makeStuckPendingUser();
        $this->failNotificationsFor([$failing->id]);

        $this->artisan('users:reopen-stuck-document-steps')
            ->expectsOutputToContain('1 employees reopened.')
            ->expectsOutputToContain(
                "1 employees failed and were left unchanged, run the command again to retry: {$failing->id}"
            )
            ->assertFailed();

        $this->assertTrue($this->overviewStep($healthy, UserPaperStepKey::StudentCard)['needs_reupload']);
        $this->assertFalse($this->overviewStep($failing, UserPaperStepKey::StudentCard)['needs_reupload']);
        $this->assertSame(0, $this->documentsRequiredNotificationCount($failing));

        $this->failNotificationsFor([]);

        $this->artisan('users:reopen-stuck-document-steps')
            ->expectsOutputToContain('1 employees reopened.')
            ->assertSuccessful();

        $this->assertTrue($this->overviewStep($failing, UserPaperStepKey::StudentCard)['needs_reupload']);
        $this->assertSame(1, $this->documentsRequiredNotificationCount($failing));
        $this->assertSame(1, $this->documentsRequiredNotificationCount($healthy));
    }

    public function testTheDeployMigrationQueuesEveryEnabledPendingFile(): void
    {
        $stuck = $this->makeStuckPendingUser();
        $neverFiled = $this->makePendingUserMissingOnlyAWorkPermitNeverFiled();
        $disabled = $this->makeStuckPendingUser();
        $disabled->update(['is_disabled' => true]);
        $this->makeApprovedUserWithHalfApprovedStudentCard();

        $jobs = $this->runDeployMigration();

        $this->assertCount(1, $jobs);
        $this->assertSame([$stuck->id, $neverFiled->id], $jobs->first()->userIds);
        $this->assertSame(0, UserPaperStep::query()->count());
    }

    public function testTheQueuedJobsReopenOnlyStuckFilesAndNotifyOnce(): void
    {
        $stuck = $this->makeStuckPendingUser();
        $neverFiled = $this->makePendingUserMissingOnlyAWorkPermitNeverFiled();

        $jobs = $this->runDeployMigration();
        $jobs->each(fn(ReopenStuckDocumentStepsJob $job) => $job->handle(app(ReopenPendingDocumentStepsAction::class)));
        $jobs->each(fn(ReopenStuckDocumentStepsJob $job) => $job->handle(app(ReopenPendingDocumentStepsAction::class)));

        $this->assertTrue($this->overviewStep($stuck, UserPaperStepKey::StudentCard)['needs_reupload']);
        $this->assertSame(1, $this->documentsRequiredNotificationCount($stuck));
        $this->assertFalse($this->overviewStep($neverFiled, UserPaperStepKey::WorkPermit)['needs_reupload']);
        $this->assertSame(0, $this->documentsRequiredNotificationCount($neverFiled));
    }

    public function testAFailingEmployeeFailsTheJobAfterTheRestAreReopenedAndTheRetryFinishesThem(): void
    {
        $failing = $this->makeStuckPendingUser();
        $healthy = $this->makeStuckPendingUser();
        $this->failNotificationsFor([$failing->id]);

        $job = $this->runDeployMigration()->first();
        try {
            $job->handle(app(ReopenPendingDocumentStepsAction::class));
            $this->fail('The job must fail so the queue retries it');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString((string) $failing->id, $e->getMessage());
        }

        $this->assertTrue($this->overviewStep($healthy, UserPaperStepKey::StudentCard)['needs_reupload']);
        $this->assertFalse($this->overviewStep($failing, UserPaperStepKey::StudentCard)['needs_reupload']);

        $this->failNotificationsFor([]);
        $job->handle(app(ReopenPendingDocumentStepsAction::class));

        $this->assertTrue($this->overviewStep($failing, UserPaperStepKey::StudentCard)['needs_reupload']);
        $this->assertSame(1, $this->documentsRequiredNotificationCount($failing));
        $this->assertSame(1, $this->documentsRequiredNotificationCount($healthy));
    }

    public function testTheJobRetriesABoundedNumberOfTimes(): void
    {
        $job = new ReopenStuckDocumentStepsJob([1]);

        $this->assertSame(3, $job->tries);
        $this->assertNotEmpty($job->backoff());
    }

    public function testCommandReopensOnlyStuckEmployees(): void
    {
        $stuck = $this->makeStuckPendingUser();
        $approved = $this->makeApprovedUserWithHalfApprovedStudentCard();

        $this->artisan('users:reopen-stuck-document-steps')
            ->expectsOutputToContain('1 employees reopened.')
            ->assertSuccessful();

        $this->assertTrue($this->overviewStep($stuck, UserPaperStepKey::StudentCard)['needs_reupload']);
        $this->assertSame(1, $this->documentsRequiredNotificationCount($stuck));
        $this->assertSame(
            0,
            UserPaperStep::query()
                ->whereIn('user_paper_id', $approved->paper()->select('id'))
                ->count()
        );
        $this->assertSame(UserPaperStatus::Approved->value, $approved->refresh()->paper_status);

        $this->artisan('users:reopen-stuck-document-steps')
            ->expectsOutputToContain('No pending employee is stuck on a document already on file.')
            ->assertSuccessful();
    }

    /**
     * @return Collection<int, ReopenStuckDocumentStepsJob>
     */
    private function runDeployMigration(): Collection
    {
        Queue::fake();

        $migration = require database_path('migrations/2026_11_03_000001_reopen_stuck_document_steps.php');
        $migration->up();

        return Queue::pushed(ReopenStuckDocumentStepsJob::class);
    }

    /**
     * @param int[] $userIds
     */
    private function failNotificationsFor(array $userIds): void
    {
        $this->app->instance(
            NotifyDocumentsRequiredAction::class,
            new class ($userIds) extends NotifyDocumentsRequiredAction {
                /**
                 * @param int[] $failingUserIds
                 */
                public function __construct(private readonly array $failingUserIds) {}

                public function __invoke(User $user, array $missingSteps, bool $studentCardExpiryMissing): Notification
                {
                    if (in_array($user->id, $this->failingUserIds, true)) {
                        throw new RuntimeException('notification store unavailable');
                    }

                    return parent::__invoke($user, $missingSteps, $studentCardExpiryMissing);
                }
            }
        );
    }

    private function makeStuckPendingUser(): User
    {
        $user = $this->makeApprovedUserWithHalfApprovedStudentCard();
        $user->update(['paper_status' => UserPaperStatus::Pending->value]);

        return $user->fresh(['info', 'paper']);
    }

    private function makePendingUserMissingOnlyAWorkPermitNeverFiled(): User
    {
        $user = $this->makeApprovedUserWithHalfApprovedStudentCard(withWorkPermit: false, halfStudentCard: false);
        $user->update(['paper_status' => UserPaperStatus::Pending->value]);

        return $user->fresh(['info', 'paper']);
    }

    private function makeApprovedUserWithHalfApprovedStudentCard(
        bool $withWorkPermit = true,
        bool $halfStudentCard = true
    ): User {
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'SN-' . fake()->unique()->numerify('#####'),
        ]);
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_1' => 'AB12345678CD',
            'residence_expiry_date_1' => now()->addYears(2)->toDateString(),
            'student_card_expiry_date' => '2027-03-31',
        ]);

        $paper = UserPaper::factory()->create([
            'user_id' => $user->id,
            'approved_step_keys' => array_map(
                fn(UserPaperStepKey $key) => $key->value,
                app(ResolveRequiredStepKeysAction::class)(ResidenceStatus::StudyAbroad->value)
            ),
        ]);

        $approvedTypes = [
            UserPaperType::ForeignerCardFront,
            UserPaperType::ForeignerCardBack,
            UserPaperType::Passport,
            UserPaperType::PortraitImage,
            UserPaperType::StudentCardBack,
        ];
        if ($withWorkPermit) {
            $approvedTypes[] = UserPaperType::WorkPermitStamp;
        }

        foreach ($approvedTypes as $type) {
            $this->createItem($paper, $type, approved: true);
        }
        $this->createItem($paper, UserPaperType::StudentCardFront, approved: !$halfStudentCard);

        return $user->fresh(['info', 'paper']);
    }

    private function createItem(UserPaper $paper, UserPaperType $type, bool $approved): void
    {
        UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'type' => $type->value,
            'approved_at' => $approved ? now() : null,
            'rejected_at' => null,
            'deleted_at' => null,
            'folder' => UserPaperItemFolder::Current->value,
        ]);
    }

    private function fileStudentCardAndSubmit(User $user): void
    {
        $step = $this->runQueuedOcrFor($this->uploadStep($user, UserPaperStepKey::StudentCard));
        $this->assertSame(UserPaperStepStatus::OcrReady->value, $step->status);

        $this->actingAs($user->refresh(), 'api')
            ->postJson('/user/document-steps/submit', [
                'steps' => [
                    UserPaperStepKey::StudentCard->value => [
                        'ocr_result_id' => $step->refresh()->ocr_result_id,
                        'student_card_expiry_date' => '2028-03-31',
                    ],
                ],
            ])
            ->assertStatus(200);
    }

    private function uploadStep(User $user, UserPaperStepKey $step): UserPaperStep
    {
        $this->actingAs($user->refresh(), 'api')
            ->postJson("/user/document-steps/{$step->value}/upload", [
                'files' => array_combine(
                    $step->requiredTypes(),
                    array_map(fn(string $type) => UploadedFile::fake()->image("{$type}.jpg"), $step->requiredTypes())
                ),
            ])
            ->assertStatus(200);

        return UserPaperStep::query()
            ->whereIn('user_paper_id', $user->paper()->select('id'))
            ->where('step_key', $step->value)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function overviewStep(User $user, UserPaperStepKey $step): array
    {
        $overview = $this->actingAs($user->refresh(), 'api')->getJson('/user/document-steps')->assertStatus(200);

        return collect($overview->json('data.steps'))->firstWhere('key', $step->value);
    }

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

    private function documentsRequiredNotificationCount(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('type', NotificationType::PAPER_DOCUMENTS_REQUIRED->value)
            ->count();
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
