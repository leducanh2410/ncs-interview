<?php

namespace App\Actions\User\Exam;

use App\Actions\ActionResult;
use App\Actions\Common\Exam\ApplyExamAudienceScopeAction;
use App\Actions\Common\Exam\GetUnavailableRecurringExamIdsAction;
use App\Models\Exam;
use App\Models\WorkplaceUser;
use App\Services\User\ExamService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class GetExamsAction
{
    private ExamService $examService;

    private ApplyExamAudienceScopeAction $applyAudienceScope;

    private GetUnavailableRecurringExamIdsAction $getUnavailableRecurringExamIds;

    public function __construct(
        ExamService $examService,
        ApplyExamAudienceScopeAction $applyAudienceScope,
        GetUnavailableRecurringExamIdsAction $getUnavailableRecurringExamIds
    ) {
        $this->examService = $examService;
        $this->applyAudienceScope = $applyAudienceScope;
        $this->getUnavailableRecurringExamIds = $getUnavailableRecurringExamIds;
    }

    public function __invoke(array $conditions): ActionResult
    {
        $pageSize = $conditions['page_size'] ?? null;
        // A workplace-less employee needs no short-circuit: the audience scope
        // already matches nothing for them, and going through paginate() is
        // what resolves a null page size to the model default. That also
        // sidesteps dev's DivisionByZeroError fix - the LengthAwarePaginator
        // constructed by hand with a null page size is gone with the shortcut.
        $workplaceIds = WorkplaceUser::query()->where('user_id', Auth::id())->pluck('workplace_id');

        $now = now();

        $exams = Exam::query()
            ->with([
                'results' => function ($query) {
                    $query->where('exam_results.user_id', Auth::id());
                },
                'documents',
                'translations',
            ])
            ->tap(fn(Builder $query) => ($this->applyAudienceScope)($query, Auth::id(), $workplaceIds))
            ->whereNotIn('id', ($this->getUnavailableRecurringExamIds)((int) Auth::id(), $workplaceIds))
            ->where(function ($query) use ($now) {
                $query->where('start_at', '<=', $now)->orWhereNull('start_at');
            })
            ->where(function ($query) use ($now) {
                $query->where('is_required', true)->orWhere(function ($optionalExamQuery) use ($now) {
                    $optionalExamQuery->where('end_at', '>=', $now)->orWhereNull('end_at');
                });
            })
            ->orderByDesc('start_at')
            ->orderByDesc('id')
            ->paginate($pageSize);

        $exams->through(function ($exam) {
            $this->examService->setCustomFieldForExam($exam);
            $this->examService->resolveTitle($exam);

            return $exam;
        });

        return new ActionResult(data: $exams);
    }
}
