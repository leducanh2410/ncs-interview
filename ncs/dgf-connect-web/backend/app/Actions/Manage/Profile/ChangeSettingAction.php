<?php

namespace App\Actions\Manage\Profile;

use App\Actions\ActionResult;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class ChangeSettingAction
{
    public function __invoke(array $data): ActionResult
    {
        /** @var User $user */
        $user = Auth::user();
        $oldSettings = $user->settings ?? [];
        $user->settings = array_merge($oldSettings, $data);
        $user->save();

        return new ActionResult(data: $user);
    }
}
