<?php

namespace App\Services\Admin\PdfTemplate;

use App\Models\User;
use App\Models\UserInfo;
use Carbon\Carbon;

class DataSourceResolver
{
    public function resolve(string $key, User $user): string|bool
    {
        $sources = config('pdf_data_sources.sources', []);
        $source = collect($sources)->firstWhere('key', $key);

        if (!$source) {
            return '';
        }

        return match ($source['type']) {
            'model_field' => $this->resolveModelField($source['key'], $user),
            'fixed' => $source['value'] ?? '',
            'custom_function' => $this->resolveCustomFunction($source['key'], $user),
            default => '',
        };
    }

    public function resolveAll(array $keys, User $user): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->resolve($key, $user);
        }

        return $result;
    }

    public function getAll(): array
    {
        $sources = config('pdf_data_sources.sources', []);
        $translatedNames = __('pdf_data_sources.names');
        $translatedGroups = __('pdf_data_sources.groups');

        return collect($sources)
            ->map(
                fn($source) => [
                    'key' => $source['key'],
                    'name' => $translatedNames[$source['key']] ?? $source['name'],
                    'placeholder' => $source['placeholder'],
                    'type' => $source['type'],
                    'group' => $translatedGroups[$source['group']] ?? $source['group'],
                    'is_reversible' => $source['is_reversible'] ?? false,
                ]
            )
            ->values()
            ->toArray();
    }

    protected function resolveModelField(string $key, User $user): string
    {
        $parts = explode('.', $key, 2);
        $model = $parts[0];
        $field = $parts[1] ?? null;

        if (!$field) {
            return '';
        }

        if ($model === 'user') {
            if ($field === 'name') {
                return (string) $user->short_name;
            }

            return (string) data_get($user, $field, '');
        }

        if ($model === 'user_info') {
            $userInfo = $user->info;
            if (!$userInfo) {
                return '';
            }

            return (string) data_get($userInfo, $field, '');
        }

        return '';
    }

    protected function resolveCustomFunction(string $key, User $user): string|bool
    {
        $userInfo = $user->info;

        return match ($key) {
            'is_era_meiji',
            'is_era_taisho',
            'is_era_showa',
            'is_era_heisei',
            'is_era_reiwa'
                => $this->resolveJapaneseEra($key, $userInfo),
            'is_household_head' => $userInfo?->is_household_head ?? false,
            'user.latest_residence_number' => $this->getLatestResidenceNumber($userInfo),
            'user.postal_code_first_3' => $this->getPostalCodeFirst3($userInfo),
            'user.postal_code_last_4' => $this->getPostalCodeLast4($userInfo),
            'user.full_address' => $this->getFullAddress($userInfo),
            'user.full_address_without_postcode' => $this->getFullAddressWithoutPostcode($userInfo),
            'user_info.birth_year' => $this->getBirthYear($userInfo),
            'user_info.birth_month' => $this->getBirthMonth($userInfo),
            'user_info.birth_date' => $this->getBirthDate($userInfo),
            'current_date_ymd' => Carbon::now()->format('Y/m/d'),
            default => '',
        };
    }

    protected function resolveJapaneseEra(string $key, ?UserInfo $userInfo): bool
    {
        if (!$userInfo?->birthday) {
            return false;
        }

        $birthday = Carbon::parse($userInfo->birthday);
        $era = match (true) {
            $birthday->lt(Carbon::create(1912, 7, 30)) => 'meiji',
            $birthday->lt(Carbon::create(1926, 12, 25)) => 'taisho',
            $birthday->lt(Carbon::create(1989, 1, 8)) => 'showa',
            $birthday->lt(Carbon::create(2019, 5, 1)) => 'heisei',
            default => 'reiwa',
        };

        return $key === "is_era_{$era}";
    }

    protected function getLatestResidenceNumber(?UserInfo $userInfo): string
    {
        if (!$userInfo) {
            return '';
        }

        return $userInfo->residence_number_3 ?: $userInfo->residence_number_2 ?: $userInfo->residence_number_1 ?: '';
    }

    protected function getPostalCodeFirst3(?UserInfo $userInfo): string
    {
        if (!$userInfo?->postal_code) {
            return '';
        }

        return mb_substr($userInfo->postal_code, 0, 3);
    }

    protected function getPostalCodeLast4(?UserInfo $userInfo): string
    {
        if (!$userInfo?->postal_code) {
            return '';
        }

        return mb_substr($userInfo->postal_code, -4);
    }

    protected function getBirthYear(?UserInfo $userInfo): string
    {
        if (!$userInfo?->birthday) {
            return '';
        }

        return Carbon::parse($userInfo->birthday)->format('Y');
    }

    protected function getBirthMonth(?UserInfo $userInfo): string
    {
        if (!$userInfo?->birthday) {
            return '';
        }

        return Carbon::parse($userInfo->birthday)->format('m');
    }

    protected function getBirthDate(?UserInfo $userInfo): string
    {
        if (!$userInfo?->birthday) {
            return '';
        }

        return Carbon::parse($userInfo->birthday)->format('d');
    }

    protected function getFullAddress(?UserInfo $userInfo): string
    {
        if (!$userInfo) {
            return '';
        }

        $parts = array_filter([$userInfo->postal_code, $userInfo->prefecture, $userInfo->address]);

        return implode(' ', $parts);
    }

    protected function getFullAddressWithoutPostcode(?UserInfo $userInfo): string
    {
        if (!$userInfo) {
            return '';
        }

        $parts = array_filter([$userInfo->prefecture, $userInfo->address]);

        return implode(' ', $parts);
    }
}
