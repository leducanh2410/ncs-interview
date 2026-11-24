<?php

namespace App\Actions\User\Exam;

use App\Actions\ActionResult;
use App\Actions\Common\Exam\ResolveExamRoundAction;
use App\Enums\ErrorCode;
use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
use App\Models\Exam;
use App\Models\Question;
use App\Services\User\ExamService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StartOrResumeExamAction
{
    private ExamService $examService;

    public function __construct(ExamService $examService, private readonly ResolveExamRoundAction $resolveExamRound)
    {
        $this->examService = $examService;
    }

    public function __invoke(Exam $exam, ?string $lang = null): ActionResult
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

        $round = $exam->is_recurring ? ($this->resolveExamRound)((int) Auth::id(), $exam, $exam->results) : null;
        $exam = $this->examService->setCustomFieldForExam($exam, round: $round);
        $lastResult = null;
        if (!$this->examService->isExamActive($exam)) {
            return new ActionResult(error: ErrorCode::ExamTimeOutOfRange, status: 400);
        }

        if ($round !== null) {
            if ($exam->result && !$exam->result->completed_at) {
                $lastResult = $exam->result;
            } elseif ($round->activeTrigger === null) {
                return $exam->result && $this->examService->isCompletion($exam, $exam->result)
                    ? new ActionResult(error: ErrorCode::ExamAlreadyPassed, status: 400)
                    : new ActionResult(error: ErrorCode::ExamTimeOutOfRange, status: 400);
            }
        } elseif ($exam->result) {
            if ($exam->result->completed_at) {
                // external_form_prep ignores score/retry gating entirely: a
                // previously-submitted attempt simply falls through and a new
                // one is created below, allowing unlimited re-review.
                if ($exam->type !== ExamType::ExternalFormPrep) {
                    if ($exam->result->score >= $exam->min_score) {
                        return new ActionResult(error: ErrorCode::ExamAlreadyPassed, status: 400);
                    }
                    if (!$exam->is_required && $exam->retry_count >= $exam->retry_limit) {
                        return new ActionResult(error: ErrorCode::ExamRetryLimitExceeded, status: 400);
                    }
                }
            } else {
                $lastResult = $exam->result;
            }
        }

        $exam->load('questions.options');
        $totalQuestions = $exam->questions->count();

        if (!$lastResult) {
            $lastResult = DB::transaction(function () use ($exam, $lang, $totalQuestions, $round) {
                $result = $exam->results()->create([
                    'user_id' => auth()->id(),
                    'workplace_id' => $round?->activeTrigger?->workplaceId,
                    'occurrence' => $round?->activeTrigger?->occurrence ?? 1,
                    'score' => 0,
                    'total_questions' => $totalQuestions,
                    'correct_answers' => 0,
                    'started_at' => now()->format('Y-m-d H:i:s'),
                    'expires_at' => now()->addMinutes($exam->time_limit)->format('Y-m-d H:i:s'),
                    'status' => ExamResultStatus::InProgress,
                    'lang' => $this->examService->resolveLanguage($exam, $lang),
                    'shuffle_seed' => random_int(1, PHP_INT_MAX >> 32),
                ]);

                if ($exam->type !== ExamType::ExternalFormPrep) {
                    $this->examService->carryOverCorrectAnswers($exam, $result, $round?->roundResults->pluck('id'));
                }

                return $result;
            });
        } else {
            if ($lastResult->workplace_id === null && $round?->activeTrigger !== null) {
                $lastResult->workplace_id = $round->activeTrigger->workplaceId;
                $lastResult->occurrence = $round->activeTrigger->occurrence;
            }
            $lastResult->update([
                'total_questions' => $totalQuestions,
                'expires_at' => Carbon::parse($lastResult->started_at)
                    ->addMinutes($exam->time_limit)
                    ->format('Y-m-d H:i:s'),
                'lang' => $lastResult->lang ?? $this->examService->resolveLanguage($exam, $lang),
                'shuffle_seed' => $lastResult->shuffle_seed ?? random_int(1, PHP_INT_MAX >> 32),
            ]);
        }

        $lastResult->load('questions');
        $carriedQuestionIds = $lastResult->questions->where('is_carried_over', true)->pluck('question_id');
        $remainingQuestions = $exam->questions->reject(
            fn(Question $question) => $carriedQuestionIds->contains($question->id)
        );
        $exam->setRelation(
            'questions',
            $this->examService->shuffleWithSeed($remainingQuestions, $lastResult->shuffle_seed)
        );
        $exam->questions->each(fn(Question $question) => $question->options->makeHidden('is_correct'));
        $exam = $this->examService->resolveContent($exam, $lastResult->lang);
        $exam->carried_over_count = $carriedQuestionIds->count();

        $exam->result = $lastResult;
        $this->examService->hideUnrevealedCorrectness($exam);

        return new ActionResult(data: $exam);
    }
}
