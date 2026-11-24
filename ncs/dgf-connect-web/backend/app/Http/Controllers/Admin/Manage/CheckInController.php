<?php

namespace App\Http\Controllers\Admin\Manage;

use App\Actions\Admin\Manage\CheckInAction;
use App\Actions\Admin\Manage\CheckOutAction;
use App\Actions\Common\CheckIn\AssertShiftWorkplaceAccessAction;
use App\Enums\PermissionName;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Manage\CheckIn\GetCheckInShiftRequest;
use App\Http\Requests\Admin\Manage\CheckIn\SubmitRequest;
use App\Models\User;
use App\Services\User\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CheckInController extends Controller
{
    public function __construct(private readonly CheckInService $checkInService) {}

    public function getCheckInShift(GetCheckInShiftRequest $request): JsonResponse
    {
        $userId = $this->resolveStaffNoToUserId($request->validated()['staff_no']);

        return ResponseHelper::success(
            $this->checkInService->getCheckInShift($userId, PermissionName::ShiftCheckIn->value)
        );
    }

    public function checkIn(
        SubmitRequest $request,
        CheckInAction $action,
        AssertShiftWorkplaceAccessAction $assertAccess
    ): JsonResponse {
        $data = $this->resolveTargetUserId($request->validated());
        if ($error = $assertAccess(Auth::user(), (int) $data['shift_user_id'], PermissionName::ShiftCheckIn->value)) {
            return ResponseHelper::error($error, 403);
        }

        return $action($data)->toResponse();
    }

    public function checkOut(
        SubmitRequest $request,
        CheckOutAction $action,
        AssertShiftWorkplaceAccessAction $assertAccess
    ): JsonResponse {
        $data = $this->resolveTargetUserId($request->validated());
        if ($error = $assertAccess(Auth::user(), (int) $data['shift_user_id'], PermissionName::ShiftCheckIn->value)) {
            return ResponseHelper::error($error, 403);
        }

        return $action($data)->toResponse();
    }

    private function resolveTargetUserId(array $data): array
    {
        $data['user_id'] = $this->resolveStaffNoToUserId($data['staff_no']);
        unset($data['staff_no']);

        return $data;
    }

    private function resolveStaffNoToUserId(string $staffNo): int
    {
        return User::where('staff_no', $staffNo)->value('id');
    }
}
