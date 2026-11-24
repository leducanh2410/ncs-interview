<?php

namespace Tests\Unit\Jobs;

use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Enums\Locale;
use App\Enums\UserPaperStatus;
use App\Jobs\NotifyRequiredExamActivationJob;
use App\Models\Exam;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\Workplace;
use App\Services\ExamNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Mockery;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

class NotifyRequiredExamActivationJobTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    public function testActivatesAndMarksExamAsNotified(): void
    {
        $exam = $this->makeExam();
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        // Even for an exam that targets no workplace, only employees who
        // belong to one are recipients.
        $user->workplaces()->attach(
            Workplace::create([
                'name' => 'Tokyo',
                'address' => '1-1-1',
                'latitude' => '35.0',
                'longitude' => '139.0',
            ])->id
        );
        $this->giveExamShifts($user, $user->workplaces()->pluck('workplaces.id'));
        UserDevice::create([
            'user_id' => $user->id,
            'device_id' => 'device-' . $user->id,
            'name' => 'iPhone',
            'fcm_token' => 'token-1',
            'platform' => 'ios',
        ]);
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->with(Mockery::type(CloudMessage::class), ['token-1'])
            ->andReturn(MulticastSendReport::withItems([]));

        $this->runJob($messaging);

        $this->assertNotNull($exam->fresh()->notified_at);
    }

    public function testSkipsNonRequiredExams(): void
    {
        $exam = $this->makeExam(['is_required' => false]);

        $this->runJob();

        $this->assertNull($exam->fresh()->notified_at);
    }

    public function testSkipsExamsAlreadyNotified(): void
    {
        $previouslyNotified = now()->subHour()->format('Y-m-d H:i:s');
        $exam = $this->makeExam(['notified_at' => $previouslyNotified]);

        $this->runJob();

        $this->assertEquals($previouslyNotified, $exam->fresh()->notified_at?->format('Y-m-d H:i:s'));
    }

    public function testSkipsExamsWithFutureStartAt(): void
    {
        $exam = $this->makeExam(['start_at' => now()->addHour()->format('Y-m-d H:i:s')]);

        $this->runJob();

        $this->assertNull($exam->fresh()->notified_at);
    }

    public function testProcessesExamWithNullStartAt(): void
    {
        $exam = $this->makeExam(['start_at' => null]);

        $this->runJob();

        $this->assertNotNull($exam->fresh()->notified_at);
    }

    public function testMarksAsNotifiedEvenWhenNoRecipients(): void
    {
        $exam = $this->makeExam();
        // Targeted to a specific workplace, no users assigned.
        $workplace = Workplace::create([
            'name' => 'Tokyo',
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);
        $exam->workplaces()->attach($workplace->id);

        $this->runJob();

        $this->assertNotNull($exam->fresh()->notified_at);
    }

    public function testSecondRunDuringInFlightSendDoesNotResend(): void
    {
        $exam = $this->makeExam();

        // Simulate the production incident: a second scheduler tick fires while
        // the first run is still fanning out FCM sends. The exam is claimed
        // (notified_at set) before sending, so the interleaved run must find
        // nothing to notify.
        $service = Mockery::mock(ExamNotificationService::class);
        $service->shouldReceive('getRecipientsForExam')->once()->andReturn(collect());
        $service
            ->shouldReceive('sendActivationNotification')
            ->once()
            ->andReturnUsing(function () use ($exam) {
                $this->assertNotNull($exam->fresh()->notified_at, 'Exam must be claimed before the send starts');

                $interleavedService = Mockery::mock(ExamNotificationService::class);
                $interleavedService->shouldNotReceive('getRecipientsForExam');
                $interleavedService->shouldNotReceive('sendActivationNotification');

                $interleavedJob = new NotifyRequiredExamActivationJob();
                $interleavedJob->handle($interleavedService, $this->firebaseResolver(null));

                return true;
            });

        $job = new NotifyRequiredExamActivationJob();
        $job->handle($service, $this->firebaseResolver(null));

        $this->assertNotNull($exam->fresh()->notified_at);
    }

    public function testUsesWithoutOverlappingJobMiddleware(): void
    {
        $middleware = (new NotifyRequiredExamActivationJob())->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    public function testDoesNotMarkAsNotifiedWhenSendFails(): void
    {
        $exam = $this->makeExam();
        $service = Mockery::mock(ExamNotificationService::class);
        $service
            ->shouldReceive('getRecipientsForExam')
            ->once()
            ->with(Mockery::on(fn(Exam $handledExam) => $handledExam->id === $exam->id))
            ->andReturn(collect());
        $service->shouldReceive('sendActivationNotification')->once()->andReturn(false);

        $job = new NotifyRequiredExamActivationJob();
        $job->handle($service, $this->firebaseResolver(Mockery::mock(Messaging::class)));

        $this->assertNull($exam->fresh()->notified_at);
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
                    'notified_at' => null,
                ],
                $overrides
            )
        );
        $exam->syncTranslations(array_fill_keys(Locale::values(), 'Required exam'));

        return $exam;
    }

    private function firebaseResolver(?Messaging $messaging): ResolveFirebaseMessagingAction
    {
        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->andReturn($messaging);

        return $resolver;
    }

    private function runJob(?Messaging $messaging = null): void
    {
        $job = new NotifyRequiredExamActivationJob();
        $job->handle(app(ExamNotificationService::class), $this->firebaseResolver($messaging));
    }
}
