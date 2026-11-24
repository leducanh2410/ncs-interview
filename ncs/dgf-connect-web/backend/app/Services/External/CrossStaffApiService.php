<?php

namespace App\Services\External;

use App\Enums\Gender;
use App\Enums\Nationality;
use App\Enums\ResidenceStatus;
use App\Models\User;
use App\Support\Residence\ResidenceSlotResolver;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

readonly class CrossStaffApiService
{
    /**
     * Bounds of the MySQL DATE type (`1000-01-01` to `9999-12-31`).
     */
    private const MIN_DATE_YEAR = 1000;

    private const MAX_DATE_YEAR = 9999;

    private PendingRequest $client;

    public function __construct()
    {
        $config = config('services.cross_staff');
        $this->client = Http::baseUrl($config['base_url'])
            ->timeout(60)
            ->retry(
                3,
                1000,
                function (Exception $exception) {
                    return $exception instanceof ConnectionException;
                },
                false
            );
    }

    public function searchUser(string $email, ?callable $debug = null): ?array
    {
        $params = [
            'tenantCd' => config('services.cross_staff.tenant_cd'),
            'externalCd' => config('services.cross_staff.external_cd'),
            'pcMail' => $email,
            'returnLimit' => 1,
        ];
        $signing = $this->createSigning($params);
        $params['signing'] = $signing;

        if ($debug) {
            $debug('searchUser.request', [
                'endpoint' => 'getMemberListExternal',
                'method' => 'GET',
                'params' => $params,
            ]);
        }

        $response = $this->client->get('getMemberListExternal', $params);

        if ($debug) {
            $debug('searchUser.response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        if (!$response->successful()) {
            Log::error('Failed to search user from Cross Staff API', [
                'email' => $email,
                'response' => $response->body(),
                'status' => $response->status(),
            ]);

            return null;
        }

        $data = $response->json();

        $error = Arr::get($data, 'GetMemberListExternal.ResultSet.Error');
        if ($error) {
            Log::error('Failed to search user from Cross Staff API', [
                'email' => $email,
                'error' => $error,
            ]);

            return null;
        }

        $total = Arr::get($data, 'GetMemberListExternal.ResultSet.TotalResult', 0);
        if (!$total) {
            return null;
        }
        $result = Arr::get($data, 'GetMemberListExternal.ResultSet.Result');
        $customAttributes = Arr::get($result, 'CustomAttribute', []);

        $data = [
            'last_name' => Arr::get($result, 'LastName'),
            'first_name' => Arr::get($result, 'FirstName'),
            'last_name_kana' => Arr::get($result, 'LastNameKana'),
            'first_name_kana' => Arr::get($result, 'FirstNameKana'),
            'email' => Arr::get($result, 'PcMail'),
            'staff_no' => Arr::get($result, 'StaffNo'),
            'cross_staff_id' => Arr::get($result, 'ExternalMemberId.' . config('services.cross_staff.external_cd')),
            'info' => [
                'registered_location' => Arr::get($result, 'RegistrationBaseName'),
                'birthday' => $this->normalizeDate(Arr::get($result, 'Birthday')),
                'gender' => Gender::fromCrossStaffValue(Arr::get($result, 'Sex')),
                'nationality' => Nationality::fromCrossStaffString(
                    $this->findCustomAttributeValue($customAttributes, '国籍')
                ),
                'phone' => Arr::get($result, 'Tel'),
                'postal_code' => Arr::get($result, 'Postcode'),
                'prefecture' => Arr::get($result, 'PrefName'),
                'japanese_level' => null,
                'residence_number_1' => $this->findCustomAttributeValue($customAttributes, '在留カード番号'),
                'residence_expiry_date_1' => $this->normalizeDate(
                    $this->findCustomAttributeValue($customAttributes, '在留期限')
                ),
                'residence_status_1' => ResidenceStatus::fromCrossStaffString(
                    $this->findCustomAttributeValue($customAttributes, '在留資格')
                ),
                'passport_number' => $this->findCustomAttributeValue($customAttributes, 'パスポート番号'),
                'passport_expiry_date' => $this->normalizeDate(
                    $this->findCustomAttributeValue($customAttributes, 'パスポート期限')
                ),
            ],
        ];

        $city = Arr::get($result, 'City');
        $town = Arr::get($result, 'Town');
        $address = Arr::get($result, 'Address');
        $building = Arr::get($result, 'Building');
        $data['info']['address'] = mb_trim(implode(' ', array_filter([$city, $town, $address, $building])));

        if ($debug) {
            $debug('searchUser.parsed', ['data' => $data]);
        }

        return $data;
    }

    public function createUser(User $user, int|string|null $staffNo = null, ?callable $debug = null): array
    {
        $data = $this->convertUserData($user);

        if ($staffNo) {
            $data['staffNo'] = $staffNo;
        }

        $params = [
            'tenantCd' => config('services.cross_staff.tenant_cd'),
            'externalCd' => config('services.cross_staff.external_cd'),
        ];
        $signing = $this->createSigning($params);
        $params['signing'] = $signing;

        if ($debug) {
            $debug('createUser.request', [
                'endpoint' => 'insMemberExternal',
                'method' => 'POST',
                'params' => $params,
                'payload' => $data,
            ]);
        }

        $response = $this->client->asForm()->withQueryParameters($params)->post('insMemberExternal', $data);

        if ($debug) {
            $debug('createUser.response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        if (!$response->successful()) {
            Log::error('Failed to create user in Cross Staff API', [
                'user' => $user->id,
                'response' => $response->body(),
                'status' => $response->status(),
            ]);

            return ['error' => 'HTTP Error. Status: ' . $response->status(), 'cross_staff_id' => null];
        }

        $respData = $response->json();

        $error = Arr::get($respData, 'InsMemberExternal.ResultSet.Error');
        if (!$error) {
            return ['error' => null, 'cross_staff_id' => (string) $user->id];
        }

        Log::error('Failed to create user in Cross Staff API', [
            'user' => $user->id,
            'error' => $error,
        ]);

        if (array_is_list($error)) {
            $errorMsg = Arr::get($error, '0.mes');
        } else {
            $errorMsg = Arr::get($error, 'mes');
        }

        if ($errorMsg) {
            return ['error' => $errorMsg, 'cross_staff_id' => null];
        }

        return ['error' => 'Unknown error', 'cross_staff_id' => null];
    }

    public function updateUser(string $crossStaffId, array $data, ?User $user = null, ?callable $debug = null): ?string
    {
        if ($user) {
            $data = array_merge($data, $this->convertUserData($user));
        }

        $params = [
            'tenantCd' => config('services.cross_staff.tenant_cd'),
            'externalCd' => config('services.cross_staff.external_cd'),
        ];
        $signing = $this->createSigning($params);
        $params['signing'] = $signing;

        $data['externalMemberId'] = $crossStaffId;

        if ($debug) {
            $debug('updateUser.request', [
                'endpoint' => 'updMemberExternal',
                'method' => 'POST',
                'params' => $params,
                'payload' => $data,
            ]);
        }

        $response = $this->client->asForm()->withQueryParameters($params)->post('updMemberExternal', $data);

        if ($debug) {
            $debug('updateUser.response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        if (!$response->successful()) {
            Log::error('Failed to update user in Cross Staff API', [
                'cross_staff_id' => $crossStaffId,
                'response' => $response->body(),
                'status' => $response->status(),
                'data' => $data,
            ]);

            return 'HTTP Error. Status: ' . $response->status();
        }

        $respData = $response->json();
        $error = Arr::get($respData, 'UpdMemberExternal.ResultSet.Error');
        if (!$error) {
            return null;
        }

        Log::error('Failed to update user in Cross Staff API', [
            'cross_staff_id' => $crossStaffId,
            'user_id' => $user->id ?? null,
            'error' => $error,
        ]);

        if (array_is_list($error)) {
            $errorMsg = Arr::get($error, '0.mes');
        } else {
            $errorMsg = Arr::get($error, 'mes');
        }

        if ($errorMsg) {
            return $errorMsg;
        }

        return 'Unknown error';
    }

    private function createSigning(array $params): string
    {
        $apiKey = config('services.cross_staff.api_key');
        $query = implode(
            '&',
            array_map(
                function ($value, $key) {
                    return $key . '=' . $value;
                },
                $params,
                array_keys($params)
            )
        );

        return md5($query . $apiKey);
    }

    private function convertUserData(User $user): array
    {
        $latestResidenceSlot = ResidenceSlotResolver::latestSlot($user->info);
        $residenceNumber = $latestResidenceSlot ? $user->info->{"residence_number_{$latestResidenceSlot}"} : null;
        $residenceExpiryDate = $latestResidenceSlot
            ? $user->info->{"residence_expiry_date_{$latestResidenceSlot}"}
            : null;
        $residenceStatus = $latestResidenceSlot ? $user->info->{"residence_status_{$latestResidenceSlot}"} : null;

        return [
            'externalMemberId' => $user->id,
            'lastName' => $this->convertString($user->last_name),
            'firstName' => $this->convertString($user->first_name),
            'lastNameKana' => $this->convertString($user->last_name_kana),
            'firstNameKana' => $this->convertString($user->first_name_kana),
            'memberSts' => '2',
            'sex' => Gender::toCrossStaffValue($user->info->gender),
            'birthday' => $user->info->birthday ? Carbon::parse($user->info->birthday)->format('Y/m/d') : null,
            'postcode' => $user->info->postal_code ? str_replace('-', '', $user->info->postal_code) : null,
            'prefName' => $user->info->prefecture ? $this->convertString($user->info->prefecture) : null,
            'address' => $user->info->address ? $this->convertString($user->info->address) : null,
            'tel' => $user->info->phone,
            'pcMail' => $user->email,
            'customAttribute2' => $residenceNumber,
            'customAttribute3' => $residenceExpiryDate ? Carbon::parse($residenceExpiryDate)->format('Y/m/d') : null,
            'customAttribute4' => $this->convertString(ResidenceStatus::toCrossStaffString($residenceStatus)),
            'customAttribute5' => $user->info->passport_number,
            'customAttribute6' => $user->info->passport_expiry_date
                ? Carbon::parse($user->info->passport_expiry_date)->format('Y/m/d')
                : null,
            'customAttribute9' => $this->convertString(Nationality::toCrossStaffString($user->info->nationality)),
        ];
    }

    /**
     * Every date CrossStaff hands back lands in a DATE column, and MySQL in strict mode
     * refuses anything that is not a real date. A field the employee never filled in comes
     * through as an empty string (or an all-zero date), and `Carbon::parse()` turns those
     * into "now" and `-0001-11-30` respectively — neither is what was meant, and the second
     * one is outside the range a DATE column accepts, so the whole sync would fail.
     */
    private function normalizeDate(?string $value): ?string
    {
        $value = mb_trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (Throwable) {
            Log::warning('Ignoring unparseable date from Cross Staff API', ['value' => $value]);

            return null;
        }

        if ($date->year < self::MIN_DATE_YEAR || $date->year > self::MAX_DATE_YEAR) {
            Log::warning('Ignoring out-of-range date from Cross Staff API', ['value' => $value]);

            return null;
        }

        return $date->format('Y-m-d');
    }

    private function findCustomAttributeValue(array $attributes, string $key): ?string
    {
        foreach ($attributes as $attribute) {
            if ($attribute['NAME'] === $key) {
                return $attribute['VALUE'];
            }
        }

        return null;
    }

    private function convertString(?string $value): string
    {
        if (empty($value)) {
            return $value;
        }

        return mb_convert_encoding($value, 'Windows-31J', 'UTF-8');
    }
}
