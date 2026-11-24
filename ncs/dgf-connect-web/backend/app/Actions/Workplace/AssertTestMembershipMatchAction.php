<?php

namespace App\Actions\Workplace;

use App\Models\User;
use App\Models\Workplace;
use Illuminate\Support\Collection;

/**
 * Guards the test-data invariant: a workplace membership may only exist when the
 * user's is_test flag equals the workplace's is_test flag. Test employees join
 * only test workplaces; normal employees join only normal workplaces.
 */
class AssertTestMembershipMatchAction
{
    /**
     * Users and workplaces about to be linked together must all share the same
     * is_test value. Returns true when consistent, false on a test/non-test mix.
     */
    public function __invoke(array $userIds, array $workplaceIds): bool
    {
        $flags = $this->userFlags($userIds)->merge($this->workplaceFlags($workplaceIds));

        return $flags->unique()->count() <= 1;
    }

    /**
     * Check a (possibly not-yet-persisted) is_test value against existing workplaces.
     */
    public function flagMatchesWorkplaces(bool $isTest, array $workplaceIds): bool
    {
        return $this->workplaceFlags($workplaceIds)->every(fn(bool $flag) => $flag === $isTest);
    }

    /**
     * Check a (possibly not-yet-persisted) is_test value against existing users.
     */
    public function flagMatchesUsers(bool $isTest, array $userIds): bool
    {
        return $this->userFlags($userIds)->every(fn(bool $flag) => $flag === $isTest);
    }

    private function userFlags(array $userIds): Collection
    {
        if (empty($userIds)) {
            return collect();
        }

        return User::query()->whereIn('id', $userIds)->pluck('is_test')->map(fn($value) => (bool) $value);
    }

    private function workplaceFlags(array $workplaceIds): Collection
    {
        if (empty($workplaceIds)) {
            return collect();
        }

        return Workplace::query()->whereIn('id', $workplaceIds)->pluck('is_test')->map(fn($value) => (bool) $value);
    }
}
