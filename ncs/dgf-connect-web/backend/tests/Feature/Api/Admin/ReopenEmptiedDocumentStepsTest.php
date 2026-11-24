<?php

namespace Tests\Feature\Api\Admin;

use App\Actions\Admin\User\NotifyDocumentsRequiredAction;
use App\Actions\Admin\User\ReopenEmptiedDocumentStepsAction;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Jobs\RepairEmptiedDocumentStepsJob;
use App\Mail\DocumentUploadCompleteEmail;
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
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\FakesDocumentOcr;
use Tests\Concerns\ResetsUserDocumentInfo;
use Tests\TestCase;

class ReopenEmptiedDocumentStepsTest extends TestCase
{
    use FakesDocumentOcr;
    use RefreshDatabase;
    use ResetsUserDocumentInfo;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Queue::fake();
        Mail::fake();
        Http::fake($this->documentOcrProviderStub());

        $this->admin = $this->createAdminWithPermissions(
            PermissionName::UserPaperList,
            PermissionName::UserPaperApprove,
            PermissionName::UserPaperDelete
        );
    }

    public function testAdminReopensARequiredDocumentWhoseImagesWereAllDeletedAndTheEmployeeResubmits(): void
    {
        $user = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($user, UserPaperStepKey::Other);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);

        $this->deleteItems($user, $this->activeItemIds($user, UserPaperType::WorkPermitStamp));

        $this->assertSame(
            UserPaperStepStatus::Confirmed->value,
            $this->overviewStep($user, UserPaperStepKey::WorkPermit)['status']
        );
        $this->assertSame([UserPaperStepKey::WorkPermit->value], $this->adminPapers($user)['emptied_steps']);
        $this->assertSame([], $this->adminPapers($user)['missing_required_steps']);

        $this->reopen($user)
            ->assertStatus(200)
            ->assertJsonPath('data.reopened_steps', [UserPaperStepKey::WorkPermit->value])
            ->assertJsonPath('data.cleared_steps', []);

        $workPermit = $this->overviewStep($user, UserPaperStepKey::WorkPermit);
        $this->assertTrue($workPermit['needs_reupload']);
        $this->assertEmpty($workPermit['items']);
        $this->assertSame(
            UserPaperStepStatus::Confirmed->value,
            $this->overviewStep($user, UserPaperStepKey::Other)['status']
        );
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
        $this->assertSame(1, $this->documentsRequiredNotificationCount($user));
        $this->assertSame([], $this->adminPapers($user)['emptied_steps']);

        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);

        $this->approveEveryPendingItem($user);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
    }

    public function testAPartlyDeletedResidenceCardIsReopenedAndALateOcrRunLeavesItOpen(): void
    {
        $user = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($user, UserPaperStepKey::ResidenceCard);
        $stepRow = UserPaperStep::query()->where('step_key', UserPaperStepKey::ResidenceCard->value)->firstOrFail();
        $this->assertSame(UserPaperStepStatus::OcrProcessing->value, $stepRow->status);

        $pendingScanIds = UserPaperItem::query()
            ->where('user_paper_id', $user->paper->id)
            ->where('type', UserPaperType::ForeignerCardScan->value)
            ->whereNull('approved_at')
            ->pluck('id')
            ->all();
        $this->deleteItems($user, $pendingScanIds);

        $this->assertSame([UserPaperStepKey::ResidenceCard->value], $this->adminPapers($user)['emptied_steps']);

        $this->reopen($user)
            ->assertStatus(200)
            ->assertJsonPath('data.reopened_steps', [UserPaperStepKey::ResidenceCard->value]);
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
        $this->assertSame(1, $this->documentsRequiredNotificationCount($user));

        $this->assertSame(UserPaperStepStatus::NeedsReupload->value, $this->runQueuedOcrFor($stepRow)->status);

        $this->uploadStep($user, UserPaperStepKey::ResidenceCard);
        $this->assertSame(UserPaperStepStatus::OcrProcessing->value, $stepRow->refresh()->status);
    }

    public function testAnAdminKeepingAnOlderApprovedDocumentOverTheNewOneLeavesNothingToReopen(): void
    {
        $user = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->approveEveryPendingItem($user);
        $previouslyApprovedStampIds = $this->activeItemIds($user, UserPaperType::WorkPermitStamp);

        $this->travel(1)->minute();
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->uploadStep($user, UserPaperStepKey::Other);
        $renewalStampIds = array_values(
            array_diff($this->activeItemIds($user, UserPaperType::WorkPermitStamp), $previouslyApprovedStampIds)
        );
        $otherIds = $this->activeItemIds($user, UserPaperType::Other);

        $this->travel(1)->minute();
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/approve", [
                'items' => [
                    ['id' => $renewalStampIds[0], 'approved' => true],
                    ['id' => $otherIds[0], 'approved' => false],
                ],
                'rejected_note' => 'Unreadable',
            ])
            ->assertStatus(200);
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/move", [
                'ids' => $renewalStampIds,
                'folder' => UserPaperItemFolder::Old->value,
            ])
            ->assertStatus(200);

        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);
        $this->assertSame(
            $previouslyApprovedStampIds,
            UserPaperItem::query()
                ->where('user_paper_id', $user->paper->id)
                ->where('type', UserPaperType::WorkPermitStamp->value)
                ->where('folder', UserPaperItemFolder::Current->value)
                ->pluck('id')
                ->all()
        );
        $this->assertSame([], $this->adminPapers($user)['emptied_steps']);
        $this->reopen($user)->assertStatus(422);
        $this->runDeployRepair();
        $this->assertSame(0, $this->documentsRequiredNotificationCount($user));
        $this->assertSame(
            UserPaperStepStatus::Confirmed->value,
            $this->overviewStep($user, UserPaperStepKey::WorkPermit)['status']
        );

        $this->uploadStep($user, UserPaperStepKey::Other);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    public function testARejectedFileReopensAnApprovedDocumentMovedToOldAndStaysRejected(): void
    {
        $user = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->uploadStep($user, UserPaperStepKey::Other);
        $stampIds = $this->activeItemIds($user, UserPaperType::WorkPermitStamp);
        $otherIds = $this->activeItemIds($user, UserPaperType::Other);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/approve", [
                'items' => [['id' => $stampIds[0], 'approved' => true], ['id' => $otherIds[0], 'approved' => false]],
                'rejected_note' => 'Unreadable',
            ])
            ->assertStatus(200);
        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);
        $this->assertSame([], $this->adminPapers($user)['emptied_steps']);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/move", [
                'ids' => $stampIds,
                'folder' => UserPaperItemFolder::Old->value,
            ])
            ->assertStatus(200);
        $this->assertSame(
            UserPaperStepStatus::Confirmed->value,
            $this->overviewStep($user, UserPaperStepKey::WorkPermit)['status']
        );
        $this->assertSame([UserPaperStepKey::WorkPermit->value], $this->adminPapers($user)['emptied_steps']);

        $this->reopen($user)
            ->assertStatus(200)
            ->assertJsonPath('data.reopened_steps', [UserPaperStepKey::WorkPermit->value]);
        $this->assertTrue($this->overviewStep($user, UserPaperStepKey::WorkPermit)['needs_reupload']);
        $this->assertSame(UserPaperStatus::Rejected->value, $user->refresh()->paper_status);

        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->uploadStep($user, UserPaperStepKey::Other);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    public function testAnApprovedEmployeeKeepsTheApprovalAndTheEmptiedRenewalIsUnlocked(): void
    {
        $user = $this->makeEmployee(UserPaperStatus::Approved, ResidenceStatus::PermanentResident);
        $approvedCardIds = UserPaperItem::query()
            ->where('user_paper_id', $user->paper->id)
            ->whereIn('type', [UserPaperType::ForeignerCardFront->value, UserPaperType::ForeignerCardBack->value])
            ->pluck('id')
            ->all();

        $this->uploadStep($user, UserPaperStepKey::ResidenceCard);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/move", [
                'ids' => $approvedCardIds,
                'folder' => UserPaperItemFolder::Old->value,
            ])
            ->assertStatus(200);
        $this->deleteItems(
            $user,
            UserPaperItem::query()
                ->where('user_paper_id', $user->paper->id)
                ->whereNull('approved_at')
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all()
        );
        $this->uploadStepExpecting($user, UserPaperStepKey::ResidenceCard, 422);
        $this->assertSame([UserPaperStepKey::ResidenceCard->value], $this->adminPapers($user)['emptied_steps']);

        $this->reopen($user)
            ->assertStatus(200)
            ->assertJsonPath('data.reopened_steps', [])
            ->assertJsonPath('data.cleared_steps', [UserPaperStepKey::ResidenceCard->value]);

        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
        $this->assertSame(0, $this->documentsRequiredNotificationCount($user));
        $this->assertSame(0, UserPaperStep::query()->count());

        $this->uploadStep($user, UserPaperStepKey::ResidenceCard);
    }

    public function testClearingTheOnlyOptionalDocumentHoldingThePendingFileBackSendsItToReview(): void
    {
        $user = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::PermanentResident);
        $this->uploadStep($user, UserPaperStepKey::StudentCard);
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);

        $this->deleteItems(
            $user,
            UserPaperItem::query()
                ->where('user_paper_id', $user->paper->id)
                ->whereIn('type', [UserPaperType::StudentCardFront->value, UserPaperType::StudentCardBack->value])
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all()
        );
        $this->assertSame([UserPaperStepKey::StudentCard->value], $this->adminPapers($user)['emptied_steps']);

        $this->reopen($user)
            ->assertStatus(200)
            ->assertJsonPath('data.reopened_steps', [])
            ->assertJsonPath('data.cleared_steps', [UserPaperStepKey::StudentCard->value]);

        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
        $this->assertSame(0, $this->documentsRequiredNotificationCount($user));
        Mail::assertQueued(DocumentUploadCompleteEmail::class, 1);
    }

    public function testAFileWithEveryDocumentStillOnFileHasNothingToReopen(): void
    {
        $user = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($user, UserPaperStepKey::Other, filesPerType: 2);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->deleteItems($user, [$this->activeItemIds($user, UserPaperType::Other)[0]]);

        $this->assertSame([], $this->adminPapers($user)['emptied_steps']);

        $this->reopen($user)
            ->assertStatus(422)
            ->assertJsonPath('data.message', 'No document is marked as filed with images missing');
        $this->assertSame(
            [UserPaperStepStatus::Confirmed->value],
            UserPaperStep::query()->distinct()->pluck('status')->all()
        );
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
        $this->assertSame(0, $this->documentsRequiredNotificationCount($user));
    }

    public function testRestoringTheImagesBeforeTheAdminActsLeavesNothingToReopen(): void
    {
        $user = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $stampIds = $this->activeItemIds($user, UserPaperType::WorkPermitStamp);
        $this->deleteItems($user, $stampIds);
        $this->assertSame([UserPaperStepKey::WorkPermit->value], $this->adminPapers($user)['emptied_steps']);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/restore", ['ids' => $stampIds])
            ->assertStatus(200);

        $this->reopen($user)->assertStatus(422);
        $this->assertSame(
            UserPaperStepStatus::Confirmed->value,
            $this->overviewStep($user, UserPaperStepKey::WorkPermit)['status']
        );
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
        $this->assertSame(0, $this->documentsRequiredNotificationCount($user));
    }

    public function testASecondPressFromAStaleScreenChangesNothingAndNotifiesOnce(): void
    {
        $user = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->deleteItems($user, $this->activeItemIds($user, UserPaperType::WorkPermitStamp));

        $this->reopen($user)->assertStatus(200);
        $this->reopen($user)->assertStatus(422);

        $this->assertTrue($this->overviewStep($user, UserPaperStepKey::WorkPermit)['needs_reupload']);
        $this->assertSame(1, $this->documentsRequiredNotificationCount($user));
    }

    public function testAnAccountThatSignsInToTheAdminIsNotOfferedTheRepair(): void
    {
        $user = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->deleteItems($user, $this->activeItemIds($user, UserPaperType::WorkPermitStamp));
        $role = Role::query()->create(['name' => 'leader', 'display_name' => 'Leader']);
        $role->permissions()->attach($this->permission(PermissionName::ManagerAuth));
        $user->addRole($role);

        $this->assertSame([], $this->adminPapers($user)['emptied_steps']);
        $this->artisan('users:list-emptied-document-steps')
            ->expectsOutputToContain('No employee has a document marked as filed with images missing.')
            ->assertSuccessful();
        $this->reopen($user)
            ->assertStatus(422)
            ->assertJsonPath('data.message', 'This account signs in to the admin and files no documents');
        $this->assertSame(
            UserPaperStepStatus::Confirmed->value,
            UserPaperStep::query()->where('step_key', UserPaperStepKey::WorkPermit->value)->value('status')
        );
    }

    public function testAnAdminWithoutTheApprovePermissionCannotReopen(): void
    {
        $user = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->deleteItems($user, $this->activeItemIds($user, UserPaperType::WorkPermitStamp));
        $viewer = $this->createAdminWithPermissions(PermissionName::UserPaperList);

        $this->actingAs($viewer, 'api')
            ->postJson("/admin/users/{$user->id}/papers/reopen-emptied-steps")
            ->assertStatus(403);
        $this->assertSame(0, $this->documentsRequiredNotificationCount($user));
    }

    public function testAFailedNotificationRollsBackTheWholeRepair(): void
    {
        $user = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($user, UserPaperStepKey::Other);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->deleteItems($user, $this->activeItemIds($user, UserPaperType::WorkPermitStamp));
        $this->deleteItems($user, $this->activeItemIds($user, UserPaperType::Other));
        $this->mock(NotifyDocumentsRequiredAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->andThrow(new RuntimeException('notification store unavailable'));
        });

        $this->reopen($user)->assertStatus(500);

        $this->assertSame(
            [
                UserPaperStepKey::Other->value => UserPaperStepStatus::Confirmed->value,
                UserPaperStepKey::WorkPermit->value => UserPaperStepStatus::Confirmed->value,
            ],
            UserPaperStep::query()->orderBy('step_key')->pluck('status', 'step_key')->all()
        );
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
        $this->assertSame(
            [UserPaperStepKey::WorkPermit->value, UserPaperStepKey::Other->value],
            $this->adminPapers($user)['emptied_steps']
        );
    }

    public function testListCommandShowsStuckEmployeesWithoutChangingAnything(): void
    {
        $stuck = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($stuck, UserPaperStepKey::WorkPermit);
        $this->deleteItems($stuck, $this->activeItemIds($stuck, UserPaperType::WorkPermitStamp));

        $healthy = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($healthy, UserPaperStepKey::WorkPermit);

        $this->artisan('users:list-emptied-document-steps')
            ->expectsOutputToContain('1 employees have documents marked as filed with images missing.')
            ->assertSuccessful();
        $this->assertSame(
            [UserPaperStepStatus::Confirmed->value],
            UserPaperStep::query()->distinct()->pluck('status')->all()
        );
        $this->assertSame(0, $this->documentsRequiredNotificationCount($stuck));

        $this->reopen($stuck)->assertStatus(200);

        $this->artisan('users:list-emptied-document-steps')
            ->expectsOutputToContain('No employee has a document marked as filed with images missing.')
            ->assertSuccessful();
    }

    public function testTheButtonLeavesARenewalDeletedBeforeTheDeleteButtonWasRemovedToTheDeployRepair(): void
    {
        $user = $this->makeEmployeeWhoseRenewalWasDeleted();

        $this->uploadStepExpecting($user, UserPaperStepKey::WorkPermit, 422);
        $this->assertSame([], $this->adminPapers($user)['emptied_steps']);
        $this->reopen($user)->assertStatus(422);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);
    }

    public function testTheDeployMigrationQueuesEveryEnabledFileWithAFiledStep(): void
    {
        $stuck = $this->makeEmployeeWhoseWorkPermitWasDeleted();
        $renewal = $this->makeEmployeeWhoseRenewalWasDeleted();
        $healthy = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($healthy, UserPaperStepKey::WorkPermit);
        $disabled = $this->makeEmployeeWhoseWorkPermitWasDeleted();
        $disabled->update(['is_disabled' => true]);
        $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);

        $jobs = $this->queueDeployRepair();

        $this->assertCount(1, $jobs);
        $this->assertSame([$stuck->id, $renewal->id, $healthy->id], $jobs->first()->userIds);
        $this->assertSame(
            0,
            UserPaperStep::query()->where('status', UserPaperStepStatus::NeedsReupload->value)->count()
        );
        $this->assertSame(
            0,
            Notification::query()->where('type', NotificationType::PAPER_DOCUMENTS_REQUIRED->value)->count()
        );
    }

    public function testTheDeployRepairFixesEveryStuckFileOnceAndLeavesTheRestAlone(): void
    {
        $stuck = $this->makeEmployeeWhoseWorkPermitWasDeleted();
        $renewal = $this->makeEmployeeWhoseRenewalWasDeleted();
        $healthy = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($healthy, UserPaperStepKey::WorkPermit);
        $signsInToTheAdmin = $this->makeEmployeeWhoseWorkPermitWasDeleted();
        $role = Role::query()->create(['name' => 'leader', 'display_name' => 'Leader']);
        $role->permissions()->attach($this->permission(PermissionName::ManagerAuth));
        $signsInToTheAdmin->addRole($role);

        $this->runDeployRepair();
        $this->runDeployRepair();

        foreach ([$stuck, $renewal] as $user) {
            $this->assertTrue($this->overviewStep($user, UserPaperStepKey::WorkPermit)['needs_reupload']);
            $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
            $this->assertSame(1, $this->documentsRequiredNotificationCount($user));
        }
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $healthy->refresh()->paper_status);
        $this->assertSame(0, $this->documentsRequiredNotificationCount($healthy));
        $this->assertSame(
            UserPaperStepStatus::Confirmed->value,
            UserPaperStep::query()->where('user_paper_id', $signsInToTheAdmin->paper->id)->value('status')
        );
        $this->assertSame(0, $this->documentsRequiredNotificationCount($signsInToTheAdmin));

        $this->uploadStep($renewal, UserPaperStepKey::WorkPermit);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $renewal->refresh()->paper_status);
        $this->approveEveryPendingItem($renewal);
        $this->assertSame(UserPaperStatus::Approved->value, $renewal->refresh()->paper_status);
    }

    public function testAFailingEmployeeFailsTheRepairJobAfterTheRestAreFixedAndTheRetryFinishesThem(): void
    {
        $failing = $this->makeEmployeeWhoseWorkPermitWasDeleted();
        $other = $this->makeEmployeeWhoseRenewalWasDeleted();
        $this->failNotificationsFor([$failing->id]);

        $job = $this->queueDeployRepair()->first();
        try {
            $job->handle(app(ReopenEmptiedDocumentStepsAction::class));
            $this->fail('The job must fail so the queue retries it');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString((string) $failing->id, $e->getMessage());
        }

        $this->assertTrue($this->overviewStep($other, UserPaperStepKey::WorkPermit)['needs_reupload']);
        $this->assertSame(
            UserPaperStepStatus::Confirmed->value,
            $this->overviewStep($failing, UserPaperStepKey::WorkPermit)['status']
        );
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $failing->refresh()->paper_status);

        $this->failNotificationsFor([]);
        $job->handle(app(ReopenEmptiedDocumentStepsAction::class));

        $this->assertTrue($this->overviewStep($failing, UserPaperStepKey::WorkPermit)['needs_reupload']);
        $this->assertSame(1, $this->documentsRequiredNotificationCount($failing));
        $this->assertSame(1, $this->documentsRequiredNotificationCount($other));
    }

    public function testTheRepairJobRetriesABoundedNumberOfTimes(): void
    {
        $job = new RepairEmptiedDocumentStepsJob([1]);

        $this->assertSame(3, $job->tries);
        $this->assertNotEmpty($job->backoff());
    }

    private function makeEmployee(UserPaperStatus $paperStatus, ResidenceStatus $residenceStatus): User
    {
        $user = User::factory()->create([
            'paper_status' => $paperStatus->value,
            'staff_no' => 'SN-' . fake()->unique()->numerify('#####'),
        ]);
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_status_1' => $residenceStatus->value,
            'residence_number_1' => 'AB12345678CD',
            'residence_expiry_date_1' => now()->addYears(2)->toDateString(),
            'student_card_expiry_date' => '2027-03-31',
        ]);

        $paper = UserPaper::factory()->create(['user_id' => $user->id]);

        $approvedTypes = [
            UserPaperType::ForeignerCardFront,
            UserPaperType::ForeignerCardBack,
            UserPaperType::Passport,
            UserPaperType::PortraitImage,
        ];
        if ($residenceStatus === ResidenceStatus::StudyAbroad) {
            $approvedTypes[] = UserPaperType::StudentCardFront;
            $approvedTypes[] = UserPaperType::StudentCardBack;
        }

        foreach ($approvedTypes as $type) {
            UserPaperItem::factory()->create([
                'user_paper_id' => $paper->id,
                'type' => $type->value,
                'approved_at' => now()->subMonth(),
                'rejected_at' => null,
                'deleted_at' => null,
                'folder' => UserPaperItemFolder::Current->value,
            ]);
        }

        return $user->fresh(['info', 'paper']);
    }

    private function makeEmployeeWhoseWorkPermitWasDeleted(): User
    {
        $user = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->deleteItems($user, $this->activeItemIds($user, UserPaperType::WorkPermitStamp));

        return $user->refresh();
    }

    private function makeEmployeeWhoseRenewalWasDeleted(): User
    {
        $user = $this->makeEmployee(UserPaperStatus::Pending, ResidenceStatus::StudyAbroad);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->approveEveryPendingItem($user);
        $previouslyApprovedStampIds = $this->activeItemIds($user, UserPaperType::WorkPermitStamp);

        $this->travel(1)->minute();
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);
        $this->deleteItems(
            $user,
            array_values(
                array_diff($this->activeItemIds($user, UserPaperType::WorkPermitStamp), $previouslyApprovedStampIds)
            )
        );
        $this->assertSame($previouslyApprovedStampIds, $this->activeItemIds($user, UserPaperType::WorkPermitStamp));
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $user->refresh()->paper_status);

        return $user;
    }

    /**
     * @return Collection<int, RepairEmptiedDocumentStepsJob>
     */
    private function queueDeployRepair(): Collection
    {
        Queue::fake();

        $migration = require database_path('migrations/2026_11_05_000001_repair_emptied_document_steps.php');
        $migration->up();

        return Queue::pushed(RepairEmptiedDocumentStepsJob::class);
    }

    private function runDeployRepair(): void
    {
        $this->queueDeployRepair()->each(
            fn(RepairEmptiedDocumentStepsJob $job) => $job->handle(app(ReopenEmptiedDocumentStepsAction::class))
        );
    }

    /**
     * @param int[] $failingUserIds
     */
    private function failNotificationsFor(array $failingUserIds): void
    {
        $this->app->instance(
            NotifyDocumentsRequiredAction::class,
            new class ($failingUserIds) extends NotifyDocumentsRequiredAction {
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

    private function uploadStep(User $user, UserPaperStepKey $step, int $filesPerType = 1): void
    {
        $this->uploadStepExpecting($user, $step, 200, $filesPerType);
    }

    private function uploadStepExpecting(User $user, UserPaperStepKey $step, int $status, int $filesPerType = 1): void
    {
        $files = [];
        foreach ($step->requiredTypes() as $type) {
            $files[$type] = $step->allowsMultipleFilesPerType()
                ? array_map(
                    fn(int $index) => UploadedFile::fake()->image("{$type}_{$index}.jpg"),
                    range(1, $filesPerType)
                )
                : UploadedFile::fake()->image("{$type}.jpg");
        }

        $this->actingAs($user->refresh(), 'api')
            ->postJson("/user/document-steps/{$step->value}/upload", ['files' => $files])
            ->assertStatus($status);
    }

    /**
     * @param int[] $ids
     */
    private function deleteItems(User $user, array $ids): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/delete", ['ids' => $ids])
            ->assertStatus(200);
    }

    private function reopen(User $user): TestResponse
    {
        return $this->actingAs($this->admin, 'api')->postJson("/admin/users/{$user->id}/papers/reopen-emptied-steps");
    }

    /**
     * @return array<string, mixed>
     */
    private function adminPapers(User $user): array
    {
        return $this->actingAs($this->admin, 'api')
            ->getJson("/admin/users/{$user->id}/papers")
            ->assertStatus(200)
            ->json('data');
    }

    /**
     * @return int[]
     */
    private function activeItemIds(User $user, UserPaperType $type): array
    {
        return UserPaperItem::query()
            ->where('user_paper_id', $user->paper->id)
            ->where('type', $type->value)
            ->whereNull('deleted_at')
            ->whereNull('rejected_at')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function overviewStep(User $user, UserPaperStepKey $step): array
    {
        $overview = $this->actingAs($user->refresh(), 'api')->getJson('/user/document-steps')->assertStatus(200);

        return collect($overview->json('data.steps'))->firstWhere('key', $step->value);
    }

    private function approveEveryPendingItem(User $user): void
    {
        $itemIds = UserPaperItem::query()
            ->where('user_paper_id', $user->paper->id)
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->whereNull('deleted_at')
            ->pluck('id');

        $this->actingAs($this->admin, 'api')
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
