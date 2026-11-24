<?php

namespace App\Http\Controllers\User;

use App\Actions\User\Exam\CompleteExternalExamStepAction;
use App\Actions\User\Exam\GetExamAction;
use App\Actions\User\Exam\GetExamResultAction;
use App\Actions\User\Exam\GetExamsAction;
use App\Actions\User\Exam\StartOrResumeExamAction;
use App\Actions\User\Exam\SubmitExamAnswerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\Exam\ExamLanguageRequest;
use App\Http\Requests\User\Exam\SubmitRequest;
use App\Models\Exam;
use App\Models\ExamResult;
use Illuminate\Http\JsonResponse;

class ExamController extends Controller
{
    public function index(GetExamsAction $action): JsonResponse
    {
        return $action([])->toResponse(true);
    }

    public function show(Exam $exam, ExamLanguageRequest $request, GetExamAction $action): JsonResponse
    {
        return $action($exam, $request->validated('lang'))->toResponse();
    }

    public function startOrResumeExam(
        Exam $exam,
        ExamLanguageRequest $request,
        StartOrResumeExamAction $action
    ): JsonResponse {
        return $action($exam, $request->validated('lang'))->toResponse();
    }

    public function submitExam(Exam $exam, SubmitRequest $request, SubmitExamAnswerAction $action): JsonResponse
    {
        return $action($exam, $request->input('answers'), $request->input('is_final', false))->toResponse();
    }

    public function getResult(Exam $exam, ExamLanguageRequest $request, GetExamResultAction $action): JsonResponse
    {
        return $action($exam, $request->validated('lang'))->toResponse();
    }

    public function completeExternal(
        Exam $exam,
        ExamResult $result,
        CompleteExternalExamStepAction $action
    ): JsonResponse {
        return $action($exam, $result)->toResponse();
    }
}
