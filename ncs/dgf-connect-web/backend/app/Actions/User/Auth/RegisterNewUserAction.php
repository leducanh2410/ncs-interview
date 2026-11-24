<?php

namespace App\Actions\User\Auth;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Mail\RegistrationSuccessEmail;
use App\Models\TempUser;
use App\Models\User;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RegisterNewUserAction
{
    public function __invoke(string $key, array $data): ActionResult
    {
        $cacheData = Cache::get($key);
        if (!isset($cacheData) || !$cacheData['is_verified']) {
            return new ActionResult(error: ErrorCode::RegisterInvalidSession);
        }
        $tempUser = TempUser::query()->where('email', $cacheData['email'])->first();
        if (!isset($tempUser)) {
            return new ActionResult(error: ErrorCode::RegisterTempUserNotFound, status: 404);
        }

        $staffNo = Arr::get($cacheData, 'user.staff_no');
        if ($staffNo) {
            $user = User::query()->where('staff_no', $staffNo)->first();
            if ($user) {
                return new ActionResult(error: ErrorCode::RegisterStaffNoAlreadyExists, status: 400);
            }
        }

        try {
            $user = DB::transaction(function () use ($cacheData, $tempUser, $data, $staffNo) {
                $userFieldList = ['first_name', 'last_name', 'first_name_kana', 'last_name_kana', 'email'];
                $userData = array_merge(
                    Arr::only(Arr::get($cacheData, 'user') ?? [], $userFieldList),
                    array_filter(Arr::only($data, $userFieldList))
                );
                $userData['email'] = $tempUser->email;
                $userData['password'] = $tempUser->password;
                $crossStaffId = Arr::get($cacheData, 'user.cross_staff_id');
                if ($staffNo) {
                    $userData['staff_no'] = $staffNo;
                    $userData['cross_staff_id'] = $crossStaffId;
                    $userData['paper_status'] = UserPaperStatus::Approved;
                } else {
                    $userData['paper_status'] = UserPaperStatus::Registered;
                }
                $user = User::query()->create($userData);

                $userInfoData = array_merge(
                    Arr::get($cacheData, 'user.info', []),
                    array_filter(Arr::except($data, [...$userFieldList, 'key']), function ($value) {
                        return $value !== null;
                    })
                );
                $user->info()->create($userInfoData);
                // UserInfoShortNameObserver persists short_name via a separate User
                // instance; refresh so the in-memory $user (used for the welcome
                // email below and the response) reflects it.
                $user->refresh();

                $user->addRole(RoleName::User);

                if (!$crossStaffId) {
                    $user->paper()->create();
                }

                $tempUser->delete();

                Mail::to($user->email)->send(new RegistrationSuccessEmail($user->short_name));

                return $user;
            });

            Cache::forget($key);

            return new ActionResult(
                data: [
                    'error' => null,
                    'data' => $user,
                ]
            );
        } catch (Exception $exception) {
            Log::error('Failed to register new user', [
                'key' => $key,
                'data' => $data,
                'cache_data' => $cacheData,
                'exception' => $exception->getMessage(),
            ]);
        }

        return new ActionResult(error: ErrorCode::InternalServerError);
    }
}
