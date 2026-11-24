<?php

namespace Tests\Feature\Api\User;

use App\Actions\Common\Exam\RecordRecurringExamCompletionAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Actions\Common\Recurring\RecordRecurringCompletionAction;
use App\Enums\ExamResultStatus;
use App\Enums\RecurringCompletableType;
use App\Jobs\ExamResultCalculation;
use App\Jobs\NotifyRequiredExamActivationJob;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\RecurringCompletion;
use App\Models\User;
use App\Models\Workplace;
use App\Services\ExamNotificationService;
use App\Services\User\ExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\Concerns\DrivesRecurringShiftFlow;
use Tests\TestCase;

class RecurringExamFlowTest extends TestCase
{
    use DrivesRecurringShiftFlow;
    use RefreshDatabase;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelToDate('2026-09-01 08:00:00');
        $this->setUpRecurringShiftFlow();

        $this->workplace = Workplace::factory()->create();
        $this->otherWorkplace = Workplace::factory()->create();
        $this->employee = $this->createRecurringMember($this->workplace);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testLifecycleFromFirstShiftToEleventhShift(): void
    {
        $exam = $this->recurringExam();
        $this->assertSame([], $this->pendingExamIds($this->employee));

        $first = $this->approveShift($this->employee, $this->workplace, '2026-09-01');
        $this->assertSame([$exam->id], $this->pendingExamIds($this->employee));

        $this->start($exam)->assertOk();
        $this->submit($exam, correct: false)->assertOk();
        $this->assertSame([$exam->id], $this->pendingExamIds($this->employee));

        $this->start($exam)->assertOk()->assertJsonPath('data.carried_over_count', 0);
        $this->submit($exam, correct: true)->assertOk();
        $this->assertSame([], $this->pendingExamIds($this->employee));
        $this->assertCompletion($exam, $this->workplace, $first->id, 1);
        $this->assertSame([1, 1], $this->results($exam)->pluck('occurrence')->all());

        $this->travelToDate('2026-09-01 19:00:00');
        $this->workShift($first);
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-02', 9);
        $this->assertSame([], $this->pendingExamIds($this->employee));
        $this->start($exam)->assertStatus(400);

        $this->travelToDate('2026-09-11 08:00:00');
        $eleventh = $this->approveShift($this->employee, $this->workplace, '2026-09-11');
        $this->assertSame([$exam->id], $this->pendingExamIds($this->employee));

        $this->actingAs($this->employee, 'api')
            ->getJson("/user/exams/{$exam->id}")
            ->assertOk()
            ->assertJsonPath('data.result', null)
            ->assertJsonPath('data.retry_count', 0);

        $this->start($exam)->assertOk()->assertJsonPath('data.carried_over_count', 0);
        $latest = $this->results($exam)->last();
        $this->assertSame(2, $latest->occurrence);
        $this->assertSame($this->workplace->id, $latest->workplace_id);

        $this->submit($exam, correct: true)->assertOk();
        $this->assertCompletion($exam, $this->workplace, $eleventh->id, 2);
        $this->assertSame([], $this->pendingExamIds($this->employee));
    }

    public function testListAndResultEndpointsFollowTheCurrentRound(): void
    {
        $exam = $this->recurringExam();
        $exam->update(['show_result_immediately' => true]);
        $first = $this->approveShift($this->employee, $this->workplace, '2026-09-01');
        $this->start($exam)->assertOk();
        $this->submit($exam, correct: false)->assertOk();
        $this->start($exam)->assertOk();
        $this->submit($exam, correct: true)->assertOk();
        $passed = $this->results($exam)->last();

        $listed = $this->listedExam($exam);
        $this->assertSame($passed->id, $listed['result']['id']);
        $this->assertSame(1, $listed['retry_count']);
        $this->actingAs($this->employee, 'api')
            ->getJson("/user/exams/{$exam->id}/result")
            ->assertOk()
            ->assertJsonPath('data.result.id', $passed->id);

        $this->travelToDate('2026-09-01 19:00:00');
        $this->workShift($first);
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-02', 9);
        $this->travelToDate('2026-09-11 08:00:00');
        $this->approveShift($this->employee, $this->workplace, '2026-09-11');

        $listed = $this->listedExam($exam);
        $this->assertNull($listed['result']);
        $this->assertSame(0, $listed['retry_count']);
        $this->actingAs($this->employee, 'api')
            ->getJson("/user/exams/{$exam->id}/result")
            ->assertNotFound();

        $this->start($exam)->assertOk();
        $this->submit($exam, correct: true)->assertOk();

        $listed = $this->listedExam($exam);
        $this->assertSame($this->results($exam)->last()->id, $listed['result']['id']);
        $this->assertSame(0, $listed['retry_count']);
    }

