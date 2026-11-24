<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\Locale;
use App\Enums\Nationality;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Mail\EmployeeCreatedEmail;
use App\Models\TempUser;
use App\Models\User;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CreateEmployeeAction
{
    public function __invoke(string $key, array $data, #[\SensitiveParameter] string $plainPassword): ActionResult
    {
        $cacheData = Cache::get($key);
        if (!isset($cacheData) || !Arr::get($cacheData, 'is_verified')) {
            return new ActionResult(error: ErrorCode::RegisterInvalidSession);
        }

        $tempUser = TempUser::query()->where('email', $cacheData['email'])->first();
        if (!isset($tempUser)) {
            return new ActionResult(error: ErrorCode::RegisterTempUserNotFound, status: 400);
        }

        if (!Hash::check($plainPassword, $tempUser->password)) {
            return new ActionResult(error: ErrorCode::BadRequest, status: 400);
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
                $userData['paper_status'] = UserPaperStatus::Registered->value;

                $crossStaffId = Arr::get($cacheData, 'user.cross_staff_id');
                if ($staffNo) {
                    $userData['staff_no'] = $staffNo;
                    $userData['cross_staff_id'] = $crossStaffId;
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
                // instance; refresh so the in-memory $user (used for the created
                // email below and the response) reflects it.
                $user->refresh();

                // Set user locale based on nationality
                $nationality = $userInfoData['nationality'] ?? null;
                $locale = match ($nationality) {
                    Nationality::Vietnam->value => Locale::Vietnamese->value,
                    Nationality::Nepal->value => Locale::Nepali->value,
                    Nationality::SriLanka->value => Locale::Sinhala->value,
                    Nationality::Other->value => Locale::English->value,
                    default => Locale::English->value,
                };
                $user->settings = array_merge($user->settings ?? [], ['lang' => $locale]);
                $user->save();
                $user->paper()->create();
                $user->addRole(RoleName::User);

                $tempUser->delete();

                return $user;
            });

            Cache::forget($key);

            try {
                Mail::to($user->email)->queue(new EmployeeCreatedEmail($user, $plainPassword));
            } catch (Exception $exception) {
                Log::error('Failed to queue employee created email', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'exception' => $exception->getMessage(),
                ]);
            }

            return new ActionResult(data: $user);
        } catch (Exception $exception) {
            Log::error('Failed to create employee', [
                'key' => $key,
                'data' => $data,
                'cache_data' => $cacheData,
                'exception' => $exception->getMessage(),
            ]);
        }

        return new ActionResult(error: ErrorCode::InternalServerError);
    }
}
