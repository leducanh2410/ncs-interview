<?php

namespace Tests\Feature\Api\User;

use App\Enums\ErrorCode;
use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
use App\Enums\Locale;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Question;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalFormPrepExamTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Exam $exam;

    private Question $question;

    private int $wrongOptionId;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workplace = $this->makeWorkplace();
        $this->user = $this->makeEmployee();

        $this->exam = $this->makeExternalFormPrepExam();
        $this->question = $this->makeQuestionWithOptions($this->exam);
        $this->wrongOptionId = $this->question->options()->where('is_correct', false)->first()->id;
    }

    /**
     * Full lifecycle, per the project's Test Design Rules: reached through
     * the real start -> submit -> complete-external endpoints, not by
     * inserting an ExamResult row directly in a pending/completed state.
     */
    public function testFullLifecycleFromStartToExternalCompletionRemovesExamFromPendingExams(): void
    {
        $before = $this->actingAs($this->user, 'api')->getJson('/auth/check');
        $before->assertStatus(200);
        $this->assertContains($this->exam->id, collect($before->json('data.pending_exams'))->pluck('id')->all());

        $startResponse = $this->actingAs($this->user, 'api')->postJson("/user/exams/{$this->exam->id}/start");
        $startResponse->assertStatus(200);
        $resultId = $startResponse->json('data.result.id');
        $this->assertNotNull($resultId);

        $submitResponse = $this->actingAs($this->user, 'api')->postJson("/user/exams/{$this->exam->id}/submit", [
            'answers' => [['question_id' => $this->question->id, 'option_id' => $this->wrongOptionId]],
            'is_final' => true,
        ]);
        $submitResponse->assertStatus(200);

        $result = ExamResult::find($resultId);
        $this->assertEquals(ExamResultStatus::PendingExternal->value, $result->status);
        $this->assertNotNull($result->completed_at);

        $duringPending = $this->actingAs($this->user, 'api')->getJson('/auth/check');
        $this->assertContains(
            $this->exam->id,
            collect($duringPending->json('data.pending_exams'))->pluck('id')->all(),
            'Exam must still be pending: submitted internally but external form not confirmed complete.'
        );

        $resultResponse = $this->actingAs($this->user, 'api')->getJson("/user/exams/{$this->exam->id}/result");
        $resultResponse->assertStatus(200);
        $this->assertNotNull($resultResponse->json('data.result.questions.0.correct_option_id'));
        $this->assertEquals(
            'https://docs.google.com/forms/d/e/example/viewform',
            $resultResponse->json('data.external.url')
        );
        $this->assertNotEmpty($resultResponse->json('data.external.complete_pattern'));

        $completeResponse = $this->actingAs($this->user, 'api')->postJson(
            "/user/exams/{$this->exam->id}/results/{$resultId}/complete-external"
        );
        $completeResponse->assertStatus(200);
        $this->assertEquals(ExamResultStatus::Completed->value, $completeResponse->json('data.status'));

        $result->refresh();
        $this->assertEquals(ExamResultStatus::Completed->value, $result->status);
        $this->assertNotNull($result->external_completed_at);

        $after = $this->actingAs($this->user, 'api')->getJson('/auth/check');
        $after->assertStatus(200)->assertJsonPath('data.pending_exams', []);
    }

    public function testCompleteExternalIsIdempotent(): void
    {
        $resultId = $this->startAndSubmit();

        $first = $this->actingAs($this->user, 'api')->postJson(
            "/user/exams/{$this->exam->id}/results/{$resultId}/complete-external"
        );
        $first->assertStatus(200);
        $completedAt = ExamResult::find($resultId)->external_completed_at;

        $second = $this->actingAs($this->user, 'api')->postJson(
            "/user/exams/{$this->exam->id}/results/{$resultId}/complete-external"
        );
        $second->assertStatus(200);

        $this->assertEquals($completedAt, ExamResult::find($resultId)->external_completed_at);
    }

    public function testCompleteExternalRejectsAnotherUsersResult(): void
    {
        $resultId = $this->startAndSubmit();

        // Same workplace as the owner, so the rejection can only come from the
        // ownership check and not from the exam's audience.
        $otherUser = $this->makeEmployee();

        $response = $this->actingAs($otherUser, 'api')->postJson(
            "/user/exams/{$this->exam->id}/results/{$resultId}/complete-external"
        );

        $response->assertStatus(403)->assertJsonPath('error', ErrorCode::Forbidden->value);
        $this->assertEquals(ExamResultStatus::PendingExternal->value, ExamResult::find($resultId)->status);
    }

    /**
     * Retry/re-submission: submitting multiple times before external
     * completion must not re-block on score or retry limit, unlike
     * assessment exams.
     */
    public function testRestartingAfterSubmissionIsNotBlockedByAlreadyPassedOrRetryLimit(): void
    {
        $this->startAndSubmit();

        $response = $this->actingAs($this->user, 'api')->postJson("/user/exams/{$this->exam->id}/start");

        $response->assertStatus(200);
        $this->assertNotEquals(ErrorCode::ExamAlreadyPassed->value, $response->json('error'));
    }

    /**
     * Re-review interacts with result selection: starting a new attempt
     * while a previous one is pending_external must not hide the submitted
     * attempt — the result endpoint keeps serving its answers and external
     * form link, and switches to the new attempt once that one is submitted.
     */
    public function testResultEndpointStillServesPendingAttemptAfterRestart(): void
    {
        $firstResultId = $this->startAndSubmit();

        $restart = $this->actingAs($this->user, 'api')->postJson("/user/exams/{$this->exam->id}/start");
        $restart->assertStatus(200);
        $newResultId = $restart->json('data.result.id');
        $this->assertNotEquals($firstResultId, $newResultId);

        $whileInProgress = $this->actingAs($this->user, 'api')->getJson("/user/exams/{$this->exam->id}/result");
        $whileInProgress->assertStatus(200);
        $this->assertEquals((string) $firstResultId, (string) $whileInProgress->json('data.result.id'));
        $this->assertEquals(
            'https://docs.google.com/forms/d/e/example/viewform',
            $whileInProgress->json('data.external.url')
        );

        $this->actingAs($this->user, 'api')->postJson("/user/exams/{$this->exam->id}/submit", [
            'answers' => [['question_id' => $this->question->id, 'option_id' => $this->wrongOptionId]],
            'is_final' => true,
        ]);

        $afterResubmit = $this->actingAs($this->user, 'api')->getJson("/user/exams/{$this->exam->id}/result");
        $afterResubmit->assertStatus(200);
        $this->assertEquals((string) $newResultId, (string) $afterResubmit->json('data.result.id'));
    }

    /**
     * The exam has exactly one external form, so confirming it must close
     * every pending_external attempt of this user+exam at once — otherwise
     * the superseded attempts stay pending forever and show up as phantom
     * stragglers in the admin exam-result list.
     */
    public function testCompleteExternalClosesAllPendingAttemptsForSameExam(): void
    {
        $firstResultId = $this->startAndSubmit();
        $secondResultId = $this->startAndSubmit();
        $this->assertNotEquals($firstResultId, $secondResultId);
        $this->assertEquals(ExamResultStatus::PendingExternal->value, ExamResult::find($firstResultId)->status);
        $this->assertEquals(ExamResultStatus::PendingExternal->value, ExamResult::find($secondResultId)->status);

        $response = $this->actingAs($this->user, 'api')->postJson(
            "/user/exams/{$this->exam->id}/results/{$secondResultId}/complete-external"
        );
        $response->assertStatus(200);

        foreach ([$firstResultId, $secondResultId] as $resultId) {
            $result = ExamResult::find($resultId);
            $this->assertEquals(ExamResultStatus::Completed->value, $result->status);
            $this->assertNotNull($result->external_completed_at);
        }
    }

    /**
     * created_at only has second precision, so a start right after a submit
     * produces two rows sharing a timestamp. The exam list must break the
     * tie by id (like every other exam endpoint) and report the newest
     * attempt as the current result.
     */
    public function testExamListPicksNewestResultWhenCreatedAtTies(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->startAndSubmit();
        $restart = $this->actingAs($this->user, 'api')->postJson("/user/exams/{$this->exam->id}/start");
        $restart->assertStatus(200);
        $newResultId = $restart->json('data.result.id');

        $response = $this->actingAs($this->user, 'api')->getJson('/user/exams');

        $response->assertStatus(200);
        $exam = collect($response->json('data.data'))->firstWhere('id', $this->exam->id);
        $this->assertNotNull($exam);
        $this->assertEquals((string) $newResultId, (string) $exam['result']['id']);
    }

    private function startAndSubmit(): int
    {
        $startResponse = $this->actingAs($this->user, 'api')->postJson("/user/exams/{$this->exam->id}/start");
        $resultId = $startResponse->json('data.result.id');

        $this->actingAs($this->user, 'api')->postJson("/user/exams/{$this->exam->id}/submit", [
            'answers' => [['question_id' => $this->question->id, 'option_id' => $this->wrongOptionId]],
            'is_final' => true,
        ]);

        return $resultId;
    }

    private function langPayload(string $value): array
    {
        return array_fill_keys(Locale::values(), $value);
    }

    /**
     * Employees are attached to a workplace because an employee without one
     * belongs to no exam's audience and is denied every exam endpoint.
     */
    private function makeEmployee(): User
    {
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
        ]);
        $user->addRole(Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']));
        $user->workplaces()->attach($this->workplace->id);

        return $user;
    }

    private function makeWorkplace(): Workplace
    {
        return Workplace::create([
            'name' => 'Tokyo',
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);
    }

    private function makeExternalFormPrepExam(array $overrides = []): Exam
    {
        $exam = Exam::create(
            array_merge(
                [
                    'type' => ExamType::ExternalFormPrep->value,
                    'is_required' => true,
                    'retry_limit' => 0,
                    'min_score' => 0,
                    'time_limit' => 120,
                    'start_at' => null,
                    'end_at' => null,
                ],
                $overrides
            )
        );
        $exam->syncTranslations($this->langPayload('External form prep exam'));
        $exam->syncTranslations(
            $this->langPayload('https://docs.google.com/forms/d/e/example/viewform'),
            'external_url'
        );

        return $exam;
    }

    private function makeQuestionWithOptions(Exam $exam): Question
    {
        $question = Question::create(['exam_id' => $exam->id]);
        $question->syncTranslations($this->langPayload('Q1?'));
        $correct = $question->options()->create(['is_correct' => true]);
        $correct->syncTranslations($this->langPayload('A'));
        $wrong = $question->options()->create(['is_correct' => false]);
        $wrong->syncTranslations($this->langPayload('B'));

        return $question;
    }
}
