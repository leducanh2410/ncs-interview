<?php

namespace App\Actions\User\Exam;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
use App\Models\Exam;
use App\Services\User\ExamService;
use Illuminate\Support\Facades\Auth;

readonly class GetExamResultAction
{
    private ExamService $examService;

    public function __construct(ExamService $examService)
    {
        $this->examService = $examService;
    }

    public function __invoke(Exam $exam, ?string $lang = null): ActionResult
    {
        // Own-result exception: an employee removed from this exam's audience
        // keeps access to a result they already earned.
        if (!$this->examService->canUserViewExamResult(Auth::id(), $exam)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $isExternalFormPrep = $exam->type === ExamType::ExternalFormPrep;

        $exam->load([
            'results' => function ($query) {
                $query->where('exam_results.user_id', Auth::id());
            },
            'results.questions',
            'documents.attachments',
        ]);

        if ($exam->results->isEmpty()) {
            return new ActionResult(error: ErrorCode::ExamResultNotAvailable, status: 404);
        }

        // Pick the newest *presentable* result, not the newest overall: for
        // external_form_prep the user can start a re-review attempt while a
        // previous attempt is still pending_external, and that newer
        // in_progress (or later failed) row must not hide the submitted
        // attempt's answers and external form link.
        $exam = $this->examService->setCustomFieldForExam($exam, $this->examService->presentableResultStatuses($exam));
        if (!$this->examService->canViewResultDetails($exam, $exam->result)) {
            return new ActionResult(error: ErrorCode::ExamResultNotAvailable, status: 404);
        }

        $exam = $this->examService->resolveContent(
            $exam,
            $lang !== null ? $this->examService->resolveLanguage($exam, $lang) : $exam->result->lang
        );

        $correctOptions = [];
        foreach ($exam->questions as $question) {
            $correctOption = $question->options->where('is_correct', true)->first();
            $correctOptions[$question->id] = $correctOption->id;
        }

        foreach ($exam->result->questions as $question) {
            $question->correct_option_id = $correctOptions[$question->question_id] ?? null;
        }

        if ($isExternalFormPrep && $exam->result->status === ExamResultStatus::PendingExternal->value) {
            $exam->external = [
                'url' => $exam->external_url,
                'complete_pattern' => config('exam.default_external_complete_pattern'),
            ];
        }

        return new ActionResult(data: $exam);
    }
}