    public function testExamIsHiddenFromTheListWhileNotDueAndNeverCompleted(): void
    {
        $exam = $this->recurringExam();
        $this->approveShift($this->employee, $this->workplace, '2026-09-01');
        $this->travelToDate('2026-09-05 08:00:00');

        $this->assertSame([], $this->pendingExamIds($this->employee));
        $this->assertNotContains($exam->id, $this->listedExamIds());

        $this->approveShift($this->employee, $this->workplace, '2026-09-06');

        $this->assertContains($exam->id, $this->listedExamIds());
        $this->assertNull($this->listedExam($exam)['result']);
    }

    public function testAttemptInProgressWhenANewerTriggerArrivesCountsForTheNewestTrigger(): void
    {
        $exam = $this->recurringExam();
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-01', 10);
        $this->assertSame([$exam->id], $this->pendingExamIds($this->employee));
        $this->start($exam)->assertOk();

        $this->travelToDate('2026-09-10 19:10:00');
        $eleventh = $this->approveShift($this->employee, $this->workplace, '2026-09-11');

        $this->start($exam)->assertOk();
        $this->assertSame(1, $this->results($exam)->count());
        $this->submit($exam, correct: true)->assertOk();

        $this->assertCompletion($exam, $this->workplace, $eleventh->id, 1);
        $this->assertSame([], $this->pendingExamIds($this->employee));
    }

    public function testEachWorkplaceIsCompletedSeparately(): void
    {
        $employee = $this->createRecurringMember($this->workplace, $this->otherWorkplace);
        $exam = $this->recurringExam([$this->workplace, $this->otherWorkplace]);
        $this->approveShift($employee, $this->workplace, '2026-09-01');
        $this->approveShift($employee, $this->otherWorkplace, '2026-09-02');
        $this->assertSame([$exam->id], $this->pendingExamIds($employee));

        $this->start($exam, $employee)->assertOk();
        $this->submit($exam, correct: true, employee: $employee)->assertOk();

        $this->assertSame([$this->workplace->id], RecurringCompletion::query()->pluck('workplace_id')->all());
        $this->assertSame([$exam->id], $this->pendingExamIds($employee));

        $this->start($exam, $employee)->assertOk();
        $this->assertSame($this->otherWorkplace->id, $this->results($exam, $employee)->last()->workplace_id);
        $this->submit($exam, correct: true, employee: $employee)->assertOk();

        $this->assertSame([], $this->pendingExamIds($employee));
        $this->assertEqualsCanonicalizing(
            [$this->workplace->id, $this->otherWorkplace->id],
            RecurringCompletion::query()->pluck('workplace_id')->all()
        );
    }

    public function testInProgressAttemptKeepsItsWorkplaceWhenAnotherWorkplaceGetsAnEarlierTrigger(): void
    {
        $employee = $this->createRecurringMember($this->workplace, $this->otherWorkplace);
        $exam = $this->recurringExam([$this->workplace, $this->otherWorkplace]);
        $this->approveShift($employee, $this->workplace, '2026-09-10');
        $this->start($exam, $employee)->assertOk();
        $attempt = $this->results($exam, $employee)->last();

        $this->approveShift($employee, $this->otherWorkplace, '2026-09-05');
        $this->start($exam, $employee)->assertOk()->assertJsonPath('data.result.id', $attempt->id);

        $this->assertSame(1, $this->results($exam, $employee)->count());
        $this->assertSame($this->workplace->id, $attempt->fresh()->workplace_id);
    }

    public function testExpiryGradedPassRecordsTheCompletion(): void
    {
        $exam = $this->recurringExam();
        $shift = $this->approveShift($this->employee, $this->workplace, '2026-09-01');
        $this->start($exam)->assertOk();
        $this->submit($exam, correct: true, isFinal: false)->assertOk();

        $this->travelToDate('2026-09-01 10:00:00');
        $this->runCalculationJob();

        $this->assertSame(ExamResultStatus::Completed->value, $this->results($exam)->last()->status);
        $this->assertCompletion($exam, $this->workplace, $shift->id, 1);
        $this->assertSame([], $this->pendingExamIds($this->employee));
    }

