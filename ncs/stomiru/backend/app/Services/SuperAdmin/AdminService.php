<?php

namespace App\Services\SuperAdmin;

use App\Enums\RoleName;
use App\Mail\NotifyDeleteMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AdminService
{
    public function delete(User $user)
    {
        $companyId = $user->company_id;
        $deletedLoginId = $user->login_id;
        $deletedUserName = $user->name;
        $deletedUserEmail = $user->email;
        $completionDate = Carbon::now()->format('Y/m/d');

        $adminsToNotify = User::where('company_id', $companyId)
            ->whereHasRole(RoleName::Admin->value)
            ->where('id', '!=', $user->id)
            ->get();

        DB::transaction(function () use ($user) {
            $user->delete();
        });

        try {
            if ($deletedUserEmail) {
                Mail::to($deletedUserEmail)->send(new NotifyDeleteMail(
                    userName: $deletedUserName,
                    completionDate: $completionDate,
                    loginId: $deletedLoginId,
                ));
            }

            foreach ($adminsToNotify as $admin) {
                if ($admin->email) {
                    Mail::to($admin->email)->send(new NotifyDeleteMail(
                        userName: $admin->name,
                        completionDate: $completionDate,
                        loginId: $deletedLoginId,
                    ));
                }
            }
        } catch (\Exception $e) {
            report($e);
        }
    }
}
