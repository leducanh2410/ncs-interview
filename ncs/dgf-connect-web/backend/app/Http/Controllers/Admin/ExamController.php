<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Exam\CreateExamAction;
use App\Actions\Admin\Exam\DeleteExamAction;
use App\Actions\Admin\Exam\GetExamStatisticsAction;
use App\Actions\Admin\Exam\UpdateExamAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Exam\CreateUpdateExamRequest;
use App\Http\Requests\Admin\Exam\IndexRequest;
use App\Http\Requests\Admin\Exam\StatisticsRequest;
use App\Models\Exam;
use App\Services\Admin\ExamService;
use Illuminate\Http\JsonResponse;

class ExamController extends Controller
{
    private ExamService $examService;

    public function __construct(ExamService $examService)
    {
        $this->examService = $examService;
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $exams = $this->examService->list($request->validated());

        return ResponseHelper::successPaginated($exams);
    }

    public function show(Exam $exam): JsonResponse
    {
        return ResponseHelper::success($this->examService->show($exam));
    }

    public function store(CreateUpdateExamRequest $request, CreateExamAction $action): JsonResponse
    {
        $exam = $action($request->validated());

        return ResponseHelper::success($exam);
    }

    public function update(Exam $exam, CreateUpdateExamRequest $request, UpdateExamAction $action): JsonResponse
    {
        $updated = $action($exam, $request->validated());

        return ResponseHelper::success($updated);
    }

    public function statistics(Exam $exam, StatisticsRequest $request, GetExamStatisticsAction $action): JsonResponse
    {
        return ResponseHelper::success($action($exam, $request->validated()));
    }

    public function destroy(Exam $exam, DeleteExamAction $action): JsonResponse
    {
        return ResponseHelper::success($action($exam));
    }
}
