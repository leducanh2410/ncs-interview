<?php

namespace App\Services;

use App\Enums\ErrorCode;
use App\Mail\RegisterVerifyEmail;
use App\Models\AccessToken;
use App\Models\TempUser;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\Workplace;
use App\Services\External\CrossStaffApiService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\TwoFactorAuthException;

class AuthService
{
    private const OTP_EXPIRES_IN_MINUTES = 60;

    public function createToken(int $userId, int $expiresInHour = 1): AccessToken
    {
        return AccessToken::query()->create([
            'user_id' => $userId,
            'token' => Str::random(128),
            'expires_at' => Carbon::now()->addHours($expiresInHour),
        ]);
    }

    public function revokeToken(string $token): int
    {
        return AccessToken::query()->where('token', $token)->delete();
    }

    /**
     * @throws TwoFactorAuthException
     */
    public function createTfaSession(User $user, bool $isRemember): array
    {
        $tfa = new TwoFactorAuth(new EndroidQrCodeProvider());
        $key = 'tfa_key_' . Str::random(32);
        $values = ['user_id' => $user->id, 'remember' => $isRemember];
        $data = [
            'key' => $key,
        ];
        $secret = $user->tfa_secret;
        if (!$secret) {
            $secret = $tfa->createSecret();
            $values['secret'] = $secret;
            $data['qr'] = $tfa->getQRCodeImageAsDataUri(config('app.name') . "({$user->email})", $secret);
        }
        Cache::add($key, $values, 3600);

        return $data;
    }

    /**
     * Determine whether the given email is configured to bypass two-factor authentication.
     */
    public function shouldSkipTfa(?string $email): bool
    {
        if (!$email) {
            return false;
        }

        $skipEmails = config('auth.tfa.skip_emails', []);
        if (empty($skipEmails)) {
            return false;
        }

        return in_array(mb_strtolower($email), $skipEmails, true);
    }

    public function verifyTfa(string $key, string $code): array
    {
        $values = Cache::get($key);
        if (!$values) {
            return [
                'success' => false,
                'error' => ErrorCode::AuthTfaInvalidKey,
            ];
        }

        $user = User::query()->find($values['user_id']);
        if (!$user) {
            return [
                'error' => ErrorCode::AuthTfaInvalidKey,
            ];
        }

        if ($user->is_disabled) {
            return [
                'error' => ErrorCode::AuthUserDisabled,
            ];
        }

        $secret = $user->tfa_secret ?? ($values['secret'] ?? null);
        if (!$secret) {
            return [
                'error' => ErrorCode::AuthTfaInvalidKey,
            ];
        }

        $tfa = new TwoFactorAuth(new EndroidQrCodeProvider());
        $result = $tfa->verifyCode($secret, $code);
        if (!$result) {
            return [
                'error' => ErrorCode::AuthTfaInvalidCode,
            ];
        }
        if (!$user->tfa_secret) {
            $user->update(['tfa_secret' => $secret]);
        }

        Cache::forget($key);

        return [
            'user' => $user,
            'remember' => $values['remember'] ?? false,
        ];
    }

    public function storeUserDevice($userId, array $data): ?UserDevice
    {
        $fcmToken = Arr::get($data, 'fcm_token');
        if (!$fcmToken) {
            return null;
        }
        $device = UserDevice::query()->where('fcm_token', $data['fcm_token'])->first();
        if (!$device) {
            return UserDevice::query()->create([
                'user_id' => $userId,
                'device_id' => $data['device_id'],
                'name' => $data['device_name'],
                'platform' => $data['platform'],
                'fcm_token' => $data['fcm_token'],
            ]);
        }

        if ($device->user_id === $userId) {
            $device->update([
                'device_id' => $data['device_id'],
                'name' => $data['device_name'],
                'platform' => $data['platform'],
            ]);

            return $device;
        }

        // Remove fcm_token from the old user
        $device->update(['fcm_token' => null]);

        return UserDevice::query()->create([
            'user_id' => $userId,
            'device_id' => $data['device_id'],
            'name' => $data['device_name'],
            'platform' => $data['platform'],
            'fcm_token' => $data['fcm_token'],
        ]);
    }

    public function sendRegisterOtp(array $data): array
    {
        try {
            $otp = mt_rand(100000, 999999);
            $key = 'reg_' . Str::random();
            $values = [
                'otp' => $otp,
                'is_verified' => false,
                'email' => $data['email'],
            ];

            Cache::put($key, $values, now()->addMinutes(self::OTP_EXPIRES_IN_MINUTES));

            Mail::to($data['email'])->send(new RegisterVerifyEmail($otp, self::OTP_EXPIRES_IN_MINUTES));

            $deletedUser = User::findDisabledByOriginalEmail($data['email']);

            return [
                'status' => 'success',
                'key' => $key,
                'account_deleted' => $deletedUser !== null,
            ];
        } catch (Exception $exception) {
            Log::error('Fail sending otp mail', [$exception->getMessage()]);

            return [
                'error' => 'failed',
            ];
        }
    }

    public function verifyRegisterOtp(array $data): array
    {
        $cacheData = Cache::get($data['key']);
        if (!isset($cacheData)) {
            return [
                'error' => ErrorCode::RegisterInvalidSession,
            ];
        }

        if ((string) $cacheData['otp'] !== (string) $data['otp']) {
            return [
                'error' => ErrorCode::RegisterInvalidOtp,
            ];
        }

        $cacheData['is_verified'] = true;

        $apiService = new CrossStaffApiService();
        $cacheData['user'] = $apiService->searchUser($cacheData['email']);

        Cache::put($data['key'], $cacheData, now()->addDay());

        return [
            'error' => null,
            'data' => [
                'email' => $cacheData['email'],
                'user' => $cacheData['user'],
            ],
        ];
    }

    public function storeTempUser(array $data): array
    {
        $cacheData = Cache::get($data['key']);
        if (!isset($cacheData) || !$cacheData['is_verified']) {
            return [
                'error' => ErrorCode::RegisterInvalidSession,
            ];
        }

        $data['password'] = Hash::make($data['password']);
        unset($data['key']);

        TempUser::query()->updateOrCreate(['email' => $cacheData['email']], $data);

        return [
            'error' => null,
            'data' => true,
        ];
    }

    public function getUserMasterData(int $userId): array
    {
        $workplaces = Workplace::query()
            ->select('id', 'name')
            ->whereHas('users', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->get();

        return compact('workplaces');
    }

    public function updateUserLang(User $user, string $lang): void
    {
        $settings = $user->settings ?? [];
        $settings['lang'] = $lang;
        $user->update(['settings' => $settings]);
    }
}
