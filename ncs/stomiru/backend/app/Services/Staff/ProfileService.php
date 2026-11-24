<?php

namespace App\Services\Staff;

use App\Enums\LogAction;
use App\Enums\LogCategory;
use App\Models\User;
use App\Services\SuperAdmin\LogService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProfileService
{
    protected $logService;

    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            // Update user table fields
            $userData = [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'] ?? null,
            ];

            if (! empty($data['password'])) {
                $userData['password'] = Hash::make($data['password']);
            }

            $user->fill($userData);
            $isNameChanged = $user->isDirty(['first_name', 'last_name']);
            $user->save();

            // Update or create user profile
            $userProfile = $user->userProfile()->firstOrNew(['user_id' => $user->id]);
            $userProfile->fill([
                'gender' => $data['gender'],
                'birthday' => Carbon::createFromFormat('Ymd', $data['birthday'])->format('Y-m-d'),
                'position' => $data['position'] ?? null,
                'department' => $data['department'] ?? null,
            ]);

            $isDobChanged = $userProfile->isDirty('birthday');
            $userProfile->save();

            if ($isNameChanged) {
                $this->logService->create([
                    'category' => LogCategory::EMPLOYEE_MANAGEMENT->value,
                    'action' => LogAction::NAME_UPDATE->value,
                    'target_id' => $user->employee_code,
                    'company_id' => $user->company_id,
                ]);
            }

            if ($isDobChanged) {
                $this->logService->create([
                    'category' => LogCategory::EMPLOYEE_MANAGEMENT->value,
                    'action' => LogAction::DOB_UPDATE->value,
                    'target_id' => $user->employee_code,
                    'company_id' => $user->company_id,
                ]);
            }

            return $user->load('userProfile');
        });
    }

    public function show(User $user): User
    {
        return $user->load('userProfile');
    }
}
