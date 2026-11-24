<?php

namespace App\Http\Controllers\Manage;

use App\Actions\Common\Salary\CollectUserSalaryAction;
use App\Actions\Common\Salary\CreateSalaryRequestAction;
use App\Actions\Manage\Salary\ApproveSalaryRequestAction;
use App\Actions\Manage\Salary\ConfirmPaidSalaryAction;
use App\Actions\Manage\Salary\CreateSalaryProcessAction;
use App\Actions\Manage\Salary\DeleteSalaryProcessAction;
use App\Actions\Manage\Salary\ExportSalariesAction;
use App\Actions\Manage\Salary\ExportSalaryRequestsAction;
use App\Actions\Manage\Salary\GetSalariesAction;
use App\Actions\Manage\Salary\GetSalaryProcessesAction;
use App\Actions\Manage\Salary\GetSalaryRequestAction;
use App\Actions\Manage\Salary\ImportRemainingSalaryAction;
use App\Actions\Manage\Salary\ImportSalaryDeductionAction;
use App\Actions\Manage\Salary\ImportTransferInfoAction;
use App\Actions\Manage\Salary\RejectSalaryRequestAction;
use App\Actions\Manage\Salary\UpdateSalaryProcessAction;
use App\Enums\ErrorCode;
use App\Helpers\CsvHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\Salary\CreateSalaryProcessRequest;
use App\Http\Requests\Manage\Salary\CreateSalaryRequestRequest;
use App\Http\Requests\Manage\Salary\GetUserSalaryRequest;
use App\Http\Requests\Manage\Salary\ImportDeductionCsvRequest;
use App\Http\Requests\Manage\Salary\ImportTransferCsvRequest;
use App\Http\Requests\Manage\Salary\RejectSalaryRequest;
use App\Http\Requests\Manage\Salary\SalaryIndexRequest;
use App\Http\Requests\Manage\Salary\SalaryProcessIndexRequest;
use App\Http\Requests\Manage\Salary\SalaryRequestIndexRequest;
use App\Models\SalaryProcess;
use App\Models\SalaryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SalaryController extends Controller
{
    public function getRequests(SalaryRequestIndexRequest $request, GetSalaryRequestAction $action): JsonResponse
    {
        return $action($request->validated())->toResponse(true);
    }

    public function exportSalaryRequests(
        SalaryRequestIndexRequest $request,
        ExportSalaryRequestsAction $action
    ): BinaryFileResponse|JsonResponse {
        $result = $action($request->validated());

        if ($result->getError()) {
            return $result->toResponse();
        }

        $data = $result->getData();

        return Excel::download($data['export'], $data['name']);
    }

    public function getSalaries(SalaryIndexRequest $request, GetSalariesAction $action): JsonResponse
    {
        return $action($request->validated())->toResponse(true);
    }

    public function getSalaryProcesses(
        SalaryProcessIndexRequest $request,
        GetSalaryProcessesAction $action
    ): JsonResponse {
        return $action($request->validated())->toResponse(true);
    }

    public function createSalaryProcess(
        CreateSalaryProcessRequest $request,
        CreateSalaryProcessAction $action
    ): JsonResponse {
        return $action($request->validated())->toResponse();
    }

    public function updateSalaryProcess(
        SalaryProcess $salaryProcess,
        CreateSalaryProcessRequest $request,
        UpdateSalaryProcessAction $action
    ): JsonResponse {
        return $action($salaryProcess, $request->validated())->toResponse();
    }

    public function deleteSalaryProcess(SalaryProcess $salaryProcess, DeleteSalaryProcessAction $action): JsonResponse
    {
        return $action($salaryProcess)->toResponse();
    }

    public function exportSalary(
        SalaryIndexRequest $request,
        ExportSalariesAction $action
    ): BinaryFileResponse|JsonResponse {
        $result = $action($request->validated());

        if ($result->getError()) {
            return $result->toResponse();
        }

        $data = $result->getData();

        return Excel::download($data['export'], $data['name']);
    }

    public function getUserSalary(GetUserSalaryRequest $request, CollectUserSalaryAction $action): JsonResponse
    {
        return $action($request->input('user_id'), false)->toResponse();
    }

    public function createSalaryRequest(
        CreateSalaryRequestRequest $request,
        CreateSalaryRequestAction $action
    ): JsonResponse {
        return $action($request->input('user_id'), $request->input('amount'), $request->input('type'))->toResponse();
    }

    public function getDetail(SalaryRequest $salaryRequest): JsonResponse
    {
        $salaryRequest->load(['user', 'approver', 'accountantApprover', 'details.salary']);

        return ResponseHelper::success($salaryRequest);
    }

    public function approve(SalaryRequest $salaryRequest, ApproveSalaryRequestAction $action): JsonResponse
    {
        return $action($salaryRequest)->toResponse();
    }

    public function reject(
        SalaryRequest $salaryRequest,
        RejectSalaryRequest $request,
        RejectSalaryRequestAction $action
    ): JsonResponse {
        return $action($salaryRequest, $request->input('reason'))->toResponse();
    }

    public function confirmPaid(SalaryRequest $salaryRequest, ConfirmPaidSalaryAction $action): JsonResponse
    {
        return $action($salaryRequest)->toResponse();
    }

    public function importDeductionCsv(
        ImportDeductionCsvRequest $request,
        ImportSalaryDeductionAction $action
    ): JsonResponse {
        $file = $request->file('file');
        $month = $request->input('month');
        try {
            $dataRows = CsvHelper::parse($file->getRealPath(), $request->input('encoding'));

            return $action($month, $dataRows)->toResponse();
        } catch (Throwable $e) {
            Log::error('Error while parsing CSV: ' . $e->getMessage());
            Log::error($e);

            return ResponseHelper::error(ErrorCode::SalaryCsvMalformed, status: 500);
        }
    }

    public function importTransferCsv(ImportTransferCsvRequest $request, ImportTransferInfoAction $action): JsonResponse
    {
        $file = $request->file('file');
        try {
            $dataRows = CsvHelper::parse($file->getRealPath(), $request->input('encoding'));

            return $action($dataRows)->toResponse();
        } catch (Throwable $e) {
            Log::error('Error while parsing CSV: ' . $e->getMessage());
            Log::error($e);

            return ResponseHelper::error(ErrorCode::SalaryCsvMalformed, status: 500);
        }
    }

    public function importRemainingCsv(
        ImportTransferCsvRequest $request,
        ImportRemainingSalaryAction $action
    ): JsonResponse {
        $file = $request->file('file');
        try {
            $dataRows = CsvHelper::parse($file->getRealPath(), $request->input('encoding'));

            return $action($dataRows)->toResponse();
        } catch (Throwable $e) {
            Log::error('Error while parsing CSV: ' . $e->getMessage());
            Log::error($e);

            return ResponseHelper::error(ErrorCode::SalaryCsvMalformed, status: 500);
        }
    }
}
