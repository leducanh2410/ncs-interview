<?php

namespace App\Http\Controllers\Staff;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\Exam\AnswerRequest;
use App\Models\ExamSession;
use App\Models\StressCheckPeriod;
use App\Services\Staff\ExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ExamController extends Controller
{
    private ExamService $examService;

    public function __construct(ExamService $examService)
    {
        $this->examService = $examService;
    }

    public function saveAnswer(AnswerRequest $request, StressCheckPeriod $stressCheckPeriod): JsonResponse
    {
        $examSession = ExamSession::query()
            ->where('stress_check_period_id', $stressCheckPeriod->id)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $result = $this->examService->saveAnswer($examSession, $request->validated());

        return ResponseHelper::success($result);
    }

    public function getExamResult(StressCheckPeriod $stressCheckPeriod): JsonResponse
    {
        $examSession = ExamSession::query()
            ->where('stress_check_period_id', $stressCheckPeriod->id)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $result = $this->examService->getExamResult($examSession);

        return ResponseHelper::success($result);
    }
}
