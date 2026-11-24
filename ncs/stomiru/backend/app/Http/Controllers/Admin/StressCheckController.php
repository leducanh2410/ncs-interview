<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RoleName;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StressCheck\CreateStressCheckRequest;
use App\Http\Requests\Admin\StressCheck\GetExamineesRequest;
use App\Http\Requests\Admin\StressCheck\IndexRequest;
use App\Http\Requests\Admin\StressCheck\UpdateStressCheckRequest;
use App\Models\Company;
use App\Models\ExamSession;
use App\Models\StressCheckPeriod;
use App\Models\User;
use App\Services\Admin\ReportService;
use App\Services\Admin\StressCheckService;
use App\Services\Staff\ExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StressCheckController extends Controller
{
    private StressCheckService $stressCheckService;

    private ExamService $examService;

    private ReportService $reportService;

    public function __construct(
        StressCheckService $stressCheckService,
        ReportService $reportService,
        ExamService $examService
    ) {
        $this->stressCheckService = $stressCheckService;
        $this->reportService = $reportService;
        $this->examService = $examService;
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $stressCheck = $this->stressCheckService->listStressCheck($request->validated());

        return ResponseHelper::successPaginated($stressCheck);
    }

    public function create(CreateStressCheckRequest $request): JsonResponse
    {
        $stressCheck = $this->stressCheckService->create($request->validated());

        return ResponseHelper::success($stressCheck);
    }

    public function show(StressCheckPeriod $stressCheckPeriod): JsonResponse
    {
        $stressCheck = $this->stressCheckService->show($stressCheckPeriod);

        return ResponseHelper::success($stressCheck);
    }

    public function update(UpdateStressCheckRequest $request, StressCheckPeriod $stressCheckPeriod): JsonResponse
    {
        $stressCheck = $this->stressCheckService->update($stressCheckPeriod, $request->validated());

        return ResponseHelper::success($stressCheck);
    }

    public function destroy(StressCheckPeriod $stressCheckPeriod): JsonResponse
    {
        $this->stressCheckService->delete($stressCheckPeriod);

        return ResponseHelper::success();
    }

    public function getExaminees(GetExamineesRequest $request, StressCheckPeriod $stressCheckPeriod): JsonResponse
    {
        $examinees = $this->stressCheckService->getExaminees($stressCheckPeriod, $request->validated());

        return ResponseHelper::successPaginated($examinees);
    }

    public function exportExaminees(StressCheckPeriod $stressCheckPeriod): Response
    {
        $csvContent = $this->stressCheckService->exportExaminees($stressCheckPeriod);

        $bom = "\xEF\xBB\xBF";
        $filename = 'examinees_'.now()->format('Ymd').'.csv';

        return response($bom.$csvContent, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function getAdminReport(Company $company, StressCheckPeriod $stressCheckPeriod): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole(RoleName::Staff->value)) {
            throw ValidationException::withMessages([
                'message' => 'Staff is not allowed to access this feature.',
            ]);
        }
        if ($user->hasRole(RoleName::SuperAdmin->value)) {
            // if (! $company->id) {
            //     throw new InvalidArgumentException('Company ID is required for Super Admin.');
            // }
            // $companyId = $company->id;
            $companyId = $stressCheckPeriod->company_id;
        } else {
            $companyId = $user->company_id;
        }
        $report = $this->reportService->getAdminReport($stressCheckPeriod->id, $companyId);

        return ResponseHelper::success($report);
    }

    public function getExamineeResult(StressCheckPeriod $stressCheckPeriod, User $examinee): JsonResponse
    {
        $examSession = ExamSession::query()
            ->where('stress_check_period_id', $stressCheckPeriod->id)
            ->where('user_id', $examinee->id)
            ->firstOrFail();

        $result = $this->examService->getExamResult($examSession);

        return ResponseHelper::success($result);
    }
}
