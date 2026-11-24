<?php

namespace App\Actions\Admin\Manage;

use App\Actions\ActionResult;
use App\Actions\Common\CheckIn\CoreCheckOutAction;
use Illuminate\Support\Facades\Auth;

readonly class CheckOutAction
{
    public function __construct(private CoreCheckOutAction $core) {}

    public function __invoke(array $data): ActionResult
    {
        return ($this->core)($data, $data['user_id'], false, Auth::id());
    }
}