    public function testRejectedTriggerDuringAnAttemptStillAcceptsTheSubmitButRecordsNothing(): void
    {
        $exam = $this->recurringExam();
        $shift = $this->approveShift($this->employee, $this->workplace, '2026-09-10');
        $this->start($exam)->assertOk();

        $this->rejectShift($shift);
        $this->assertSame('in_progress', $this->listedExam($exam)['result']['status']);
        $this->submit($exam, correct: true)->assertOk();

        $this->assertSame(ExamResultStatus::Completed->value, $this->results($exam)->last()->status);
        $this->assertSame(0, RecurringCompletion::query()->count());
        $this->assertSame([], $this->pendingExamIds($this->employee));
    }

    public function testPassWithoutATriggerIsNotReusedWhenTheNextTriggerArrives(): void
    {
        $exam = $this->recurringExam();
        $exam->update(['show_result_immediately' => true]);
        $shift = $this->approveShift($this->employee, $this->workplace, '2026-09-10');
        $this->start($exam)->assertOk();
        $this->rejectShift($shift);
        $this->submit($exam, correct: true)->assertOk();
        $orphanPass = $this->results($exam)->last();

        $trigger = $this->approveShift($this->employee, $this->workplace, '2026-09-12');

        $this->assertSame([$exam->id], $this->pendingExamIds($this->employee));
        $listed = $this->listedExam($exam);
        $this->assertNull($listed['result']);
        $this->assertFalse($listed['can_view_result']);
        $this->actingAs($this->employee, 'api')
            ->getJson("/user/exams/{$exam->id}/result")
            ->assertNotFound();

        $this->start($exam)->assertOk()->assertJsonPath('data.carried_over_count', 0);
        $attempt = $this->results($exam)->last();
        $this->assertNotSame($orphanPass->id, $attempt->id);
        $this->assertSame(1, $attempt->occurrence);

        $this->submit($exam, correct: true)->assertOk()->assertJsonPath('data.can_view_result', true);
        $this->assertCompletion($exam, $this->workplace, $trigger->id, 1);
        $this->assertSame([], $this->pendingExamIds($this->employee));
        $listed = $this->listedExam($exam);
        $this->assertSame($attempt->id, $listed['result']['id']);
        $this->assertSame(0, $listed['retry_count']);
    }

    public function testSubmitOnlyReportsAViewableResultThatTheResultEndpointServes(): void
    {
        $employee = $this->createRecurringMember($this->workplace, $this->otherWorkplace);
        $exam = $this->recurringExam([$this->workplace, $this->otherWorkplace]);
        $exam->update(['show_result_immediately' => true]);
        $this->approveShift($employee, $this->workplace, '2026-09-01');
        $this->approveShift($employee, $this->otherWorkplace, '2026-09-02');

        $this->start($exam, $employee)->assertOk();
        $this->submit($exam, correct: true, employee: $employee)
            ->assertOk()
            ->assertJsonPath('data.can_view_result', false);
        $this->actingAs($employee, 'api')
            ->getJson("/user/exams/{$exam->id}/result")
            ->assertNotFound();

        $this->start($exam, $employee)->assertOk();
        $this->submit($exam, correct: false, employee: $employee)
            ->assertOk()
            ->assertJsonPath('data.can_view_result', true);
        $failed = $this->results($exam, $employee)->last();
        $this->actingAs($employee, 'api')
            ->getJson("/user/exams/{$exam->id}/result")
            ->assertOk()
            ->assertJsonPath('data.result.id', $failed->id);

        $this->start($exam, $employee)->assertOk();
        $this->submit($exam, correct: true, employee: $employee)
            ->assertOk()
            ->assertJsonPath('data.can_view_result', true);
        $passed = $this->results($exam, $employee)->last();
        $this->assertSame($this->otherWorkplace->id, $passed->workplace_id);
        $this->actingAs($employee, 'api')
            ->getJson("/user/exams/{$exam->id}/result")
            ->assertOk()
            ->assertJsonPath('data.result.id', $passed->id);
    }

    public function testTurningRecurringOffAfterAFailedLaterRoundKeepsThePassedRound(): void
    {
        $exam = $this->recurringExam();
        $exam->update(['show_result_immediately' => true]);
        $first = $this->approveShift($this->employee, $this->workplace, '2026-09-01');
        $this->start($exam)->assertOk();
        $this->submit($exam, correct: true)->assertOk();
        $passed = $this->results($exam)->last();

        $this->travelToDate('2026-09-01 19:00:00');
        $this->workShift($first);
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-02', 9);
        $this->travelToDate('2026-09-11 08:00:00');
        $this->approveShift($this->employee, $this->workplace, '2026-09-11');
        $this->start($exam)->assertOk();
        $this->submit($exam, correct: false)->assertOk();
        $this->assertSame([1, 2], $this->results($exam)->pluck('occurrence')->all());

        $exam->update(['is_recurring' => false]);

        $this->assertSame([], $this->pendingExamIds($this->employee));
        $listed = $this->listedExam($exam);
        $this->assertSame($passed->id, $listed['result']['id']);
        $this->assertSame(ExamResultStatus::Completed->value, $listed['result']['status']);
        $this->actingAs($this->employee, 'api')
            ->getJson("/user/exams/{$exam->id}")
            ->assertOk()
            ->assertJsonPath('data.result.id', $passed->id);
        $this->actingAs($this->employee, 'api')
            ->getJson("/user/exams/{$exam->id}/result")
            ->assertOk()
            ->assertJsonPath('data.result.id', $passed->id);
        $this->start($exam)->assertStatus(400);
        $this->assertSame(2, $this->results($exam)->count());
    }

