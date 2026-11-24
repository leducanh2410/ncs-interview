<?php

namespace App\Services\User;

use App\Actions\Common\Exam\ApplyExamAudienceScopeAction;
use App\Actions\Common\Exam\ResolveExamRoundAction;
use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\ExamResultQuestion;
use App\Models\QuestionOption;
use App\Models\WorkplaceUser;
use App\ValueObjects\Recurring\ExamRound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Random\Engine\Mt19937;
use Random\Randomizer;

class ExamService
{
    public function __construct(
        private readonly ApplyExamAudienceScopeAction $applyAudienceScope,
        private readonly ResolveExamRoundAction $resolveExamRound
    ) {}

    /**
     * Resolve `title` (and `external_url`, when present) to plain strings in
     * the given (or current) locale. Used by every user-facing endpoint so
     * mobile keeps receiving plain strings, unaware the content is stored
     * per-language server-side.
     *
     * The `translations` relation is unset once resolved: Eloquent
     * serializes every loaded relation by default, so leaving it loaded
     * would ship all five language rows alongside the resolved string.
     */
    public function resolveTitle(Exam $exam, ?string $locale = null): Exam
    {
        $locale = $locale ?? App::getLocale();
        $exam->loadMissing('translations');
        $exam->title = $exam->translation($locale);
        $exam->external_url = $exam->translation($locale, 'external_url');
        $exam->unsetRelation('translations');

        return $exam;
    }

    /**
     * Same as resolveTitle(), plus resolves every question/option content.
     */
    public function resolveContent(Exam $exam, ?string $locale = null): Exam
    {
        $locale = $locale ?? App::getLocale();
        $this->resolveTitle($exam, $locale);
        $exam->loadMissing(['questions.translations', 'questions.options.translations']);
        foreach ($exam->questions as $question) {
            $question->content = $question->translation($locale);
            $question->unsetRelation('translations');
            foreach ($question->options as $option) {
                $option->content = $option->translation($locale);
                $option->unsetRelation('translations');
            }
        }

        return $exam;
    }

    /**
     * Runs the same audience rule as the exam list and the pending-mandatory
     * list, as an existence query rather than a PHP re-implementation, so the
     * three decision points agree structurally instead of by convention.
     */
    public function canUserViewExam(int $userId, Exam $exam): bool
    {
        $workplaceIds = WorkplaceUser::query()->where('user_id', $userId)->pluck('workplace_id');

        return Exam::query()
            ->whereKey($exam->id)
            ->tap(fn(Builder $query) => ($this->applyAudienceScope)($query, $userId, $workplaceIds))
            ->exists();
    }

    /**
     * Reading an already-earned result is the one gated path where denial
     * punishes rather than protects: narrowing an exam's targeting must not
     * take away a result the employee already has. Every path that advances
     * an attempt - starting, submitting, and confirming the external form
     * step - stays on the strict gate above, so an in-progress attempt still
     * becomes unfinishable when the employee leaves the audience.
     */
    public function canUserViewExamResult(int $userId, Exam $exam): bool
    {
        return $this->canUserViewExam($userId, $exam) || $exam->results()->where('user_id', $userId)->exists();
    }

    public function isExamActive(Exam $exam): bool
    {
        $now = now();

        if ($exam->start_at && $exam->start_at->greaterThan($now)) {
            return false;
        }

        if ($exam->is_required) {
            return true;
        }

        return !($exam->end_at && $exam->end_at->lessThan($now));
    }

    /**
     * When $preferredStatuses is given, `result` is the newest result whose
     * status is in the list (null when none match) instead of the newest
     * overall. external_form_prep allows starting a new attempt while a
     * previous one is still `pending_external`, so "the newest row" and "the
     * row worth showing" can differ: the result screen must keep serving the
     * submitted attempt even while a newer in_progress/failed row exists.
     */
    public function setCustomFieldForExam(Exam $exam, ?array $preferredStatuses = null, ?ExamRound $round = null): Exam
    {
        if (!$exam->relationLoaded('results')) {
            $exam->load([
                'results' => function ($query) {
                    $query->where('exam_results.user_id', Auth::id());
                },
            ]);
        }

        // `id` breaks ties on purpose: created_at only has second precision,
        // so a start right after a submit can produce two rows sharing a
        // timestamp. Without the tie-break the "current session" would be
        // whichever row the database happened to return first, so start and
        // submit could disagree on which result is the current session.
        $results = $exam->results->sortBy([['created_at', 'desc'], ['id', 'desc']]);
        $candidates = $results;
        $retryResults = $results;

        if ($exam->is_recurring) {
            $round ??= ($this->resolveExamRound)((int) Auth::id(), $exam, $exam->results);

            if ($round->activeTrigger !== null) {
                $candidates = $round->roundResults->sortBy([['created_at', 'desc'], ['id', 'desc']]);
                $retryResults = $candidates;
            } else {
                $completions = $results->filter(fn(ExamResult $result) => $this->isCompletion($exam, $result));
                $latestCompletion = $completions->first();
                $candidates = $latestCompletion ? $completions : $results;
                if ($latestCompletion) {
                    $retryResults = $results->filter(
                        fn(ExamResult $result) => (int) $result->occurrence === (int) $latestCompletion->occurrence &&
                            ($result->workplace_id === null ||
                                $result->workplace_id === $latestCompletion->workplace_id) &&
                            ($result->id === $latestCompletion->id || !$this->isCompletion($exam, $result))
                    );
                }
            }
        } elseif ($exam->type !== ExamType::ExternalFormPrep) {
            $completions = $results->filter(fn(ExamResult $result) => $this->isCompletion($exam, $result));
            if ($completions->isNotEmpty()) {
                $candidates = $completions;
            }
        }

        unset($exam->results);
        $exam->result =
            $preferredStatuses === null
                ? $candidates->first()
                : $candidates->first(fn($result) => in_array($result->status, $preferredStatuses, true));
        $exam->retry_count = max(0, $retryResults->count() - 1);
        $exam->can_view_result = $candidates->contains(
            fn(ExamResult $result) => $this->canViewResultDetails($exam, $result)
        );

        return $exam;
    }

