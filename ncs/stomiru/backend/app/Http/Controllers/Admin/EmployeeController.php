<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Employee\CreateEmployeeRequest;
use App\Http\Requests\Admin\Employee\ImportCsvRequest;
use App\Http\Requests\Admin\Employee\IndexRequest;
use App\Http\Requests\Admin\Employee\UpdateEmployeeRequest;
use App\Models\User;
use App\Services\Admin\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EmployeeController extends Controller
{
    private EmployeeService $employeeService;

    public function __construct(EmployeeService $employeeService)
    {
        $this->employeeService = $employeeService;
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $employees = $this->employeeService->listEmployees($request->validated());

        return ResponseHelper::successPaginated($employees);
    }

    public function create(CreateEmployeeRequest $request): JsonResponse
    {
        $employee = $this->employeeService->create($request->validated());

        return ResponseHelper::success($employee);
    }

    public function update(User $employee, UpdateEmployeeRequest $request): JsonResponse
    {
        $updated = $this->employeeService->update($employee, $request->validated());

        return ResponseHelper::success($updated);
    }

    public function show(User $employee): JsonResponse
    {
        return ResponseHelper::success($this->employeeService->show($employee));
    }

    public function exportCsv(Request $request): Response
    {
        $csvContent = $this->employeeService->exportEmployees($request);

        $bom = "\xEF\xBB\xBF";
        $filename = 'employees_'.now()->format('Ymd').'.csv';

        return response($bom.$csvContent, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function importCsv(ImportCsvRequest $request): JsonResponse
    {
        $result = $this->employeeService->importFromCsv($request->file('file'), $request->input('company_id', 0));

        return ResponseHelper::success($result);
    }

    public function requestDelete(User $employee): JsonResponse
    {
        $this->employeeService->requestDelete($employee);

        return ResponseHelper::success();
    }

    public function confirmRequestDelete(User $employee, Request $request): JsonResponse
    {
        $this->employeeService->confirmDeleteRequest($employee, $request);

        return ResponseHelper::success();
    }

    public function delete(User $user): JsonResponse
    {
        $this->employeeService->delete($user);

        return ResponseHelper::success();
    }
}