    public function testRecurringExamWithPastEndDateStaysPending(): void
    {
        $exam = $this->recurringExam();
        $exam->forceFill(['end_at' => '2026-08-01 00:00:00'])->save();
        $this->approveShift($this->employee, $this->workplace, '2026-09-01');

        $this->assertSame([$exam->id], $this->pendingExamIds($this->employee));
        $this->start($exam)->assertOk();
    }

    public function testLegacyPassingResultCountsAsTheFirstOccurrence(): void
    {
        $exam = $this->recurringExam(isRecurring: false);
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-01', 2);
        $this->start($exam)->assertOk();
        $this->submit($exam, correct: true)->assertOk();
        $exam->update(['is_recurring' => true]);

        $this->assertSame([], $this->pendingExamIds($this->employee));

        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-03', 9);
        $this->travelToDate('2026-09-12 08:00:00');
        $this->approveShift($this->employee, $this->workplace, '2026-09-12');
        $this->assertSame([$exam->id], $this->pendingExamIds($this->employee));

        $this->start($exam)->assertOk();
        $this->assertSame([1, 2], $this->results($exam)->pluck('occurrence')->all());
    }

    public function testAttemptStartedBeforeTheExamBecameRecurringIsBoundOnResume(): void
    {
        $exam = $this->recurringExam(isRecurring: false);
        $shift = $this->approveShift($this->employee, $this->workplace, '2026-09-01');
        $this->start($exam)->assertOk();
        $exam->update(['is_recurring' => true]);

        $this->start($exam)->assertOk();
        $attempt = $this->results($exam)->sole();
        $this->assertSame($this->workplace->id, $attempt->workplace_id);

        $this->submit($exam, correct: true)->assertOk();
        $this->assertCompletion($exam, $this->workplace, $shift->id, 1);
    }

    public function testOneTimeRequiredExamKeepsItsLifetimeSatisfaction(): void
    {
        $exam = $this->recurringExam(isRecurring: false);
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-01', 2);
        $this->start($exam)->assertOk();
        $this->submit($exam, correct: true)->assertOk();

        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-03', 12);

        $this->assertSame([], $this->pendingExamIds($this->employee));
        $this->start($exam)->assertStatus(400);
        $this->assertSame(0, RecurringCompletion::query()->count());
    }

