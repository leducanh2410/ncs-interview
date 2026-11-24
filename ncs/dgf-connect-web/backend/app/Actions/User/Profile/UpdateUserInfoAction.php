<?php

namespace App\Actions\User\Profile;

use App\Actions\ActionResult;
use App\Enums\UserPaperStatus;
use App\Models\User;
use App\Services\External\CrossStaffApiService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateUserInfoAction
{
    public function __invoke(array $data): ActionResult
    {
        /** @var User $user */
        $user = Auth::user();
        $user = DB::transaction(function () use ($user, $data) {
            $user->info?->fill($data)->save();
            // UserInfoShortNameObserver persists short_name via a separate User
            // instance; refresh so the in-memory $user (returned below) reflects it.
            $user->refresh();
            if ($user->paper_status === UserPaperStatus::Registered->value) {
                $user->update(['paper_status' => UserPaperStatus::Pending]);
            }

            return $user;
        });

        if (!$user->cross_staff_id) {
            $apiService = new CrossStaffApiService();
            $user->load('info');
            $result = $apiService->createUser($user);
            if ($result['cross_staff_id']) {
                $user->update(['cross_staff_id' => $result['cross_staff_id']]);
            }
            if ($result['error']) {
                Log::error('Failed to create user in CrossStaff during profile update', [
                    'user_id' => $user->id,
                    'error' => $result['error'],
                ]);
            }
        }

        return new ActionResult(data: $user->toArray());
    }
}
