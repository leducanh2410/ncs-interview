<?php

namespace App\Actions\MonthlyReminder;

use App\Models\User;
use App\Models\UserConfig;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CheckActiveUserConfigsAction
{
    /**
     * Check for users with active custom shift/hour configs at the start of the month.
     *
     * @return Collection<int, array{user: User, active_configs: array<int, string>}>
     */
    public function __invoke(): Collection
    {
        $firstOfMonth = Carbon::now()->startOfMonth();
        $checkDate = $firstOfMonth->toDateString();

        $result = collect();

        UserConfig::query()
            ->whereHas('user')
            ->with('user')
            ->where(function (Builder $q) use ($checkDate) {
                $q->where(fn(Builder $s) => $this->scopeActive($s, 'max_shift_per_day', $checkDate))->orWhere(
                    fn(Builder $s) => $this->scopeActive($s, 'max_hour_per_week', $checkDate)
                );
            })
            ->lazy()
            ->each(function (UserConfig $userConfig) use ($firstOfMonth, $result) {
                $activeConfigs = [];

                if (
                    $this->isConfigActive(
                        $userConfig->max_shift_per_day_start_date,
                        $userConfig->max_shift_per_day_expiry_date,
                        $firstOfMonth
                    )
                ) {
                    $activeConfigs[] = 'max_shift_per_day';
                }

                if (
                    $this->isConfigActive(
                        $userConfig->max_hour_per_week_start_date,
                        $userConfig->max_hour_per_week_expiry_date,
                        $firstOfMonth
                    )
                ) {
                    $activeConfigs[] = 'max_hour_per_week';
                }

                if (!empty($activeConfigs)) {
                    $result->push([
                        'user' => $userConfig->user,
                        'active_configs' => $activeConfigs,
                    ]);
                }
            });

        return $result;
    }

    private function scopeActive(Builder $query, string $column, string $checkDate): void
    {
        $query
            ->where(
                fn(Builder $q) => $q
                    ->whereNull("{$column}_start_date")
                    ->orWhere("{$column}_start_date", '<=', $checkDate)
            )
            ->where(
                fn(Builder $q) => $q
                    ->whereNull("{$column}_expiry_date")
                    ->orWhere("{$column}_expiry_date", '>=', $checkDate)
            );
    }

    private function isConfigActive(?string $startDate, ?string $expiryDate, Carbon $checkDate): bool
    {
        if ($startDate === null && $expiryDate === null) {
            return true;
        }

        if ($startDate === null) {
            return Carbon::parse($expiryDate)->gte($checkDate);
        }

        if ($expiryDate === null) {
            return Carbon::parse($startDate)->lte($checkDate);
        }

        return Carbon::parse($startDate)->lte($checkDate) && Carbon::parse($expiryDate)->gte($checkDate);
    }
}
