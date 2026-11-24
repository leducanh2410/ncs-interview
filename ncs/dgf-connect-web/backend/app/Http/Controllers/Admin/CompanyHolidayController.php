<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\CompanyHoliday\CreateCompanyHolidayAction;
use App\Actions\Admin\CompanyHoliday\DeleteCompanyHolidayAction;
use App\Actions\Admin\CompanyHoliday\EnsureHolidayYearAction;
use App\Actions\Admin\CompanyHoliday\GetHolidayCalendarAction;
use App\Actions\Admin\CompanyHoliday\UpdateCompanyHolidayAction;
use App\Actions\Admin\CompanyHoliday\UpdateHolidaySettingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompanyHoliday\GenerateRequest;
use App\Http\Requests\Admin\CompanyHoliday\IndexRequest;
use App\Http\Requests\Admin\CompanyHoliday\StoreRequest;
use App\Http\Requests\Admin\CompanyHoliday\UpdateRequest;
use App\Http\Requests\Admin\CompanyHoliday\UpdateSettingRequest;
use App\Models\CompanyHoliday;
use Illuminate\Http\JsonResponse;

class CompanyHolidayController extends Controller
{
    public function index(IndexRequest $request, GetHolidayCalendarAction $action): JsonResponse
    {
        return $action((int) $request->validated()['year'])->toResponse();
    }

    public function generate(GenerateRequest $request, EnsureHolidayYearAction $action): JsonResponse
    {
        return $action((int) $request->validated()['year'])->toResponse();
    }

    public function store(StoreRequest $request, CreateCompanyHolidayAction $action): JsonResponse
    {
        return $action($request->validated())->toResponse();
    }

    public function update(
        CompanyHoliday $companyHoliday,
        UpdateRequest $request,
        UpdateCompanyHolidayAction $action
    ): JsonResponse {
        return $action($companyHoliday, $request->validated())->toResponse();
    }

    public function destroy(CompanyHoliday $companyHoliday, DeleteCompanyHolidayAction $action): JsonResponse
    {
        return $action($companyHoliday)->toResponse();
    }

    public function updateSetting(UpdateSettingRequest $request, UpdateHolidaySettingAction $action): JsonResponse
    {
        $data = $request->validated();

        return $action((int) $data['year'], (bool) $data['exclude_weekends'])->toResponse();
    }
}
