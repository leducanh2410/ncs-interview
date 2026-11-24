<?php

namespace App\Http\Controllers\Staff;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\Exam\AnswerRequest;
use App\Services\Staff\SelfCareService;
use Illuminate\Http\JsonResponse;

class SelfCareController extends Controller
{
    private SelfCareService $selfCareService;

    public function __construct(SelfCareService $selfCareService)
    {
        $this->selfCareService = $selfCareService;
    }

    /**
     * Get exam questions for self-care (no stress check period required).
     */
    public function getExam(): JsonResponse
    {
        $result = $this->selfCareService->getExam();

        return ResponseHelper::success($result);
    }

    /**
     * Submit answers and calculate result without saving to database.
     */
    public function submitExam(AnswerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $answers = $data['answers'] ?? [];

        if (empty($answers)) {
            return ResponseHelper::badRequest();
        }

        $result = $this->selfCareService->calculateResult($answers);

        return ResponseHelper::success($result);
    }
}
