<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\Company\CreateCompanyRequest;
use App\Http\Requests\SuperAdmin\Company\IndexRequest;
use App\Http\Requests\SuperAdmin\Company\ShowRequest;
use App\Http\Requests\SuperAdmin\Company\UpdateCompanyNoteRequest;
use App\Http\Requests\SuperAdmin\Company\UpdateCompanyRequest;
use App\Models\Company;
use App\Services\SuperAdmin\CompanyService;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    private CompanyService $companyService;

    public function __construct(CompanyService $companyService)
    {
        $this->companyService = $companyService;
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $companies = $this->companyService->listCompanies($request->validated());

        return ResponseHelper::successPaginated($companies);
    }

    public function create(CreateCompanyRequest $request): JsonResponse
    {
        $company = $this->companyService->create($request->validated());

        return ResponseHelper::success($company);
    }

    public function show(ShowRequest $request, Company $company): JsonResponse
    {
        return ResponseHelper::success($this->companyService->show($company, $request->validated()));
    }

    public function update(Company $company, UpdateCompanyRequest $request): JsonResponse
    {
        $updated = $this->companyService->update($company, $request->validated());

        return ResponseHelper::success($updated);
    }

    public function updateNote(Company $company, UpdateCompanyNoteRequest $request): JsonResponse
    {
        $updated = $this->companyService->updateNote($company, $request->validated());

        return ResponseHelper::success($updated);
    }

    public function destroy(Company $company): JsonResponse
    {
        return ResponseHelper::success($this->companyService->delete($company));
    }

    public function approve(Company $company): JsonResponse
    {
        return ResponseHelper::success($this->companyService->approve($company));
    }

    public function restore(Company $company): JsonResponse
    {
        return ResponseHelper::success($this->companyService->restore($company));
    }
}
