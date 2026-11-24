<?php

namespace App\Actions\Chat\Support;

use App\Actions\ActionResult;
use App\Models\SupportTypeAdmin;

class RemoveSupportTypeAdminAction
{
    public function __invoke(string $supportType, int $userId): ActionResult
    {
        SupportTypeAdmin::where('support_type', $supportType)->where('user_id', $userId)->delete();

        return new ActionResult();
    }
}
