<?php

namespace App\Jobs;

use App\Actions\Common\Exam\RecordRecurringExamCompletionAction;
use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
use App\Models\ExamResult;
use App\Models\Question;
use App\Services\User\ExamService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExamResultCalculation implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(
        ExamService $examService,
        RecordRecurringExamCompletionAction $recordRecurringExamCompletion
    ): void {
        $examResults = ExamResult::query()
            ->with(['questions', 'exam'])
            ->where('status', ExamResultStatus::InProgress)
            ->where('expires_at', '<', now())
            ->take(100)
            ->get();

        if ($examResults->isEmpty()) {
            return;
        }

        $examIds = $examResults->pluck('exam_id')->unique()->toArray();
        $questions = Question::query()->with('options')->whereIn('exam_id', $examIds)->get()->keyBy('id');

        /** @var ExamResult $result */
        foreach ($examResults as $result) {
            $correctCount = $examService->gradeExamResult($questions, $result);
            // external_form_prep is never graded by score (min_score is forced
            // to 0): a session abandoned before the external form is confirmed
            // must expire as Failed, never fall through to Completed.
            $status =
                $result->exam->type === ExamType::ExternalFormPrep
                    ? ExamResultStatus::Failed
                    : ($correctCount >= $result->exam->min_score
                        ? ExamResultStatus::Completed
                        : ExamResultStatus::Failed);

            // Conditional update, not check-then-act: SubmitExamAnswerAction
            // still accepts a submission up to 60s after `expires_at`, so a
            // valid submission (or an admin override) can land between the
            // query above and this write. Only a row still exactly
            // `in_progress` gets finalized here; one that already moved on
            // in that window simply matches zero rows and is left alone.
            $updated = DB::transaction(function () use (
                $result,
                $correctCount,
                $status,
                $recordRecurringExamCompletion
            ) {
                $updated = ExamResult::query()
                    ->where('id', $result->id)
                    ->where('status', ExamResultStatus::InProgress->value)
                    ->update([
                        'completed_at' => now(),
                        'score' => $correctCount,
                        'correct_answers' => $correctCount,
                        'status' => $status,
                    ]);

                if ($updated === 1 && $status === ExamResultStatus::Completed) {
                    $recordRecurringExamCompletion($result, $result->exam);
                }

                return $updated;
            });

            if ($updated === 0) {
                continue;
            }

            Log::info('Exam result calculation: Exam result updated', [
                'id' => $result->id,
                'correct_count' => $correctCount,
            ]);
        }
    }
}
