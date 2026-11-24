<?php

namespace App\Observers;

use App\Actions\User\SyncShortNameAction;
use App\Models\User;
use App\Models\UserInfo;

class UserInfoShortNameObserver
{
    public function __construct(private readonly SyncShortNameAction $syncShortName) {}

    public function saved(UserInfo $userInfo): void
    {
        if (!$userInfo->wasRecentlyCreated && !$userInfo->wasChanged('nationality')) {
            return;
        }

        $this->sync($userInfo->user_id, $userInfo->nationality);
    }

    public function deleted(UserInfo $userInfo): void
    {
        $this->sync($userInfo->user_id, null);
    }

    private function sync(int $userId, ?string $nationality): void
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return;
        }

        ($this->syncShortName)($user, $nationality);
    }
}
