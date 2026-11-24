<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\User;
use App\Services\External\CrossStaffApiService;

class SyncToCrossStaffAction
{
    private CrossStaffApiService $crossStaffApiService;

    public function __construct(CrossStaffApiService $crossStaffApiService)
    {
        $this->crossStaffApiService = $crossStaffApiService;
    }

    public function __invoke(User $user, ?callable $debug = null): ActionResult
    {
        $data = $this->crossStaffApiService->searchUser($user->email, $debug);
        if ($data) {
            $remoteCrossStaffId = $data['cross_staff_id'];

            if ($debug) {
                $debug('syncTo.branch', ['branch' => 'update', 'found_remote' => $data]);
            }

            if (!$user->cross_staff_id && $remoteCrossStaffId) {
                $user->update(['cross_staff_id' => $remoteCrossStaffId]);
            }

            $crossStaffId = $user->cross_staff_id ?? ($remoteCrossStaffId ?? (string) $user->id);

            $extraData = [];
            if (empty($data['staff_no']) && !empty($user->staff_no)) {
                $extraData['staffNo'] = $user->staff_no;
            }
            $error = $this->crossStaffApiService->updateUser($crossStaffId, $extraData, $user, $debug);
        } else {
            if ($debug) {
                $debug('syncTo.branch', ['branch' => 'create']);
            }
            $result = $this->crossStaffApiService->createUser($user, $user->staff_no, $debug);
            if ($result['cross_staff_id'] && !$user->cross_staff_id) {
                $user->update(['cross_staff_id' => $result['cross_staff_id']]);
            }
            $error = $result['error'];
        }

        if (!$error) {
            return new ActionResult(data: true);
        }

        return new ActionResult(error: ErrorCode::InternalServerError, status: 500, data: $error);
    }
}
