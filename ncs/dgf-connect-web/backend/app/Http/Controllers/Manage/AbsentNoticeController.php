<?php

namespace App\Http\Controllers\Manage;

use App\Actions\Manage\AbsentNotice\GetAbsentNoticesAction;
use App\Actions\Manage\AbsentNotice\UpdateAbsentNoticeStatusAction;
use App\Enums\AbsentNoticeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\AbsentNotice\IndexRequest;
use App\Http\Requests\Manage\AbsentNotice\UpdateStatusRequest;
use App\Models\ShiftAbsentNotice;
use Illuminate\Http\JsonResponse;

class AbsentNoticeController extends Controller
{
    public function index(IndexRequest $request, GetAbsentNoticesAction $action): JsonResponse
    {
        return $action($request->validated())->toResponse(true);
    }

    public function updateStatus(
        UpdateStatusRequest $request,
        ShiftAbsentNotice $absentNotice,
        UpdateAbsentNoticeStatusAction $action
    ): JsonResponse {
        $status = AbsentNoticeStatus::from($request->validated('status'));

        return $action($absentNotice, $status)->toResponse();
    }
}
