<?php

namespace App\Actions\Admin\User;

use App\Models\SystemConfig;
use App\Models\UserConfig;
use Illuminate\Contracts\Database\Eloquent\Builder;

class ResolveCustomUserConfigsAction
{
    public const KEYS = ['max_shift_per_day', 'max_hour_per_week'];

    /**
     * @param array<string, int> $defaults
     * @return array<int, array{key: string, value: int, default_value: int, start_date: ?string, expiry_date: ?string}>
     */
    public function __invoke(?UserConfig $userConfig, array $defaults): array
    {
        if ($userConfig === null) {
            return [];
        }

        $customConfigs = [];
        foreach ($defaults as $key => $defaultValue) {
            $value = $userConfig->{$key};
            if ($value === null || (int) $value === $defaultValue) {
                continue;
            }

            $customConfigs[] = [
                'key' => $key,
                'value' => (int) $value,
                'default_value' => $defaultValue,
                'start_date' => $userConfig->{"{$key}_start_date"},
                'expiry_date' => $userConfig->{"{$key}_expiry_date"},
            ];
        }

        return $customConfigs;
    }

    /**
     * @return array<string, int>
     */
    public function defaults(): array
    {
        $systemConfig = SystemConfig::query()->first();

        $defaults = [];
        foreach (self::KEYS as $key) {
            $defaults[$key] = (int) ($systemConfig?->{$key} ?? config("work.{$key}"));
        }

        return $defaults;
    }

    /**
     * @param array<string, int> $defaults
     */
    public function scopeDifferentFromDefaults(Builder $query, array $defaults): void
    {
        $query->where(function (Builder $q) use ($defaults) {
            foreach ($defaults as $key => $defaultValue) {
                $q->orWhere(fn(Builder $s) => $s->whereNotNull($key)->where($key, '!=', $defaultValue));
            }
        });
    }
}
