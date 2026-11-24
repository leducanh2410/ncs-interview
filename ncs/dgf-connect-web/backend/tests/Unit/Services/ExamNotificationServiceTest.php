<?php

namespace Tests\Unit\Services;

use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
use App\Enums\Locale;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\Workplace;
use App\Services\ExamNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Mockery;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

class ExamNotificationServiceTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    private ExamNotificationService $service;

    private ?Workplace $defaultWorkplace = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ExamNotificationService::class);
        $this->defaultWorkplace = null;
    }

    public function testRecipientsExcludeNonApprovedUsers(): void
    {
        $exam = $this->makeExam();
        $this->makeApprovedUser(UserPaperStatus::Pending->value);
        $approved = $this->makeApprovedUser();

        $recipients = $this->service->getRecipientsForExam($exam);

        $this->assertEquals([$approved->id], $recipients->pluck('id')->all());
    }

    public function testRecipientsRespectWorkplaceTargeting(): void
    {
        $wpA = $this->makeWorkplace('A');
        $wpB = $this->makeWorkplace('B');

        $exam = $this->makeExam();
        $exam->workplaces()->attach($wpA->id);

        $userInA = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $userInA->workplaces()->attach($wpA->id);
        $this->giveExamShifts($userInA, $userInA->workplaces()->pluck('workplaces.id'));

        $userInB = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $userInB->workplaces()->attach($wpB->id);
        $this->giveExamShifts($userInB, $userInB->workplaces()->pluck('workplaces.id'));

        $recipients = $this->service->getRecipientsForExam($exam);

        $this->assertEquals([$userInA->id], $recipients->pluck('id')->all());
    }

    /**
     * An exam with no workplace targeting still reaches everyone - but
     * "everyone" now means every employee who belongs to at least one
     * workplace. An employee in no workplace is not an exam participant
     * (only global admins have no workplace), and was already excluded from
     * the exam list before this change; this closes the same gap for
     * notifications.
     */
    public function testRecipientsIncludeAllApprovedMembersWhenExamHasNoWorkplace(): void
    {
        $exam = $this->makeExam();

        $u1 = $this->makeApprovedUser();
        $u2 = $this->makeApprovedUser();
        $workplaceless = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        $recipients = $this->service->getRecipientsForExam($exam);

        $this->assertEqualsCanonicalizing([$u1->id, $u2->id], $recipients->pluck('id')->all());
        $this->assertNotContains($workplaceless->id, $recipients->pluck('id')->all());
    }

    public function testRecipientsAreLimitedToNamedEmployeesWhenTheExamNamesAny(): void
    {
        $workplace = $this->makeWorkplace('A');

        $exam = $this->makeExam();
        $exam->workplaces()->attach($workplace->id);

        $named = $this->makeApprovedUser();
        $named->workplaces()->attach($workplace->id);
        $this->giveExamShifts($named, $named->workplaces()->pluck('workplaces.id'));
        $notNamed = $this->makeApprovedUser();
        $notNamed->workplaces()->attach($workplace->id);
        $this->giveExamShifts($notNamed, $notNamed->workplaces()->pluck('workplaces.id'));

        $exam->targetedUsers()->sync([$named->id]);

        $recipients = $this->service->getRecipientsForExam($exam->fresh());

        $this->assertEquals([$named->id], $recipients->pluck('id')->all());
    }

    public function testNamedEmployeeWhoLeftTheWorkplaceIsNotARecipient(): void
    {
        $workplace = $this->makeWorkplace('A');
        $otherWorkplace = $this->makeWorkplace('B');

        $exam = $this->makeExam();
        $exam->workplaces()->attach($workplace->id);

        $named = $this->makeApprovedUser();
        $named->workplaces()->attach($workplace->id);
        $this->giveExamShifts($named, $named->workplaces()->pluck('workplaces.id'));
        $exam->targetedUsers()->sync([$named->id]);

        $named->workplaces()->sync([$otherWorkplace->id]);
        $this->giveExamShifts($named, $named->workplaces()->pluck('workplaces.id'));

        $this->assertEmpty($this->service->getRecipientsForExam($exam->fresh())->all());
    }

    public function testRecipientsExcludeUsersWhoAlreadyPassed(): void
    {
        $exam = $this->makeExam(['min_score' => 50]);
        $passed = $this->makeApprovedUser();
        $failed = $this->makeApprovedUser();

        ExamResult::create([
            'user_id' => $passed->id,
            'exam_id' => $exam->id,
            'score' => 80,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subDay(),
            'expires_at' => now()->subDay()->addMinutes(30),
            'completed_at' => now()->subDay(),
            'status' => ExamResultStatus::Completed,
        ]);
        ExamResult::create([
            'user_id' => $failed->id,
            'exam_id' => $exam->id,
            'score' => 30,
            'total_questions' => 1,
            'correct_answers' => 0,
            'started_at' => now()->subDay(),
            'expires_at' => now()->subDay()->addMinutes(30),
            'completed_at' => now()->subDay(),
            'status' => ExamResultStatus::Completed,
        ]);

        $recipients = $this->service->getRecipientsForExam($exam);

        $this->assertEquals([$failed->id], $recipients->pluck('id')->all());
    }

    public function testSerializeExamShape(): void
    {
        $exam = $this->makeExam();

        $payload = $this->service->serializeExam($exam);

        $this->assertEquals($exam->id, $payload['id']);
        $this->assertEquals('Required exam', $payload['title']);
        $this->assertEquals(ExamType::Assessment->value, $payload['type']);
        $this->assertTrue($payload['is_required']);
        $this->assertEquals(50, $payload['min_score']);
        $this->assertEquals(30, $payload['time_limit']);
        $this->assertNotNull($payload['start_at']);
        $this->assertNull($payload['end_at']);
    }

    /**
     * The bug this regression-tests: with `min_score` defaulted to 0 for
     * external_form_prep, `score >= min_score` is true the instant a user
     * merely starts the exam (a fresh ExamResult also starts at score = 0).
     * Recipients must only be excluded once `status = Completed`.
     */
    public function testExternalFormPrepRecipientsNotExcludedByStartingAlone(): void
    {
        $exam = $this->makeExam([
            'type' => ExamType::ExternalFormPrep->value,
            'min_score' => 0,
        ]);
        $justStarted = $this->makeApprovedUser();
        ExamResult::create([
            'user_id' => $justStarted->id,
            'exam_id' => $exam->id,
            'score' => 0,
            'total_questions' => 1,
            'correct_answers' => 0,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'status' => ExamResultStatus::InProgress,
        ]);

        $recipients = $this->service->getRecipientsForExam($exam);

        $this->assertEquals([$justStarted->id], $recipients->pluck('id')->all());
    }

    public function testExternalFormPrepRecipientsExcludeOnlyOnceExternalStepCompleted(): void
    {
        $exam = $this->makeExam([
            'type' => ExamType::ExternalFormPrep->value,
            'min_score' => 0,
        ]);
        $pendingExternal = $this->makeApprovedUser();
        ExamResult::create([
            'user_id' => $pendingExternal->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(30),
            'completed_at' => now(),
            'status' => ExamResultStatus::PendingExternal,
        ]);
        $completed = $this->makeApprovedUser();
        ExamResult::create([
            'user_id' => $completed->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(30),
            'completed_at' => now(),
            'external_completed_at' => now(),
            'status' => ExamResultStatus::Completed,
        ]);

        $recipients = $this->service->getRecipientsForExam($exam);

        $this->assertEquals([$pendingExternal->id], $recipients->pluck('id')->all());
    }

    public function testSendActivationNotificationChunksTokensIntoFcmLimit(): void
    {
        $exam = $this->makeExam();
        $users = Collection::make(range(1, 501))->map(function (int $index) {
            $user = new User(['id' => $index]);
            $user->setRelation(
                'devices',
                Collection::make([
                    new UserDevice([
                        'user_id' => $index,
                        'fcm_token' => "token-{$index}",
                    ]),
                ])
            );

            return $user;
        });

        $batchSizes = [];
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->twice()
            ->with(
                Mockery::type(CloudMessage::class),
                Mockery::on(function (array $tokens) use (&$batchSizes) {
                    $batchSizes[] = count($tokens);

                    return count($tokens) <= 500;
                })
            )
            ->andReturn(MulticastSendReport::withItems([]));

        $sent = $this->service->sendActivationNotification($exam, $users, $messaging);

        $this->assertTrue($sent);
        $this->assertEquals([500, 1], $batchSizes);
    }

    public function testSendActivationNotificationBuildsSeparateLocalizedMessagePerRecipientLocale(): void
    {
        $exam = $this->makeExam();
        $exam->syncTranslations([
            'vi' => 'Bai thi VI',
            'en' => 'Exam EN',
            'ja' => 'Exam JA',
            'ne' => 'Exam NE',
            'si' => 'Exam SI',
        ]);

        $viUser = $this->makeUserWithDevice(1, 'vi', 'vi-token');
        $jaUser = $this->makeUserWithDevice(2, 'ja', 'ja-token');
        // No saved language preference: must fall back to the app's default
        // locale (APP_LOCALE=vi in testing) rather than being dropped.
        $noPrefUser = $this->makeUserWithDevice(3, null, 'default-token');

        $users = Collection::make([$viUser, $jaUser, $noPrefUser]);

        // viUser and noPrefUser both resolve to the 'vi' locale group, so
        // they're expected to share a single batched send; jaUser gets its
        // own localized send. Two locale groups => two sendMulticast calls.
        $sentBodiesByToken = [];
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->twice()
            ->with(Mockery::type(CloudMessage::class), Mockery::type('array'))
            ->andReturnUsing(function (CloudMessage $message, array $tokens) use (&$sentBodiesByToken) {
                $body = $message->jsonSerialize()['notification']['body'] ?? null;
                foreach ($tokens as $token) {
                    $sentBodiesByToken[$token] = $body;
                }

                return MulticastSendReport::withItems([]);
            });

        $sent = $this->service->sendActivationNotification($exam, $users, $messaging);

        $this->assertTrue($sent);
        $this->assertStringContainsString('Bai thi VI', $sentBodiesByToken['vi-token']);
        $this->assertStringContainsString('Exam JA', $sentBodiesByToken['ja-token']);
        $this->assertStringContainsString('Bai thi VI', $sentBodiesByToken['default-token']);
    }

    /**
     * Every exam participant belongs to at least one workplace - an employee
     * in no workplace is not in any exam's audience, so recipient fixtures
     * have to have one to be reachable at all.
     */
    private function makeApprovedUser(string $paperStatus = UserPaperStatus::Approved->value): User
    {
        $user = User::factory()->create(['paper_status' => $paperStatus]);
        $user->workplaces()->attach($this->defaultWorkplace()->id);
        $this->giveExamShifts($user, $user->workplaces()->pluck('workplaces.id'));

        return $user;
    }

    private function defaultWorkplace(): Workplace
    {
        return $this->defaultWorkplace ??= $this->makeWorkplace('Default');
    }

    private function makeUserWithDevice(int $id, ?string $lang, string $fcmToken): User
    {
        $user = new User([
            'id' => $id,
            'settings' => $lang ? ['lang' => $lang] : null,
        ]);
        $user->setRelation(
            'devices',
            Collection::make([
                new UserDevice([
                    'user_id' => $id,
                    'fcm_token' => $fcmToken,
                ]),
            ])
        );

        return $user;
    }

    private function makeExam(array $overrides = []): Exam
    {
        $exam = Exam::create(
            array_merge(
                [
                    'is_required' => true,
                    'retry_limit' => 0,
                    'min_score' => 50,
                    'time_limit' => 30,
                    'start_at' => now()->subMinute()->format('Y-m-d H:i:s'),
                    'end_at' => now()->addDay()->format('Y-m-d H:i:s'),
                ],
                $overrides
            )
        );
        $exam->syncTranslations(array_fill_keys(Locale::values(), 'Required exam'));

        return $exam;
    }

    private function makeWorkplace(string $name = 'WP'): Workplace
    {
        return Workplace::create([
            'name' => $name,
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);
    }
}
