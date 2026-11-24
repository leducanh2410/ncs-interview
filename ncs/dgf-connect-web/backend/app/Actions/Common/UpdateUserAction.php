<?php

namespace App\Actions\Common;

use App\Actions\ActionResult;
use App\Models\User;

class UpdateUserAction
{
    public function __invoke(User $user, array $data): ActionResult
    {
        $user->update($data);

        return new ActionResult(data: $user);
    }
}