    /**
     * @return list<string>
     */
    public function presentableResultStatuses(Exam $exam): array
    {
        return $exam->type === ExamType::ExternalFormPrep
            ? [ExamResultStatus::PendingExternal->value, ExamResultStatus::Completed->value]
            : [ExamResultStatus::Completed->value, ExamResultStatus::Failed->value];
    }

    public function isCompletion(Exam $exam, ExamResult $result): bool
    {
        return $result->completed_at !== null && $result->score >= $exam->min_score;
    }

    public function resolveLanguage(Exam $exam, ?string $requested): string
    {
        $languages = $exam->languages ?? [];
        if ($requested !== null && in_array($requested, $languages, true)) {
            return $requested;
        }

        return App::getLocale();
    }

    public function canViewResultDetails(Exam $exam, ?ExamResult $result): bool
    {
        if (!$result) {
            return false;
        }

        if ($exam->type === ExamType::ExternalFormPrep) {
            return in_array(
                $result->status,
                [ExamResultStatus::PendingExternal->value, ExamResultStatus::Completed->value],
                true
            );
        }

        if (!$result->completed_at) {
            return false;
        }

        if ($exam->show_result_immediately) {
            return true;
        }

        return $exam->end_at !== null && $exam->end_at->lessThanOrEqualTo(now());
    }

    public function hideUnrevealedCorrectness(Exam $exam): void
    {
        $result = $exam->result;
        if (!$result || !$result->relationLoaded('questions') || $this->canViewResultDetails($exam, $result)) {
            return;
        }

        $result->questions->each(fn(ExamResultQuestion $answer) => $answer->makeHidden('is_correct'));
    }

    public function gradeExamResult(Collection $questionKeyedById, ExamResult $examResult): int
    {
        $correctCount = 0;
        foreach ($examResult->questions as $questionResult) {
            if ($questionResult->is_carried_over) {
                $correctCount++;

                continue;
            }

            $question = $questionKeyedById[$questionResult->question_id] ?? null;
            $correctOption = $question?->options->firstWhere('is_correct', true);
            $isCorrect = $correctOption !== null && $questionResult->question_option_id === $correctOption->id;

            if ($questionResult->is_correct !== $isCorrect) {
                $questionResult->update(['is_correct' => $isCorrect]);
            }

            if ($isCorrect) {
                $correctCount++;
            }
        }

        return $correctCount;
    }

    /**
     * @param Collection<int, int>|null $candidateResultIds
     */
    public function carryOverCorrectAnswers(
        Exam $exam,
        ExamResult $newResult,
        ?Collection $candidateResultIds = null
    ): void {
        $previousResult = ExamResult::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', $newResult->user_id)
            ->whereKeyNot($newResult->id)
            ->whereNotNull('completed_at')
            ->when($candidateResultIds !== null, fn($query) => $query->whereKey($candidateResultIds->all()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->with('questions')
            ->first();

        if (!$previousResult) {
            return;
        }

        $correctOptionIds = QuestionOption::query()
            ->whereIn('question_id', $exam->questions()->select('id'))
            ->where('is_correct', true)
            ->pluck('question_id', 'id');

        $now = now();
        $rows = $previousResult->questions
            ->filter(
                fn(ExamResultQuestion $answer) => $answer->is_correct &&
                    ($correctOptionIds[$answer->question_option_id] ?? null) === $answer->question_id
            )
            ->map(
                fn(ExamResultQuestion $answer) => [
                    'exam_result_id' => $newResult->id,
                    'question_id' => $answer->question_id,
                    'question_option_id' => $answer->question_option_id,
                    'is_correct' => true,
                    'is_carried_over' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            )
            ->values()
            ->all();

        if (!empty($rows)) {
            ExamResultQuestion::query()->insert($rows);
        }
    }

    public function shuffleWithSeed(Collection $items, int $seed): Collection
    {
        return $items::make((new Randomizer(new Mt19937($seed)))->shuffleArray($items->values()->all()));
    }
}
