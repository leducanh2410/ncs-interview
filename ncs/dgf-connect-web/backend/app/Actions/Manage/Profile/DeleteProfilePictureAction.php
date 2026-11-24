<?php

namespace App\Actions\Manage\Profile;

use App\Actions\ActionResult;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DeleteProfilePictureAction
{
    public function __invoke(): ActionResult
    {
        /** @var User $user */
        $user = Auth::user();
        if ($user->profile_picture) {
            Storage::delete($user->profile_picture);
            $user->profile_picture = null;
            $user->save();
        }

        return new ActionResult(data: $user);
    }
}
