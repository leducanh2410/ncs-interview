<?php

namespace App\Actions\Admin\Manage;

use App\Actions\ActionResult;
use App\Actions\Common\CheckIn\CoreCheckInAction;
use Illuminate\Support\Facades\Auth;

readonly class CheckInAction
{
    public function __construct(private CoreCheckInAction $core) {}

    public function __invoke(array $data): ActionResult
    {
        return ($this->core)($data, $data['user_id'], false, Auth::id());
    }
}
