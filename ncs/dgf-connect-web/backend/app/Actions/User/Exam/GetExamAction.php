<?php

namespace App\Actions\User\Exam;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\Exam;
use App\Services\User\ExamService;
use Illuminate\Support\Facades\Auth;

readonly class GetExamAction
{
    private ExamService $examService;

    public function __construct(ExamService $examService)
    {
        $this->examService = $examService;
    }

    public function __invoke(Exam $exam, ?string $lang = null): ActionResult
    {
        if (!$this->examService->canUserViewExam(Auth::id(), $exam)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        if (!$this->examService->isExamActive($exam)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $exam->load([
            'results' => function ($query) {
                $query->where('exam_results.user_id', Auth::id());
            },
            'results.questions',
            'documents.attachments',
        ]);

        $exam = $this->examService->setCustomFieldForExam($exam);
        $this->examService->hideUnrevealedCorrectness($exam);
        $exam = $this->examService->resolveTitle($exam, $this->examService->resolveLanguage($exam, $lang));

        return new ActionResult(data: $exam);
    }
}
