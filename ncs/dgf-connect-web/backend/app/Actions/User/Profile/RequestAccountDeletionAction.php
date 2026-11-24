<?php

namespace App\Actions\User\Profile;

use App\Actions\ActionResult;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestAccountDeletionAction
{
    public function __invoke(): ActionResult
    {
        DB::transaction(function () {
            $user = Auth::user();
            $userId = $user->id;
            Log::info("User {$userId} requested account deletion");
            $user->update(['is_disabled' => true, 'email' => User::deletedEmailFor($user->email)]);
            AccessToken::query()->where('user_id', $userId)->delete();
            UserDevice::query()->where('user_id', $userId)->delete();
        });

        return new ActionResult(status: Response::HTTP_NO_CONTENT, data: true);
    }
}
