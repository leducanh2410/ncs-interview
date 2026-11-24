<?php

namespace App\Actions\Manage\Profile;

use App\Actions\ActionResult;
use App\Helpers\ImageHelper;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

class ChangeProfilePictureAction
{
    public function __invoke(UploadedFile $image): ActionResult
    {
        /** @var User $user */
        $user = Auth::user();
        $user->profile_picture = ImageHelper::handleImageUpload(
            $image,
            "users/{$user->id}",
            400,
            $user->profile_picture
        );
        $user->save();

        return new ActionResult(data: $user);
    }
}
