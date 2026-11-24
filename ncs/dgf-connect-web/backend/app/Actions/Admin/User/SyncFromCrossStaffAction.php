<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\User;
use App\Services\External\CrossStaffApiService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SyncFromCrossStaffAction
{
    private CrossStaffApiService $crossStaffApiService;

    public function __construct(CrossStaffApiService $crossStaffApiService)
    {
        $this->crossStaffApiService = $crossStaffApiService;
    }

    public function __invoke(User $user, ?callable $debug = null): ActionResult
    {
        $data = $this->crossStaffApiService->searchUser($user->email, $debug);

        if (!$data) {
            if ($debug) {
                $debug('syncFrom.notFound', ['email' => $user->email]);
            }

            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        DB::transaction(function () use ($user, $data, $debug) {
            $info = Arr::get($data, 'info');
            unset($data['info'], $data['staff_no']);

            if ($debug) {
                $debug('syncFrom.dbUpdate', [
                    'user_update' => $data,
                    'info_update' => $info,
                ]);
            }

            $user->update($data);
            if ($info) {
                $user->info?->fill($info)->save();
            }
        });

        return new ActionResult(data: true);
    }
}
