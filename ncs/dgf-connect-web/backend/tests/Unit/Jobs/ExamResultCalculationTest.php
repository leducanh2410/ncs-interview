<?php

namespace Tests\Unit\Jobs;

use App\Actions\Common\Exam\RecordRecurringExamCompletionAction;
use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
use App\Enums\Locale;
use App\Enums\UserPaperStatus;
use App\Jobs\ExamResultCalculation;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Question;
use App\Models\User;
use App\Services\User\ExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExamResultCalculationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The concrete reason `PendingExternal` was introduced instead of leaving
     * external_form_prep submissions at `InProgress`: this job sweeps every
     * `InProgress` row past `expires_at` and force-grades it by score. A
     * `pending_external` row must never match its query, regardless of how
     * long ago `expires_at` passed.
     */
    public function testDoesNotTouchPendingExternalResults(): void
    {
        $exam = $this->makeExam(['type' => ExamType::ExternalFormPrep->value, 'min_score' => 0]);
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $result = ExamResult::create([
            'user_id' => $user->id,
            'exam_id' => $exam->id,
            'score' => 0,
            'total_questions' => 1,
            'correct_answers' => 0,
            'started_at' => now()->subDay(),
            'expires_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
            'status' => ExamResultStatus::PendingExternal,
        ]);

        $this->runJob();

        $result->refresh();
        $this->assertEquals(ExamResultStatus::PendingExternal->value, $result->status);
        $this->assertNull($result->external_completed_at);
    }

    /**
     * external_form_prep forces min_score = 0, so a naive `$correctCount >=
     * min_score` check would always be true. An abandoned in_progress row
     * (user started but never submitted/confirmed the external form) must
     * expire as Failed, not silently Completed.
     */
    public function testFailsAbandonedExternalFormPrepSessionsPastExpiry(): void
    {
        $exam = $this->makeExam(['type' => ExamType::ExternalFormPrep->value, 'min_score' => 0]);
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $result = ExamResult::create([
            'user_id' => $user->id,
            'exam_id' => $exam->id,
            'score' => 0,
            'total_questions' => 0,
            'correct_answers' => 0,
            'started_at' => now()->subDay(),
            'expires_at' => now()->subDay(),
            'status' => ExamResultStatus::InProgress,
        ]);

        $this->runJob();

        $result->refresh();
        $this->assertEquals(ExamResultStatus::Failed->value, $result->status);
        $this->assertNotNull($result->completed_at);
    }

    public function testStillFinalizesAbandonedAssessmentSessionsPastExpiry(): void
    {
        $exam = $this->makeExam(['min_score' => 1]);
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $question = Question::create(['exam_id' => $exam->id]);
        $question->syncTranslations(array_fill_keys(Locale::values(), 'Q1?'));
        $correct = $question->options()->create(['is_correct' => true]);
        $correct->syncTranslations(array_fill_keys(Locale::values(), 'A'));

        $result = ExamResult::create([
            'user_id' => $user->id,
            'exam_id' => $exam->id,
            'score' => 0,
            'total_questions' => 1,
            'correct_answers' => 0,
            'started_at' => now()->subDay(),
            'expires_at' => now()->subDay(),
            'status' => ExamResultStatus::InProgress,
        ]);

        $this->runJob();

        $result->refresh();
        $this->assertEquals(ExamResultStatus::Failed->value, $result->status);
        $this->assertNotNull($result->completed_at);
    }

    /**
     * A concurrent transition (a submission, an admin override) can land
     * between the job's SELECT and its per-row UPDATE. This must not be a
     * check-then-act race: a row that moves off `in_progress` in that
     * window has to be left untouched, not overwritten back to
     * Failed/Completed.
     *
     * Simulated by hooking `ExamResult::retrieved`, which fires the moment
     * the job's initial query hydrates the row - i.e. exactly between its
     * SELECT and the per-row conditional UPDATE - and flipping the row
     * directly in the database from there, the same way a concurrent
     * request would.
     */
    public function testDoesNotOverwriteResultThatTransitionsDuringJobRun(): void
    {
        $exam = $this->makeExam(['type' => ExamType::ExternalFormPrep->value, 'min_score' => 0]);
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $result = ExamResult::create([
            'user_id' => $user->id,
            'exam_id' => $exam->id,
            'score' => 0,
            'total_questions' => 1,
            'correct_answers' => 0,
            'started_at' => now()->subDay(),
            'expires_at' => now()->subDay(),
            'status' => ExamResultStatus::InProgress,
        ]);

        $completedAt = now();
        ExamResult::retrieved(function (ExamResult $retrieved) use ($result, $completedAt) {
            if ((int) $retrieved->id === $result->id) {
                DB::table('exam_results')
                    ->where('id', $result->id)
                    ->update([
                        'status' => ExamResultStatus::PendingExternal->value,
                        'completed_at' => $completedAt,
                    ]);
            }
        });

        $this->runJob();

        $result->refresh();
        $this->assertEquals(ExamResultStatus::PendingExternal->value, $result->status);
        $this->assertEquals($completedAt->format('Y-m-d H:i:s'), $result->completed_at);
    }

    private function makeExam(array $overrides = []): Exam
    {
        $exam = Exam::create(
            array_merge(
                [
                    'is_required' => true,
                    'retry_limit' => 0,
                    'min_score' => 1,
                    'time_limit' => 30,
                ],
                $overrides
            )
        );
        $exam->syncTranslations(array_fill_keys(Locale::values(), 'Exam'));

        return $exam;
    }

    private function runJob(): void
    {
        $job = new ExamResultCalculation();
        $job->handle(app(ExamService::class), app(RecordRecurringExamCompletionAction::class));
    }
}
