<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\UserPaperStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateManagerAction
{
    public function __invoke(array $data, string $role): ActionResult
    {
        return DB::transaction(function () use ($data, $role) {
            $data['password'] = Hash::make($data['password']);
            $data['paper_status'] = UserPaperStatus::Approved->value;
            $user = User::query()->create($data);
            $user->addRole($role);

            return new ActionResult(data: $user);
        });
    }
}
