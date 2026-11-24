<?php

namespace App\Http\Controllers\User;

use App\Actions\User\FixedSchedule\CreateFixedScheduleChangeRequestAction;
use App\Actions\User\FixedSchedule\GetUserFixedScheduleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\FixedSchedule\ShowRequest;
use App\Http\Requests\User\FixedSchedule\StoreChangeRequestRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class FixedScheduleController extends Controller
{
    public function show(ShowRequest $request, GetUserFixedScheduleAction $action): JsonResponse
    {
        $workplaceId = $request->validated('workplace_id');

        return $action(Auth::user(), $workplaceId !== null ? (int) $workplaceId : null)->toResponse();
    }

    public function storeChangeRequest(
        StoreChangeRequestRequest $request,
        CreateFixedScheduleChangeRequestAction $action
    ): JsonResponse {
        return $action(Auth::user(), $request->validated())->toResponse();
    }
}
