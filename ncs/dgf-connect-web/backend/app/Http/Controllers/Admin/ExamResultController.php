<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Exam\CompleteExternalExamStepOverrideAction;
use App\Actions\Admin\ExamResult\ExportExamResultAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExamResult\IndexRequest;
use App\Models\ExamResult;
use App\Services\Admin\ExamResultService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExamResultController extends Controller
{
    private ExamResultService $examResultService;

    public function __construct(ExamResultService $examResultService)
    {
        $this->examResultService = $examResultService;
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $params = $request->validated();

        return ResponseHelper::successPaginated($this->examResultService->index($params));
    }

    public function export(IndexRequest $request, ExportExamResultAction $action): BinaryFileResponse|JsonResponse
    {
        $result = $action($request->validated());

        if ($result->getError()) {
            return $result->toResponse();
        }

        $data = $result->getData();

        return ExcelFacade::download($data['export'], $data['name'], Excel::CSV);
    }

    public function show(ExamResult $examResult): JsonResponse
    {
        $examResultQuestions = $this->examResultService->getExamResultDetail($examResult);

        return ResponseHelper::success($examResultQuestions);
    }

    public function completeExternalOverride(
        ExamResult $examResult,
        CompleteExternalExamStepOverrideAction $action
    ): JsonResponse {
        return $action($examResult)->toResponse();
    }
}
