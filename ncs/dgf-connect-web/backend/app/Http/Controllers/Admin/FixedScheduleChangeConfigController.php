<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\FixedSchedule\BulkUpsertFixedScheduleChangeConfigAction;
use App\Actions\Admin\FixedSchedule\GetFixedScheduleChangeConfigsAction;
use App\Actions\Admin\FixedSchedule\UpsertFixedScheduleChangeConfigAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FixedScheduleChangeConfig\BulkUpdateRequest;
use App\Http\Requests\Admin\FixedScheduleChangeConfig\UpdateRequest;
use App\Models\Workplace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class FixedScheduleChangeConfigController extends Controller
{
    public function index(GetFixedScheduleChangeConfigsAction $action): JsonResponse
    {
        return $action(Auth::user())->toResponse();
    }

    public function update(
        Workplace $workplace,
        UpdateRequest $request,
        UpsertFixedScheduleChangeConfigAction $action
    ): JsonResponse {
        return $action(Auth::user(), $workplace, $request->validated())->toResponse();
    }

    public function bulkUpdate(
        BulkUpdateRequest $request,
        BulkUpsertFixedScheduleChangeConfigAction $action
    ): JsonResponse {
        $data = $request->validated();

        return $action(Auth::user(), $data['workplace_ids'], $data)->toResponse();
    }
}
