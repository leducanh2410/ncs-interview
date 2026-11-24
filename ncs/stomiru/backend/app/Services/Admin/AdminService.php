<?php

namespace App\Services\Admin;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Mail\AdminRequestDeleteMail;
use App\Mail\InvitationMail;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\Company;
use App\Mail\ResetPasswordMail;

class AdminService
{
    public function listAdmins(array $conditions): LengthAwarePaginator
    {
        $conditions['roles'] = [RoleName::Admin->value, RoleName::Doctor->value];

        /** @var User $currentUser */
        $currentUser = Auth::user();

        $customSort = null;
        $customDirection = null;
        $orderBy = null;

        if (! empty($conditions['sort'])) {
            $sort = $conditions['sort'];
            $sortField = str_starts_with($sort, '-') ? substr($sort, 1) : $sort;

            $customFields = ['name', 'role'];

            if (in_array($sortField, $customFields)) {
                $customSort = $sortField;
                $customDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';
            } else {
                $orderBy = $sort;
            }
        }

        $query = User::getFilteredQuery($conditions, ['roles'], $orderBy);
        $query->where('company_id', $currentUser->company_id);

        if ($customSort) {
            $query->reorder();
            if ($customSort === 'name') {
                $query->orderByRaw("CONCAT(users.first_name, ' ', users.last_name) {$customDirection}");
            } elseif ($customSort === 'role') {
                $query->leftJoin('role_user', function ($join) {
                    $join->on('users.id', '=', 'role_user.user_id')
                        ->where('role_user.user_type', User::class);
                })
                    ->leftJoin('roles', 'role_user.role_id', '=', 'roles.id')
                    ->orderBy('roles.name', $customDirection);
            }
            $query->orderBy('users.login_id', 'asc');
            $query->select('users.*');
        } else {
            if (! empty($conditions['sort'])) {
                $query->orderBy('users.login_id', 'asc');
            } else {
                $query->orderBy('created_at', 'desc');
            }
            $query->select('users.*');
        }

        return $query->paginate($conditions['page_size'] ?? 10);
    }

    public function show(User $user): User
    {
        return $user->load(['roles']);
    }

    public function update(User $user, array $params): User
    {
        return DB::transaction(function () use ($user, $params) {
            $user->fill([
                'first_name' => $params['first_name'] ?? $user->first_name,
                'last_name' => $params['last_name'] ?? $user->last_name,
                'email' => $params['login_id'] ?? $user->email,
                'login_id' => $params['login_id'] ?? $user->login_id,
            ]);

            $user->save();

            // Update role if changed
            if (! empty($params['role_name'])) {
                $user->syncRoles([$params['role_name']]);
            }

            return $user->load('roles');
        });
    }

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            /** @var User $currentUser */
            $currentUser = Auth::user();
            $targetCompanyId = $currentUser->hasRole(RoleName::SuperAdmin->value)
                ? $data['company_id']
                : $currentUser->company_id;

            $company = Company::query()->where('id', $targetCompanyId)->lockForUpdate()->first();

            $managerCount = User::where('company_id', $targetCompanyId)
                ->whereHas('roles', function ($q) {
                    $q->whereIn('name', [RoleName::Admin->value, RoleName::Doctor->value]);
                })->count();

            if ($managerCount >= $company->max_manager_accounts) {
                throw ValidationException::withMessages([
                    'role_name' => ["選択された権限は、登録可能な人数の上限に達しているため登録できません。 （現在の契約枠：マネージャー{$company->max_manager_accounts}名、従業員{$company->max_employee_accounts}名）\nプランの変更や人数の追加をご希望の場合は、こちらよりお問い合わせください。"],
                ]);
            }

            $user = User::query()->create([
                'company_id' => $targetCompanyId,
                'login_id' => $data['login_id'],
                'email' => $data['login_id'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'temp_password' => Hash::make(Str::random(16)),
                'status' => UserStatus::Pending,
            ]);

            $user->addRole($data['role_name']);

            $url = URL::temporarySignedRoute(
                'admin.invitation.verify',
                now()->addHours(1),
                ['user' => $user->id]
            );

            $roleLabel = match ($data['role_name']) {
                RoleName::Admin->value => 'Administrator',
                RoleName::Doctor->value => 'Occupational Physician',
                default => 'Administrator',
            };

            Mail::to($user->login_id)->send(new InvitationMail(
                $user->last_name,
                $user->first_name,
                $user->company->company_code,
                $user->login_id,
                $url,
                $roleLabel,
                'Administrator Account'
            ));

            return $user;
        });
    }

    public function resendInvitation(User $user): bool
    {
        $url = URL::temporarySignedRoute(
            'admin.invitation.verify',
            now()->addHours(1),
            ['user' => $user->id]
        );

        $roleName = $user->roles->first()?->name ?? RoleName::Admin->value;
        $roleLabel = match ($roleName) {
            RoleName::Admin->value => 'Administrator',
            RoleName::Doctor->value => 'Occupational Physician',
            default => 'Administrator',
        };

        Mail::to($user->login_id)->send(new InvitationMail(
            $user->last_name,
            $user->first_name,
            $user->company->company_code,
            $user->login_id,
            $url,
            $roleLabel,
            'Administrator Account'
        ));

        return true;
    }

    public function setupPassword(User $user, array $data): User
    {
        $user->forceFill([
            'password' => Hash::make($data['password']),
            'temp_password' => null,
            'status' => UserStatus::Active,
        ])->save();

        return $user;
    }

    public function requestDelete(User $user)
    {
        if ($user->status === UserStatus::PendingDeletion->value) {
            return $user;
        }

        $user->update([
            'status' => UserStatus::PendingDeletion,
            'deletion_requested_at' => now(),
            'deletion_requested_by' => Auth::user()->id,
        ]);

        $user->load(['company', 'roles']);

        $roleName = $user->roles->first()?->name ?? '';
        $roleLabel = match ($roleName) {
            RoleName::Admin->value => '管理者',
            RoleName::Doctor->value => '産業医',
            default => $roleName,
        };

        Mail::to('cs@stomiru.jp')->send(new AdminRequestDeleteMail(
            companyName: $user->company?->company_name ?? '',
            userName: $user->last_name . ' ' . $user->first_name,
            loginId: $user->login_id,
            userEmail: $user->email ?? $user->login_id,
            userRole: $roleLabel,
            requestDateTime: now()->format('Y/m/d H:i'),
        ));

        return $user;
    }

    public function sendPasswordResetLink(string $email): bool
    {
        $user = User::where('email', $email)->orWhere('login_id', $email)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['入力されたメールアドレスに該当するアカウントが見つかりません。'],
            ]);
        }

        $roleName = $user->roles->first()?->name ?? '';
        if (!in_array($roleName, [RoleName::Admin->value, RoleName::Doctor->value])) {
            throw ValidationException::withMessages([
                'email' => ['このアカウントにはパスワードリセットの権限がありません。'],
            ]);
        }

        $url = URL::temporarySignedRoute(
            'admin.invitation.verify',
            now()->addHours(1),
            ['user' => $user->id]
        );

        $roleLabel = match ($roleName) {
            RoleName::Admin->value => 'Administrator',
            RoleName::Doctor->value => 'Occupational Physician',
            default => 'Administrator',
        };

        Mail::to($user->login_id)->send(new ResetPasswordMail(
            $user->last_name,
            $user->first_name,
            $user->company->company_code,
            $user->login_id,
            $url,
            $roleLabel,
            'Administrator Account'
        ));

        return true;
    }
}
