<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\GetMasterDataAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Master\SearchUserRequest;
use App\Services\Admin\MasterDataService;
use Illuminate\Http\JsonResponse;

class MasterDataController extends Controller
{
    private readonly MasterDataService $masterDataService;

    public function __construct(MasterDataService $masterDataService)
    {
        $this->masterDataService = $masterDataService;
    }

    public function searchUser(SearchUserRequest $request): JsonResponse
    {
        $conditions = $request->validated();

        if (isset($conditions['staff_no'])) {
            $conditions['exactStaffNo'] = $conditions['staff_no'];
            unset($conditions['staff_no'], $conditions['q']);
        } elseif (isset($conditions['key'])) {
            // Lookup by id, used only to resolve the label of a value the user
            // already picked and saved. Clients must not send display filters
            // (permission, workplace_id, ...) with it: a value that no longer
            // matches them would resolve to nothing and the picker would fall
            // back to showing a raw id instead of a name.
            if (is_array($conditions['key'])) {
                $conditions['ids'] = $conditions['key'];
            } else {
                $conditions['id'] = $conditions['key'];
            }
            unset($conditions['key']);
        } else {
            $conditions['query'] = $conditions['q'];
            unset($conditions['q']);
        }

        if (!isset($conditions['id']) && !isset($conditions['ids'])) {
            $conditions['is_disabled'] = false;
        }

        $users = $this->masterDataService->searchUser($conditions);

        return ResponseHelper::success($users);
    }

    public function getAll(GetMasterDataAction $action): JsonResponse
    {
        $result = $action(auth()->user());

        return $result->toResponse();
    }

    public function getRoles(): JsonResponse
    {
        return ResponseHelper::success($this->masterDataService->getAllRoles());
    }
}
