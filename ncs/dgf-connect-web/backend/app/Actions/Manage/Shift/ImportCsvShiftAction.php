<?php

namespace App\Actions\Manage\Shift;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\Shift;
use App\Models\User;
use App\Services\Common\AbsenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ImportCsvShiftAction
{
    use ValidatesShiftUser;

    public function __construct(private readonly AbsenceService $absenceService) {}

    public function __invoke(User $user, Shift $shift, Collection $dataRows): ActionResult
    {
        // Validate workplace permission
        if (!$user->isAbleTo(PermissionName::ShiftImport->value, $shift->workplace_id)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $shiftDate = Carbon::parse($shift->date);
        if ($dataRows->count() == 0) {
            Log::error('CSV file has less than 1 data rows for shift ' . $shift->id);

            return new ActionResult(error: ErrorCode::ShiftCsvEmpty, status: 400);
        }

        $dataRows = $dataRows->slice(1)->values();

        $workplacePositions = $shift->workplace->positions;
        $workplacePositionNameToIdMap = $workplacePositions->mapWithKeys(
            fn($position) => [$position->name => $position->id]
        );

        $csvStaffNos = $this->getStaffNosFromCsv($dataRows);
        if (count($csvStaffNos) == 0) {
            Log::error('CSV file has no staff numbers for shift ' . $shift->id);

            return new ActionResult(error: ErrorCode::ShiftCsvEmpty, status: 400);
        }

        $usersInWorkplace = $shift->workplace->users()->whereIn('staff_no', $csvStaffNos)->get();
        $userStaffNoToIdMap = $usersInWorkplace->mapWithKeys(fn($user) => [$user->staff_no => $user->id]);
        $userStaffNoToNameMap = $usersInWorkplace->mapWithKeys(fn($user) => [$user->staff_no => $user->short_name]);
        $userIds = $userStaffNoToIdMap->values()->all();

        $errorData = [];
        $userToWorkplacePositionIdMapForValidData = [];

        $workHourValidationErrors = $this->validateShiftUserWorkHours($shift, $userIds, $shiftDate);

        $approvedUsers = User::query()->with('info')->whereIn('id', $userIds)->get();
        $expiredUserInfoError = [];

        foreach ($approvedUsers as $user) {
            $validateUserError = $this->checkUserExpiration($user, $shiftDate);
            if ($validateUserError) {
                $expiredUserInfoError[$user->id] = $validateUserError;
            }
        }

        foreach ($dataRows as $index => $rowData) {
            $workplacePositionName = $rowData[0] ?? null;
            $staffNo = $rowData[1] ?? null;

            if (!isset($userStaffNoToIdMap[$staffNo])) {
                $errorData[] = [
                    'staff_no' => $staffNo,
                    'user_name' => null,
                    'error_code' => ErrorCode::ShiftCsvInvalidStaffNo,
                ];

                continue;
            }

            if (!isset($workplacePositionNameToIdMap[$workplacePositionName])) {
                $errorData[] = [
                    'staff_no' => $staffNo,
                    'user_name' => $userStaffNoToNameMap[$staffNo],
                    'error_code' => ErrorCode::ShiftCsvInvalidWorkplacePosition,
                ];

                continue;
            }

            $userId = $userStaffNoToIdMap[$staffNo];

            if (isset($expiredUserInfoError[$userId])) {
                $errorData[] = [
                    'staff_no' => $staffNo,
                    'user_name' => $userStaffNoToNameMap[$staffNo],
                    'error_code' => $expiredUserInfoError[$userId],
                ];

                continue;
            }

            if (isset($workHourValidationErrors[$userId])) {
                $errorData[] = [
                    'staff_no' => $staffNo,
                    'user_name' => $userStaffNoToNameMap[$staffNo],
                    'error_code' => $workHourValidationErrors[$userId]['error'],
                ];

                continue;
            }

            $currentWorkplacePositionId = $workplacePositionNameToIdMap[$workplacePositionName];
            $userToWorkplacePositionIdMapForValidData[$userId] = $currentWorkplacePositionId;
        }

        $validData = [];
        foreach ($userToWorkplacePositionIdMapForValidData as $userId => $workplacePositionId) {
            $validData[$workplacePositionId][] = $userId;
        }
        foreach ($validData as $positionId => $uids) {
            $validData[$positionId] = array_values($uids);
        }

        $absenceCounts = $this->absenceService->getUserAbsenceCount($userIds);
        $usersInWorkplace->each(fn($u) => $u->setAttribute('absence_count', $absenceCounts[$u->id] ?? 0));
        $usersMap = $usersInWorkplace->mapWithKeys(fn($user) => [$user->id => $user]);

        return new ActionResult(
            data: [
                'error_data' => $errorData,
                'valid_data' => $validData,
                'users_in_workplace' => $usersMap,
            ]
        );
    }

    private function getStaffNosFromCsv(Collection $dataRows): array
    {
        return $dataRows->pluck(1)->filter()->unique()->values()->all();
    }
}
