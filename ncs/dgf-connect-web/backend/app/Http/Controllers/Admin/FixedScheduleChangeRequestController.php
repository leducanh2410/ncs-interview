<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\FixedSchedule\ApproveFixedScheduleChangeRequestAction;
use App\Actions\Admin\FixedSchedule\BulkResolveFixedScheduleChangeRequestsAction;
use App\Actions\Admin\FixedSchedule\ExportFixedScheduleChangeRequestsAction;
use App\Actions\Admin\FixedSchedule\GetFixedScheduleChangeRequestsAction;
use App\Actions\Admin\FixedSchedule\RejectFixedScheduleChangeRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FixedScheduleChangeRequest\BulkResolveRequest;
use App\Http\Requests\Admin\FixedScheduleChangeRequest\IndexRequest;
use App\Models\FixedScheduleChangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FixedScheduleChangeRequestController extends Controller
{
    public function index(IndexRequest $request, GetFixedScheduleChangeRequestsAction $action): JsonResponse
    {
        return $action(Auth::user(), $request->validated())->toResponse(true);
    }

    public function approve(
        FixedScheduleChangeRequest $fixedScheduleChangeRequest,
        ApproveFixedScheduleChangeRequestAction $action
    ): JsonResponse {
        return $action(Auth::user(), $fixedScheduleChangeRequest)->toResponse();
    }

    public function reject(
        FixedScheduleChangeRequest $fixedScheduleChangeRequest,
        RejectFixedScheduleChangeRequestAction $action
    ): JsonResponse {
        return $action(Auth::user(), $fixedScheduleChangeRequest)->toResponse();
    }

    public function bulkResolve(
        BulkResolveRequest $request,
        BulkResolveFixedScheduleChangeRequestsAction $action
    ): JsonResponse {
        $data = $request->validated();

        return $action(Auth::user(), $data['ids'], $data['action'])->toResponse();
    }

    public function export(
        IndexRequest $request,
        ExportFixedScheduleChangeRequestsAction $action
    ): BinaryFileResponse|JsonResponse {
        $result = $action(Auth::user(), $request->validated());
        if ($result->getError()) {
            return $result->toResponse();
        }

        $data = $result->getData();

        return Excel::download($data['export'], $data['name']);
    }
}
