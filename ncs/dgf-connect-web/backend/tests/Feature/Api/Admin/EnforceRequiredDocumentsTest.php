<?php

namespace Tests\Feature\Api\Admin;

use App\Actions\Admin\User\NotifyDocumentsRequiredAction;
use App\Actions\Admin\User\RequestDocumentCompletionAction;
use App\Actions\Admin\User\ResolveOutstandingDocumentStepsAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Actions\User\Document\ResolveRequiredStepKeysAction;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Jobs\EnforceRequiredDocumentsJob;
use App\Jobs\PushNotificationToUser;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Models\UserPaperStep;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Contract\Messaging;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\Concerns\FakesDocumentOcr;
use Tests\Concerns\ResetsUserDocumentInfo;
use Tests\TestCase;

class EnforceRequiredDocumentsTest extends TestCase
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

    public function testAdminRequestNotifiesTheEmployeeWithTheMissingDocumentsInEveryLanguage(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser();

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$user->id}/papers/request-completion")
            ->assertStatus(200);

        $notification = $this->documentsRequiredNotificationsFor($user)->sole();
        $this->assertSame(NotificationStatus::PENDING->value, $notification->status);
        $this->assertSame('Cần bổ sung hồ sơ', $notification->title);
        $this->assertStringContainsString(
            'Thẻ sinh viên, Giấy phép hoạt động ngoài tư cách lưu trú',
            $notification->content
        );

        $translations = $notification->translations()->pluck('content', 'lang');
        $this->assertEqualsCanonicalizing(['en', 'ja', 'ne', 'si'], $translations->keys()->all());
        $this->assertStringContainsString('学生証、資格外活動許可', $translations['ja']);
        $this->assertStringContainsString(
            'Student card, Permission to engage in activity other than that permitted',
            $translations['en']
        );
    }

    public function testAStudentCardWithoutExpiryDateIsNamedAsSuch(): void
    {
        $user = $this->makeApprovedStudyAbroadUser(complete: true);
        $user->info->update(['student_card_expiry_date' => null]);

        $this->runEnforcement();

        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
        $notification = $this->documentsRequiredNotificationsFor($user)->sole();
        $this->assertStringContainsString('Thẻ sinh viên (thiếu ngày hết hạn)', $notification->content);
        $this->assertStringNotContainsString('Giấy phép', $notification->content);
    }

    public function testEnforcementSendsBackOnlyApprovedFilesThatOweDocuments(): void
    {
        $owesDocuments = $this->makeApprovedStudyAbroadUser();
        $complete = $this->makeApprovedStudyAbroadUser(complete: true);
        $awaitingReview = $this->makeApprovedStudyAbroadUser();
        $awaitingReview->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
        $managerWithoutFile = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        $this->runEnforcement();

        $this->assertSame(UserPaperStatus::Pending->value, $owesDocuments->refresh()->paper_status);
        $this->assertSame(UserPaperStatus::Approved->value, $complete->refresh()->paper_status);
        $this->assertSame(UserPaperStatus::WaitForApproval->value, $awaitingReview->refresh()->paper_status);
        $this->assertSame(UserPaperStatus::Approved->value, $managerWithoutFile->refresh()->paper_status);

        $this->assertSame(
            [$owesDocuments->id],
            Notification::query()
                ->where('type', NotificationType::PAPER_DOCUMENTS_REQUIRED->value)
                ->pluck('user_id')
                ->all()
        );
    }

    public function testOnlyFilesOwingDocumentsAreQueued(): void
    {
        $users = collect(range(1, 3))->map(fn() => $this->makeApprovedStudyAbroadUser());
        $this->makeApprovedStudyAbroadUser(complete: true);
        $webAdminRole = Role::query()->create(['name' => 'leader', 'display_name' => 'Leader']);
        $webAdminRole
            ->permissions()
            ->attach(
                Permission::query()->firstOrCreate(
                    ['name' => PermissionName::ManagerAuth->value],
                    ['display_name' => PermissionName::ManagerAuth->value]
                )
            );
        $this->makeApprovedStudyAbroadUser()->addRole($webAdminRole);

        $this->artisan('users:enforce-required-documents')->assertSuccessful();

        Queue::assertPushed(
            EnforceRequiredDocumentsJob::class,
            fn(EnforceRequiredDocumentsJob $job) => $job->userIds === $users->pluck('id')->all()
        );
        $this->assertSame(UserPaperStatus::Approved->value, $users->first()->refresh()->paper_status);
    }

    public function testTheDeployMigrationQueuesTheEnforcement(): void
    {
        $user = $this->makeApprovedStudyAbroadUser();

        $migration = require database_path(
            'migrations/2026_09_24_000001_enforce_required_documents_for_approved_users.php'
        );
        $migration->up();

        Queue::assertPushed(
            EnforceRequiredDocumentsJob::class,
            fn(EnforceRequiredDocumentsJob $job) => $job->userIds === [$user->id]
        );
    }

    public function testDryRunListsTheEmployeesAndChangesNothing(): void
    {
        $user = $this->makeApprovedStudyAbroadUser();

        $this->artisan('users:enforce-required-documents', ['--dry-run' => true])
            ->expectsOutputToContain('1 employees would be sent back to pending.')
            ->assertSuccessful();

        Queue::assertNotPushed(EnforceRequiredDocumentsJob::class);
        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
        $this->assertSame(0, $this->documentsRequiredNotificationsFor($user)->count());
    }

    public function testRunningEnforcementAgainDoesNotNotifyTwice(): void
    {
        $user = $this->makeApprovedStudyAbroadUser();

        $this->runEnforcement();
        $this->runEnforcement();

        $this->assertSame(1, $this->documentsRequiredNotificationsFor($user)->count());
    }

    public function testAFailureForOneEmployeeRollsBackTheirFileAndLeavesTheRestProcessed(): void
    {
        $failing = $this->makeApprovedStudyAbroadUser();
        $succeeding = $this->makeApprovedStudyAbroadUser();

        $this->app->bind(NotifyDocumentsRequiredAction::class, function () use ($failing) {
            return new class ($failing->id) extends NotifyDocumentsRequiredAction {
                public function __construct(private readonly int $failingUserId) {}

                public function __invoke(User $user, array $missingSteps, bool $studentCardExpiryMissing): Notification
                {
                    if ($user->id === $this->failingUserId) {
                        throw new RuntimeException('notification store unavailable');
                    }

                    return parent::__invoke($user, $missingSteps, $studentCardExpiryMissing);
                }
            };
        });

        $jobs = $this->queueEnforcement();
        foreach ($jobs as $job) {
            try {
                $job->handle(app(RequestDocumentCompletionAction::class));
                $this->fail('The job must fail so the queue retries it');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString((string) $failing->id, $e->getMessage());
            }
        }

        $this->assertSame(UserPaperStatus::Approved->value, $failing->refresh()->paper_status);
        $this->assertEqualsCanonicalizing(
            array_map(
                fn(UserPaperStepKey $key) => $key->value,
                app(ResolveRequiredStepKeysAction::class)(ResidenceStatus::StudyAbroad->value)
            ),
            $failing->paper()->firstOrFail()->approved_step_keys
        );
        $this->assertSame(0, $this->documentsRequiredNotificationsFor($failing)->count());

        $this->assertSame(UserPaperStatus::Pending->value, $succeeding->refresh()->paper_status);
        $this->assertSame(1, $this->documentsRequiredNotificationsFor($succeeding)->count());
    }

    public function testRetryingAFailedJobSendsBackTheRemainingEmployeeWithoutNotifyingTheOthersAgain(): void
    {
        $failing = $this->makeApprovedStudyAbroadUser();
        $succeeding = $this->makeApprovedStudyAbroadUser();

        $this->app->bind(NotifyDocumentsRequiredAction::class, function () use ($failing) {
            return new class ($failing->id) extends NotifyDocumentsRequiredAction {
                public function __construct(private readonly int $failingUserId) {}

                public function __invoke(User $user, array $missingSteps, bool $studentCardExpiryMissing): Notification
                {
                    if ($user->id === $this->failingUserId) {
                        throw new RuntimeException('notification store unavailable');
                    }

                    return parent::__invoke($user, $missingSteps, $studentCardExpiryMissing);
                }
            };
        });

        $jobs = $this->queueEnforcement();
        foreach ($jobs as $job) {
            try {
                $job->handle(app(RequestDocumentCompletionAction::class));
            } catch (RuntimeException) {
            }
        }

        $this->app->offsetUnset(NotifyDocumentsRequiredAction::class);

        foreach ($jobs as $job) {
            $job->handle(app(RequestDocumentCompletionAction::class));
        }

        $this->assertSame(UserPaperStatus::Pending->value, $failing->refresh()->paper_status);
        $this->assertSame(1, $this->documentsRequiredNotificationsFor($failing)->count());
        $this->assertSame(UserPaperStatus::Pending->value, $succeeding->refresh()->paper_status);
        $this->assertSame(1, $this->documentsRequiredNotificationsFor($succeeding)->count());
    }

    public function testTheJobRetriesABoundedNumberOfTimes(): void
    {
        $job = new EnforceRequiredDocumentsJob([1]);

        $this->assertSame(3, $job->tries);
        $this->assertNotEmpty($job->backoff());
    }

    public function testTheRequestStaysInTheInboxWhenThePushCannotBeDelivered(): void
    {
        $withoutDevice = $this->makeApprovedStudyAbroadUser();
        $withBrokenDevice = $this->makeApprovedStudyAbroadUser();
        UserDevice::query()->create([
            'user_id' => $withBrokenDevice->id,
            'device_id' => 'device-1',
            'name' => 'Primary device',
            'platform' => 'android',
            'fcm_token' => 'expired-token',
        ]);

        $this->runEnforcement();

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->andThrow(new RuntimeException('token expired'));
        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->andReturn($messaging);

        (new PushNotificationToUser())->handle($resolver);

        foreach ([$withoutDevice, $withBrokenDevice] as $user) {
            $notification = $this->documentsRequiredNotificationsFor($user)->sole();
            $this->assertSame(NotificationStatus::SENT->value, $notification->status);

            $this->actingAs($user->refresh(), 'api')
                ->getJson('/user/notifications')
                ->assertOk()
                ->assertJsonFragment(['id' => $notification->id]);
        }
    }

    public function testAStudentCardReUploadedButNotYetConfirmedIsStillOutstanding(): void
    {
        $user = $this->makeApprovedStudyAbroadUser(complete: true);
        $user->info->update(['student_card_expiry_date' => null]);

        $this->runEnforcement();
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);
        $this->assertSame([UserPaperStepKey::StudentCard->value], $this->outstandingStepsFor($user));

        $step = $this->uploadStep($user, UserPaperStepKey::StudentCard);
        $this->assertSame(UserPaperStepStatus::OcrProcessing->value, $step->status);
        $this->assertSame([UserPaperStepKey::StudentCard->value], $this->outstandingStepsFor($user));

        $this->runQueuedOcrFor($step);
        $this->assertSame(UserPaperStepStatus::OcrReady->value, $step->refresh()->status);
        $this->assertSame([UserPaperStepKey::StudentCard->value], $this->outstandingStepsFor($user));
    }

    public function testAnEmployeeWhoFilesTheRequestedDocumentsIsLeftAloneByTheNextRun(): void
    {
        $admin = $this->createAdminWithPermissions(PermissionName::UserPaperApprove);
        $user = $this->makeApprovedStudyAbroadUser();

        $this->runEnforcement();
        $this->assertSame(UserPaperStatus::Pending->value, $user->refresh()->paper_status);

        $studentStep = $this->uploadAndRunOcr($user, UserPaperStepKey::StudentCard);
        $this->uploadStep($user, UserPaperStepKey::WorkPermit);

        $this->actingAs($user->refresh(), 'api')
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

        $this->runEnforcement();

        $this->assertSame(UserPaperStatus::Approved->value, $user->refresh()->paper_status);
        $this->assertSame(1, $this->documentsRequiredNotificationsFor($user)->count());
    }

    private function runEnforcement(): void
    {
        $this->queueEnforcement()->each(
            fn(EnforceRequiredDocumentsJob $job) => $job->handle(app(RequestDocumentCompletionAction::class))
        );
    }

    /**
     * @return SupportCollection<int, EnforceRequiredDocumentsJob>
     */
    private function queueEnforcement(): SupportCollection
    {
        Queue::fake();

        $this->artisan('users:enforce-required-documents')->assertSuccessful();

        return Queue::pushed(EnforceRequiredDocumentsJob::class);
    }

    /**
     * @return string[]
     */
    private function outstandingStepsFor(User $user): array
    {
        $user = $user->fresh(['info', 'paper']);

        return app(ResolveOutstandingDocumentStepsAction::class)->forUsers(collect([$user]))[$user->id] ?? [];
    }

    /**
     * @return Collection<int, Notification>
     */
    private function documentsRequiredNotificationsFor(User $user): Collection
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('type', NotificationType::PAPER_DOCUMENTS_REQUIRED->value)
            ->get();
    }

    private function makeApprovedStudyAbroadUser(bool $complete = false): User
    {
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'SN-' . fake()->unique()->numerify('#####'),
        ]);
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_1' => 'AB' . fake()->unique()->numerify('########') . 'CD',
            'residence_expiry_date_1' => now()->addYears(2)->toDateString(),
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

    private function createAdminWithPermissions(PermissionName ...$permissionNames): User
    {
        $admin = User::factory()->create();
        foreach ($permissionNames as $permissionName) {
            $admin
                ->permissions()
                ->attach(
                    Permission::query()->firstOrCreate(
                        ['name' => $permissionName->value],
                        ['display_name' => $permissionName->value]
                    )
                );
        }

        return $admin;
    }
}
