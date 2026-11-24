<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\PaperReconciliation\GetPaperReconciliationDetailAction;
use App\Actions\Admin\PaperReconciliation\GetPaperReconciliationFilterOptionsAction;
use App\Actions\Admin\PaperReconciliation\GetPaperReconciliationListAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PaperReconciliation\FilterOptionsRequest;
use App\Http\Requests\Admin\PaperReconciliation\IndexRequest;
use Illuminate\Http\JsonResponse;

class PaperReconciliationController extends Controller
{
    public function index(IndexRequest $request, GetPaperReconciliationListAction $action): JsonResponse
    {
        return $action($request->validated())->toResponse(isPaginated: true);
    }

    public function show(int $user, GetPaperReconciliationDetailAction $action): JsonResponse
    {
        return $action($user)->toResponse();
    }

    public function filterOptions(
        FilterOptionsRequest $request,
        GetPaperReconciliationFilterOptionsAction $action
    ): JsonResponse {
        $workplaceId = $request->validated('workplace_id');

        return $action($workplaceId === null ? null : (int) $workplaceId, $request->validated('date'))->toResponse();
    }
}
