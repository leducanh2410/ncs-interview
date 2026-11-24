<?php

namespace App\Http\Controllers\Manage;

use App\Actions\Common\CheckIn\AssertShiftWorkplaceAccessAction;
use App\Actions\Manage\Shift\AssignShiftUsersAction;
use App\Actions\Manage\Shift\BulkUpdateAllowOvertimeAction;
use App\Actions\Manage\Shift\CalculateOvertimeEligibilityAction;
use App\Actions\Manage\Shift\DeleteShiftAction;
use App\Actions\Manage\Shift\ExportWeeklyWorkHoursAction;
use App\Actions\Manage\Shift\GetShiftDeleteImpactAction;
use App\Actions\Manage\Shift\GetShiftsAction;
use App\Actions\Manage\Shift\GetShiftWeeklyWorkLoadAction;
use App\Actions\Manage\Shift\GetWeeklyWorkHoursAction;
use App\Actions\Manage\Shift\ImportCsvShiftAction;
use App\Actions\Manage\Shift\UpdateAllowOvertimeAction;
use App\Actions\Manage\Shift\UpdateUserSalaryAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Helpers\CsvHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\Shift\BulkUpdateAllowOvertimeRequest;
use App\Http\Requests\Manage\Shift\CheckInRequest;
use App\Http\Requests\Manage\Shift\CreateRequest;
use App\Http\Requests\Manage\Shift\ImportCsvRequest;
use App\Http\Requests\Manage\Shift\IndexRequest;
use App\Http\Requests\Manage\Shift\OvertimeEligibilityRequest;
use App\Http\Requests\Manage\Shift\SearchUserRequest;
use App\Http\Requests\Manage\Shift\UpdateAllowOvertimeRequest;
use App\Http\Requests\Manage\Shift\UpdateConfigRequest;
use App\Http\Requests\Manage\Shift\UpdateRequest;
use App\Http\Requests\Manage\Shift\UpdateSalaryRequest;
use App\Http\Requests\Manage\Shift\WeeklyWorkHoursRequest;
use App\Http\Requests\Manage\Shift\WeeklyWorkLoadRequest;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Services\Manage\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ShiftController extends Controller
{
    private readonly ShiftService $shiftService;

    public function __construct(ShiftService $shiftService)
    {
        $this->shiftService = $shiftService;
    }

    public function searchUser(SearchUserRequest $request): JsonResponse
    {
        $conditions = $request->validated();

        if (isset($conditions['staff_no'])) {
            $conditions['exactStaffNo'] = $conditions['staff_no'];
            unset($conditions['staff_no'], $conditions['q']);
        } elseif (!empty($conditions['q'])) {
            $conditions['query'] = $conditions['q'];
            unset($conditions['q']);
        } else {
            unset($conditions['q']);
        }

        return ResponseHelper::success($this->shiftService->searchUserForShift(Auth::user(), $conditions));
    }

    public function index(IndexRequest $request, GetShiftsAction $action): JsonResponse
    {
        return $action($request->validated(), true, Auth::user())->toResponse();
    }

    public function indexSimple(IndexRequest $request, GetShiftsAction $action): JsonResponse
    {
        return $action($request->validated(), false, Auth::user())->toResponse();
    }

    public function weeklyWorkHours(WeeklyWorkHoursRequest $request, GetWeeklyWorkHoursAction $action): JsonResponse
    {
        return $action(Auth::user(), $request->validated())->toResponse();
    }

    public function exportWeeklyWorkHours(
        WeeklyWorkHoursRequest $request,
        ExportWeeklyWorkHoursAction $action
    ): BinaryFileResponse {
        $data = $action(Auth::user(), $request->validated())->getData();

        return Excel::download($data['export'], $data['name']);
    }

    public function getActiveShifts(): JsonResponse
    {
        return ResponseHelper::success($this->shiftService->getActiveShifts(Auth::user()));
    }

    public function create(CreateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $shift = $this->shiftService->findByDateAndWorkplaceShiftId($data['date'], $data['workplace_shift_id']);
        if ($shift) {
            return ResponseHelper::badRequest(ErrorCode::ShiftAlreadyExists);
        }

        $result = $this->shiftService->createShift(
            Auth::user(),
            $data['date'],
            $data['workplace_shift_id'],
            $data['fixed_total'],
            $data['temp_total'],
            $data['max_registered_temp'] ?? null,
            $data['break_minutes'] ?? null
        );
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400);
        }

        return ResponseHelper::success($result['data']);
    }

    public function detail(Shift $shift): JsonResponse
    {
        return ResponseHelper::success($this->shiftService->getShiftDetail(Auth::user(), $shift));
    }

    public function deleteImpact(Shift $shift, GetShiftDeleteImpactAction $action): JsonResponse
    {
        return $action(Auth::user(), $shift)->toResponse();
    }

    public function delete(Shift $shift, DeleteShiftAction $action): JsonResponse
    {
        return $action(Auth::user(), $shift)->toResponse();
    }

    public function exportShift(Shift $shift): BinaryFileResponse
    {
        $reports = $this->shiftService->exportShiftDetail(Auth::user(), $shift);

        return Excel::download($reports, "shift_{$shift->id}.xlsx");
    }

    public function update(Shift $shift, UpdateRequest $request, AssignShiftUsersAction $action): JsonResponse
    {
        $data = $request->validated();

        return $action(
            Auth::user(),
            $shift,
            $data['fixed_total'],
            $data['temp_total'],
            $data['fixed'],
            $data['temp'],
            $data['max_registered_temp'] ?? null,
            $data['break_minutes'] ?? null
        )->toResponse();
    }

    public function updateConfig(Shift $shift, UpdateConfigRequest $request): JsonResponse
    {
        $result = $this->shiftService->updateConfig(Auth::user(), $shift, $request->validated());
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $result['data'] ?? null);
        }

        return ResponseHelper::success($result['data']);
    }

    public function importCsv(Shift $shift, ImportCsvRequest $request, ImportCsvShiftAction $action): JsonResponse
    {
        $file = $request->file('file');
        $encoding = $request->input('encoding');
        try {
            $dataRows = CsvHelper::parse($file->getRealPath(), $encoding);

            return $action(Auth::user(), $shift, $dataRows)->toResponse();
        } catch (Throwable $e) {
            Log::error($e);

            return ResponseHelper::error(ErrorCode::ShiftCsvInvalidRow, status: 500);
        }
    }

    public function checkInUser(
        ShiftUser $shiftUser,
        CheckInRequest $request,
        AssertShiftWorkplaceAccessAction $assertAccess
    ): JsonResponse {
        if ($error = $assertAccess(Auth::user(), $shiftUser, PermissionName::ShiftCheckIn->value)) {
            return ResponseHelper::error($error, 403);
        }

        return ResponseHelper::success(
            $this->shiftService->checkInUser($shiftUser, $request->input('date'), $request->input('time'))
        );
    }

    public function checkOutUser(
        ShiftUser $shiftUser,
        CheckInRequest $request,
        AssertShiftWorkplaceAccessAction $assertAccess
    ): JsonResponse {
        if ($error = $assertAccess(Auth::user(), $shiftUser, PermissionName::ShiftCheckIn->value)) {
            return ResponseHelper::error($error, 403);
        }

        $result = $this->shiftService->checkOutUser($shiftUser, $request->input('date'), $request->input('time'));
        $data = $result['data'] ?? null;

        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $data);
        }

        return ResponseHelper::success($data);
    }

    public function updateSalaryUser(
        ShiftUser $shiftUser,
        UpdateSalaryRequest $request,
        UpdateUserSalaryAction $action,
        AssertShiftWorkplaceAccessAction $assertAccess
    ): JsonResponse {
        if (
            $error = $assertAccess(
                Auth::user(),
                $shiftUser,
                PermissionName::ShiftUpdateSalary->value,
                ErrorCode::Forbidden
            )
        ) {
            return ResponseHelper::error($error, 403);
        }

        return $action($shiftUser, $request->input('salary_per_hour'))->toResponse();
    }

    public function updateAllowOvertime(
        ShiftUser $shiftUser,
        UpdateAllowOvertimeRequest $request,
        UpdateAllowOvertimeAction $action,
        AssertShiftWorkplaceAccessAction $assertAccess
    ): JsonResponse {
        if (
            $error = $assertAccess(
                Auth::user(),
                $shiftUser,
                PermissionName::ShiftUpdateOvertime->value,
                ErrorCode::Forbidden
            )
        ) {
            return ResponseHelper::error($error, 403);
        }

        return $action($shiftUser, $request->input('is_overtime_allowed'))->toResponse();
    }

    public function getOvertimeEligibility(
        Shift $shift,
        OvertimeEligibilityRequest $request,
        CalculateOvertimeEligibilityAction $action
    ): JsonResponse {
        return $action(Auth::user(), $shift, (int) $request->input('overtime_minutes'))->toResponse();
    }

    public function getWeeklyWorkLoad(
        Shift $shift,
        WeeklyWorkLoadRequest $request,
        GetShiftWeeklyWorkLoadAction $action
    ): JsonResponse {
        return $action(Auth::user(), $shift, $request->validated('user_ids'))->toResponse();
    }

    public function bulkUpdateAllowOvertime(
        Shift $shift,
        BulkUpdateAllowOvertimeRequest $request,
        BulkUpdateAllowOvertimeAction $action
    ): JsonResponse {
        return $action(Auth::user(), $shift, $request->input('shift_user_ids', []))->toResponse();
    }
}
