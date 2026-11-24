<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Forecast\GenerateForecastAction;
use App\Actions\Admin\Forecast\GetForecastOptionsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Forecast\GenerateForecastRequest;
use App\Services\Dashboard\DashboardWorkplaceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForecastController extends Controller
{
    public function options(Request $request, GetForecastOptionsAction $action): JsonResponse
    {
        return $action(new DashboardWorkplaceResolver($request->user()))->toResponse();
    }

    public function generate(GenerateForecastRequest $request, GenerateForecastAction $action): JsonResponse
    {
        return $action(new DashboardWorkplaceResolver($request->user()), $request->validated())->toResponse();
    }
}