    public function testFailedCompletionRecordRollsBackTheFinalSubmit(): void
    {
        $exam = $this->recurringExam();
        $this->approveShift($this->employee, $this->workplace, '2026-09-01');
        $this->start($exam)->assertOk();

        $this->mock(RecordRecurringCompletionAction::class)
            ->shouldReceive('__invoke')
            ->andThrow(new RuntimeException('completion store failed'));

        $this->withoutExceptionHandling();
        try {
            $this->submit($exam, correct: true);
            $this->fail('The submit should have thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('completion store failed', $exception->getMessage());
        }

        $this->assertSame(ExamResultStatus::InProgress->value, $this->results($exam)->last()->status);
        $this->assertSame(0, RecurringCompletion::query()->count());
    }

    public function testFailedCompletionRecordRollsBackTheExpiryGrading(): void
    {
        $exam = $this->recurringExam();
        $this->approveShift($this->employee, $this->workplace, '2026-09-01');
        $this->start($exam)->assertOk();
        $this->submit($exam, correct: true, isFinal: false)->assertOk();
        $this->travelToDate('2026-09-01 10:00:00');

        $this->mock(RecordRecurringCompletionAction::class)
            ->shouldReceive('__invoke')
            ->andThrow(new RuntimeException('completion store failed'));

        try {
            $this->runCalculationJob();
            $this->fail('The job should have thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('completion store failed', $exception->getMessage());
        }

        $this->assertSame(ExamResultStatus::InProgress->value, $this->results($exam)->last()->status);
        $this->assertSame(0, RecurringCompletion::query()->count());
    }

    public function testActivationPushIsSentOnlyOnce(): void
    {
        $exam = $this->recurringExam();
        $first = $this->approveShift($this->employee, $this->workplace, '2026-09-01');

        $service = Mockery::mock(ExamNotificationService::class);
        $service
            ->shouldReceive('getRecipientsForExam')
            ->once()
            ->andReturn(collect([$this->employee]));
        $service->shouldReceive('sendActivationNotification')->once()->andReturn(true);
        (new NotifyRequiredExamActivationJob())->handle($service, $this->firebaseResolver());
        $this->assertNotNull($exam->fresh()->notified_at);

        $this->start($exam)->assertOk();
        $this->submit($exam, correct: true)->assertOk();
        $this->travelToDate('2026-09-01 19:00:00');
        $this->workShift($first);
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-02', 9);
        $this->travelToDate('2026-09-11 08:00:00');
        $this->approveShift($this->employee, $this->workplace, '2026-09-11');
        $this->assertSame([$exam->id], $this->pendingExamIds($this->employee));

        $silentService = Mockery::mock(ExamNotificationService::class);
        $silentService->shouldNotReceive('getRecipientsForExam');
        $silentService->shouldNotReceive('sendActivationNotification');
        (new NotifyRequiredExamActivationJob())->handle($silentService, $this->firebaseResolver());
    }

    /**
     * @param list<Workplace>|null $workplaces
     */
    private function recurringExam(?array $workplaces = null, bool $isRecurring = true): Exam
    {
        $exam = Exam::create([
            'is_required' => true,
            'is_recurring' => $isRecurring,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 60,
            'languages' => ['en', 'vi', 'ja'],
        ]);
        $exam->syncTranslations(['en' => 'Exam', 'vi' => 'Bài thi', 'ja' => '試験']);
        $exam->workplaces()->attach(
            collect($workplaces ?? [$this->workplace])
                ->pluck('id')
                ->all()
        );

        $question = Question::create(['exam_id' => $exam->id]);
        $question->syncTranslations(['en' => 'Q', 'vi' => 'Câu', 'ja' => '問']);
        foreach ([true, false] as $isCorrect) {
            $option = $question->options()->create(['is_correct' => $isCorrect]);
            $option->syncTranslations(['en' => 'A', 'vi' => 'A', 'ja' => 'A']);
        }

        return $exam;
    }

    private function listedExam(Exam $exam): array
    {
        $response = $this->actingAs($this->employee, 'api')->getJson('/user/exams')->assertOk();
        $listed = collect($response->json('data.data'))->firstWhere('id', $exam->id);
        $this->assertNotNull($listed);

        return $listed;
    }

    /**
     * @return list<int>
     */
    private function listedExamIds(): array
    {
        return collect($this->actingAs($this->employee, 'api')->getJson('/user/exams')->assertOk()->json('data.data'))
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    private function start(Exam $exam, ?User $employee = null): TestResponse
    {
        return $this->actingAs($employee ?? $this->employee, 'api')->postJson("/user/exams/{$exam->id}/start");
    }

    private function submit(Exam $exam, bool $correct, bool $isFinal = true, ?User $employee = null): TestResponse
    {
        $question = $exam->questions()->firstOrFail();
        $option = QuestionOption::query()
            ->where('question_id', $question->id)
            ->where('is_correct', $correct)
            ->firstOrFail();

        return $this->actingAs($employee ?? $this->employee, 'api')->postJson("/user/exams/{$exam->id}/submit", [
            'answers' => [['question_id' => $question->id, 'option_id' => $option->id]],
            'is_final' => $isFinal,
        ]);
    }

    private function results(Exam $exam, ?User $employee = null)
    {
        return ExamResult::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', ($employee ?? $this->employee)->id)
            ->orderBy('id')
            ->get();
    }

    private function assertCompletion(Exam $exam, Workplace $workplace, int $triggerShiftUserId, int $occurrence): void
    {
        $completion = RecurringCompletion::query()
            ->where('completable_type', RecurringCompletableType::Exam->value)
            ->where('completable_id', $exam->id)
            ->where('workplace_id', $workplace->id)
            ->where('occurrence', $occurrence)
            ->firstOrFail();

        $this->assertSame($triggerShiftUserId, $completion->trigger_shift_user_id);
    }

    private function runCalculationJob(): void
    {
        (new ExamResultCalculation())->handle(app(ExamService::class), app(RecordRecurringExamCompletionAction::class));
    }

    private function firebaseResolver(): ResolveFirebaseMessagingAction
    {
        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->andReturn(null);

        return $resolver;
    }
}
