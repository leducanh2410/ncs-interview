<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\AdminLeave\ApproveAdminLeaveRequestAction;
use App\Actions\Admin\AdminLeave\CreateAdminLeaveRequestAction;
use App\Actions\Admin\AdminLeave\GetAdminLeaveRequestDetailAction;
use App\Actions\Admin\AdminLeave\GetAdminLeaveRequestsAction;
use App\Actions\Admin\AdminLeave\GetMyAdminLeaveRequestsAction;
use App\Actions\Admin\AdminLeave\PreviewLeaveDaysAction;
use App\Actions\Admin\AdminLeave\RejectAdminLeaveRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminLeaveRequest\IndexRequest;
use App\Http\Requests\Admin\AdminLeaveRequest\MineRequest;
use App\Http\Requests\Admin\AdminLeaveRequest\PreviewDaysRequest;
use App\Http\Requests\Admin\AdminLeaveRequest\RejectRequest;
use App\Http\Requests\Admin\AdminLeaveRequest\StoreRequest;
use App\Models\AdminLeaveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AdminLeaveRequestController extends Controller
{
    public function index(IndexRequest $request, GetAdminLeaveRequestsAction $action): JsonResponse
    {
        return $action($request->validated())->toResponse(true);
    }

    public function mine(MineRequest $request, GetMyAdminLeaveRequestsAction $action): JsonResponse
    {
        return $action(Auth::user(), $request->validated())->toResponse(true);
    }

    public function show(AdminLeaveRequest $adminLeaveRequest, GetAdminLeaveRequestDetailAction $action): JsonResponse
    {
        return $action(Auth::user(), $adminLeaveRequest)->toResponse();
    }

    public function store(StoreRequest $request, CreateAdminLeaveRequestAction $action): JsonResponse
    {
        return $action(Auth::user(), $request->validated(), $request->file('attachments') ?? [])->toResponse();
    }

    public function previewDays(PreviewDaysRequest $request, PreviewLeaveDaysAction $action): JsonResponse
    {
        $data = $request->validated();

        return $action($data['start_date'], $data['end_date'])->toResponse();
    }

    public function approve(AdminLeaveRequest $adminLeaveRequest, ApproveAdminLeaveRequestAction $action): JsonResponse
    {
        return $action(Auth::user(), $adminLeaveRequest)->toResponse();
    }

    public function reject(
        AdminLeaveRequest $adminLeaveRequest,
        RejectRequest $request,
        RejectAdminLeaveRequestAction $action
    ): JsonResponse {
        return $action(Auth::user(), $adminLeaveRequest, $request->validated()['reject_reason'])->toResponse();
    }
}
