<?php

namespace App\Actions\Common;

use App\Actions\ActionResult;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ChangePasswordAction
{
    public function __invoke(string $password): ActionResult
    {
        /** @var User $user */
        $user = Auth::user();
        $user->password = Hash::make($password);
        $user->save();

        return new ActionResult(data: $user);
    }
}
