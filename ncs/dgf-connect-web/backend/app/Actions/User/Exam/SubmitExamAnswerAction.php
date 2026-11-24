<?php

namespace App\Actions\User\Exam;

use App\Actions\ActionResult;
use App\Actions\Common\Exam\RecordRecurringExamCompletionAction;
use App\Enums\ErrorCode;
use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\ExamResultQuestion;
use App\Services\User\ExamService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SubmitExamAnswerAction
{
    private ExamService $examService;

    public function __construct(
        ExamService $examService,
        private readonly RecordRecurringExamCompletionAction $recordRecurringExamCompletion
    ) {
        $this->examService = $examService;
    }

    public function __invoke(Exam $exam, array $answers, bool $isFinal = false): ActionResult
    {
        if (!$this->examService->canUserViewExam(Auth::id(), $exam)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $exam->load([
            'results' => function ($query) {
                $query->where('exam_results.user_id', Auth::id());
            },
            'results.questions',
            'documents.attachments',
        ]);

        if ($exam->is_recurring) {
            $exam->result = $exam->results
                ->where('status', ExamResultStatus::InProgress->value)
                ->sortBy([['created_at', 'desc'], ['id', 'desc']])
                ->first();
        } else {
            $exam = $this->examService->setCustomFieldForExam($exam);
        }

        if (!$this->examService->isExamActive($exam)) {
            return new ActionResult(error: ErrorCode::ExamTimeOutOfRange, status: 400);
        }

        if (!$exam->result || $exam->result->completed_at) {
            return new ActionResult(error: ErrorCode::ExamSubmitFailedNoSessionFound, status: 400);
        }

        $now = now()->subMinute()->format('Y-m-d H:i:s');
        if ($now > $exam->result->expires_at) {
            return new ActionResult(error: ErrorCode::ExamSubmitFailedSessionExpired, status: 400);
        }

        $exam->load('questions.options');
        $questions = $exam->questions->keyBy('id');

        $carriedQuestionIds = $exam->result->questions->where('is_carried_over', true)->pluck('question_id');

        DB::transaction(function () use ($exam, $answers, $questions, $isFinal, $carriedQuestionIds) {
            $rows = [];
            foreach ($answers as $answer) {
                if (!$questions->has($answer['question_id']) || $carriedQuestionIds->contains($answer['question_id'])) {
                    continue;
                }
                $question = $questions[$answer['question_id']];
                $option = $question->options->firstWhere('id', $answer['option_id']);
                if (!$option) {
                    continue;
                }
                $rows[$question->id] = [
                    'exam_result_id' => $exam->result->id,
                    'question_id' => $question->id,
                    'question_option_id' => $option->id,
                    'is_correct' => $option->is_correct,
                ];
            }

            if ($rows) {
                ExamResultQuestion::upsert(
                    array_values($rows),
                    ['exam_result_id', 'question_id'],
                    ['question_option_id', 'is_correct']
                );
            }

            if ($isFinal) {
                $exam->result->load('questions');
                $correct = $this->examService->gradeExamResult($questions, $exam->result);
                // external_form_prep: score is informational only — the exam
                // is submitted (and answers revealed) regardless of score,
                // never gated to Failed. Real completion is decided later by
                // CompleteExternalExamStepAction, not here.
                $status =
                    $exam->type === ExamType::ExternalFormPrep
                        ? ExamResultStatus::PendingExternal
                        : ($correct >= $exam->min_score
                            ? ExamResultStatus::Completed
                            : ExamResultStatus::Failed);
                $exam->result->update([
                    'completed_at' => now(),
                    'score' => $correct,
                    'correct_answers' => $correct,
                    'status' => $status,
                ]);

                if ($status === ExamResultStatus::Completed) {
                    ($this->recordRecurringExamCompletion)($exam->result, $exam);
                }
            }
        });

        if ($isFinal) {
            $submitted = $exam->result->refresh();

            return new ActionResult(
                data: [
                    'can_view_result' =>
                        $this->examService->canViewResultDetails($exam, $submitted) &&
                        $this->isPresentedAsResult($exam, $submitted),
                ]
            );
        }

        return new ActionResult(data: true);
    }

    private function isPresentedAsResult(Exam $exam, ExamResult $submitted): bool
    {
        if (!$exam->is_recurring) {
            return true;
        }

        $reloaded = Exam::query()
            ->with(['results' => fn($query) => $query->where('exam_results.user_id', $submitted->user_id)])
            ->findOrFail($exam->id);
        $presented = $this->examService->setCustomFieldForExam(
            $reloaded,
            $this->examService->presentableResultStatuses($reloaded)
        );

        return $presented->result?->id === $submitted->id;
    }
}
