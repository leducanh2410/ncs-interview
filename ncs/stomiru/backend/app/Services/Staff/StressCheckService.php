<?php

namespace App\Services\Staff;

use App\Enums\ErrorCode;
use App\Enums\ExamSessionStatus;
use App\Enums\StressCheckPeriodStatus;
use App\Helpers\ResponseHelper;
use App\Models\ExamSession;
use App\Models\Question;
use App\Models\QuestionCategory;
use App\Models\StressCheckPeriod;
use App\Services\QuestionSetService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

class StressCheckService
{
    protected QuestionSetService $questionSetService;

    public function __construct(QuestionSetService $questionSetService)
    {
        $this->questionSetService = $questionSetService;
    }

    public function stressCheckHistory(array $conditions): LengthAwarePaginator
    {
        $companyId = Auth::user()->company_id;

        $periodDirection = null;
        $responseStatusDirection = null;
        $statusDirection = null;
        $orderBy = '-id';

        if (! empty($conditions['sort'])) {
            $sort = $conditions['sort'];
            $sortField = str_starts_with($sort, '-') ? substr($sort, 1) : $sort;
            $sortDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';

            if ($sortField === 'period') {
                $periodDirection = $sortDirection;
            } elseif ($sortField === 'response_status') {
                $responseStatusDirection = $sortDirection;
            } elseif ($sortField === 'status') {
                $statusDirection = $sortDirection;
            } else {
                $orderBy = $sort.',-id';
            }
        }

        $query = StressCheckPeriod::getFilteredQuery($conditions, [], $orderBy);
        $query->where('company_id', $companyId)
            ->where('end_date', '<', now()->toDateString());

        if ($periodDirection) {
            $query->reorder()
                ->orderBy('start_date', $periodDirection)
                ->orderBy('id', 'desc');
        } elseif ($responseStatusDirection) {
            $userId = Auth::id();
            $query->leftJoin('exam_sessions', function ($join) use ($userId) {
                $join->on('stress_check_periods.id', '=', 'exam_sessions.stress_check_period_id')
                    ->where('exam_sessions.user_id', '=', $userId);
            })
                ->select('stress_check_periods.*')
                ->reorder()
                ->orderBy('exam_sessions.status', $responseStatusDirection)
                ->orderBy('stress_check_periods.id', 'desc');
        } elseif ($statusDirection) {
            $today = now()->toDateString();
            $query->reorder();
            $query->orderByRaw("
                CASE
                    WHEN start_date IS NULL OR end_date IS NULL THEN 0
                    WHEN ? < start_date THEN 1
                    WHEN ? <= end_date THEN 2
                    ELSE 3
                END {$statusDirection}
            ", [$today, $today]);
            $query->orderBy('id', 'desc');
        }

        $userId = Auth::id();
        $query->with(['examSessions' => function ($q) use ($userId) {
            $q->where('user_id', $userId);
        }]);

        return $query->paginate($conditions['page_size'] ?? 3);
    }

    public function getActiveStressCheck(): Collection
    {
        $companyId = Auth::user()->company_id;
        $userId = Auth::id();

        $today = now()->toDateString();

        return StressCheckPeriod::where('company_id', $companyId)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->with(['examSessions' => function ($q) use ($userId) {
                $q->where('user_id', $userId);
            }])
            ->get();
    }

    public function getExam(StressCheckPeriod $stressCheckPeriod): StressCheckPeriod
    {
        $user = Auth::user();

        if ($stressCheckPeriod->company_id !== $user->company_id) {
            throw new HttpResponseException(
                ResponseHelper::forbidden(ErrorCode::Forbidden, 'Unauthorized action.')
            );
        }

        if ($stressCheckPeriod->status !== StressCheckPeriodStatus::InProgress->value) {
            throw new HttpResponseException(
                ResponseHelper::forbidden(ErrorCode::Forbidden, 'Stress check is not available.')
            );
        }

        $session = ExamSession::firstOrCreate(
            [
                'stress_check_period_id' => $stressCheckPeriod->id,
                'user_id' => $user->id,
            ],
            [
                'started_at' => now(),
                'status' => ExamSessionStatus::NotStarted->value,
            ]
        );

        if ($session->status === ExamSessionStatus::Submitted->value) {
            throw new HttpResponseException(
                ResponseHelper::forbidden(ErrorCode::Forbidden, 'Exam already submitted.')
            );
        }

        // Use user's lang_code, fallback to 'ja' if not set
        $langCode = $user->lang_code ?? 'ja';

        $questions = Question::where('question_set_id', $stressCheckPeriod->question_set_id)
            ->orderBy('sort_order')
            ->with([
                'translations' => fn($q) => $q->where('lang_code', $langCode),
                'options' => fn($q) => $q->orderBy('sort_order'),
                'options.translations' => fn($q) => $q->where('lang_code', $langCode),
            ])
            ->get();

        // Map the user's existing answers to the fetched questions
        $answeredMap = collect($session->questions_answered ?? [])->keyBy('question_id');

        foreach ($questions as $question) {
            $answer = $answeredMap->get($question->id);
            $selectedOptionId = $answer['option_id'] ?? null;
            $question->setAttribute('is_answered', ! is_null($selectedOptionId));
            $question->setAttribute('selected_option_id', $selectedOptionId);
        }

        $questionCategories = QuestionCategory::query()
            ->with([
                'translations' => fn($q) => $q->where('lang_code', $langCode),
            ])
            ->get();

        $stressCheckPeriod->setRelation('questions', $questions);
        $stressCheckPeriod->setRelation('current_exam_session', $session);
        $stressCheckPeriod->setRelation('question_categories', $questionCategories);

        $stressCheckPeriod->setAttribute('question_set', $this->questionSetService->getQuestionSet($stressCheckPeriod->question_set_id, $langCode));

        return $stressCheckPeriod;
    }
}
