<?php

namespace App\Services\Admin;

use App\Enums\LogAction;
use App\Enums\LogCategory;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Mail\EmployeeRequestDeleteMail;
use App\Models\Company;
use App\Models\User;
use App\Services\SuperAdmin\LogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    protected $logService;

    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
    }

    public function listEmployees(array $conditions): LengthAwarePaginator
    {
        $conditions['roles'] = [RoleName::Staff, RoleName::StaffManager];

        /** @var User $currentUser */
        $currentUser = Auth::user();
        $companyId = $currentUser->hasRole(RoleName::SuperAdmin->value) ? ($conditions['company_id'] ?? null) : $currentUser->company_id;

        $customSort = null;
        $customDirection = null;
        $orderBy = null;

        if (! empty($conditions['sort'])) {
            // $sort = $conditions['sort'];
            $sort = str_replace(['search', '-search'], ['name', '-name'], $conditions['sort']);
            $sortField = str_starts_with($sort, '-') ? substr($sort, 1) : $sort;

            $customFields = ['gender', 'name', 'department', 'role'];

            if (in_array($sortField, $customFields)) {
                $customSort = $sortField;
                $customDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';
            } else {
                $orderBy = $sort;
            }
        }

        $query = User::getFilteredQuery($conditions, ['userProfile', 'roles'], $orderBy);
        $query->where('company_id', $companyId);

        if ($customSort) {
            $query->reorder();
            if (in_array($customSort, ['gender', 'department'])) {
                $query->leftJoin('user_profiles', 'users.id', '=', 'user_profiles.user_id')
                    ->orderBy('user_profiles.'.$customSort, $customDirection);
            } elseif ($customSort === 'name') {
                $query->orderByRaw("CONCAT(users.first_name, ' ', users.last_name) ".($customDirection === 'desc' ? 'DESC' : 'ASC'));
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
                $query->orderBy('users.employee_code', 'asc');
            }
            $query->select('users.*');
        }

        return $query->paginateFilter($conditions['page_size'] ?? null);
    }

    public function create(array $params): User
    {
        return DB::transaction(function () use ($params) {
            /** @var User $currentUser */
            $currentUser = Auth::user();
            $targetCompanyId = $currentUser->hasRole(RoleName::SuperAdmin->value)
                ? $params['company_id']
                : $currentUser->company_id;

            $company = Company::query()->where('id', $targetCompanyId)->lockForUpdate()->first();

            $totalCount = User::where('company_id', $targetCompanyId)
                ->whereHas('roles', function ($q) {
                    $q->whereIn('name', [RoleName::StaffManager->value, RoleName::Staff->value]);
                })->count();

            if ($totalCount >= $company->max_employee_accounts) {
                throw ValidationException::withMessages([
                    'role' => ["選択された権限は、登録可能な人数の上限に達しているため登録できません。 （現在の契約枠：マネージャー{$company->max_manager_accounts}名、従業員{$company->max_employee_accounts}名）\nプランの変更や人数の追加をご希望の場合は、こちらよりお問い合わせください。"],
                ]);
            }

            $tempPasswordHashed = Hash::make($params['temp_password']);
            $userData = [
                'company_id' => $targetCompanyId,
                'login_id' => $params['employee_code'],
                'first_name' => $params['first_name'],
                'last_name' => $params['last_name'],
                'temp_password' => $tempPasswordHashed,
                'password' => $tempPasswordHashed,
                'employee_status' => $params['employee_status'],
                'status' => UserStatus::Pending->value,
                'email' => $params['email'] ?? null,
                'employee_code' => $params['employee_code'],
                'is_disabled' => false,
            ];

            $user = User::create($userData);

            $user->userProfile()->create([
                'department' => $params['department'] ?? null,
                'position' => $params['position'] ?? null,
                'gender' => $params['gender'],
                'birthday' => $params['birthday'],
            ]);

            if (isset($params['role'])) {
                $user->addRole($params['role']);
            }

            $this->logService->create([
                'category' => LogCategory::EMPLOYEE_MANAGEMENT->value,
                'action' => LogAction::CREATE->value,
                'target_id' => $user->employee_code,
                'company_id' => $targetCompanyId,
            ]);

            return $user->load('userProfile');
        });
    }

    public function update(User $user, array $params): User
    {
        return DB::transaction(function () use ($user, $params) {
            $newRole = $params['role'] ?? null;

            $isStatusChanged = false;
            $isRoleChanged = false;
            $isOtherChanged = false;

            if ($newRole && ! $user->hasRole($newRole)) {
                $isRoleChanged = true;
            }

            $user->fill([
                'first_name' => $params['first_name'] ?? $user->first_name,
                'last_name' => $params['last_name'] ?? $user->last_name,
                'employee_status' => $params['employee_status'] ?? $user->employee_status,
                'login_id' => $params['employee_code'] ?? $user->login_id,
                'employee_code' => $params['employee_code'] ?? $user->employee_code,
                'email' => array_key_exists('email', $params) ? $params['email'] : $user->email,
            ]);

            if ($user->isDirty('employee_status')) {
                $isStatusChanged = true;
            }

            $otherUserFields = ['first_name', 'last_name', 'login_id', 'employee_code', 'email'];
            if ($user->isDirty($otherUserFields)) {
                $isOtherChanged = true;
            }

            if (! empty($params['temp_password']) && ! Hash::check($params['temp_password'], $user->temp_password)) {
                $tempPasswordHashed = Hash::make($params['temp_password']);
                $user->temp_password = $tempPasswordHashed;
                $user->password = $tempPasswordHashed;
                $isOtherChanged = true;
            }

            $user->save();

            $userProfile = $user->userProfile()->firstOrNew(['user_id' => $user->id]);
            $userProfile->fill([
                'department' => array_key_exists('department', $params)
                    ? $params['department']
                    : ($userProfile->department ?? null),
                'position' => $params['position'] ?? $userProfile->position ?? null,
                'gender' => $params['gender'] ?? $userProfile->gender ?? null,
            ]);

            $userProfileFields = ['department', 'position', 'gender'];
            if ($userProfile->isDirty($userProfileFields)) {
                $isOtherChanged = true;
            }

            $userProfile->save();
            $user->save();

            $userProfile = $user->userProfile()->firstOrNew(['user_id' => $user->id]);
            $userProfile->fill([
                'department' => array_key_exists('department', $params)
                    ? $params['department']
                    : ($userProfile->department ?? null),
                'position' => $params['position'] ?? $userProfile->position ?? null,
                'gender' => $params['gender'] ?? $userProfile->gender ?? null,
            ]);

            $userProfileFields = ['department', 'position', 'gender'];
            if ($userProfile->isDirty($userProfileFields)) {
                $isOtherChanged = true;
            }

            $userProfile->save();

            if ($isRoleChanged) {
                $user->syncRoles([$newRole]);
            }

            if ($isStatusChanged) {
                $this->logService->create([
                    'category' => LogCategory::EMPLOYEE_MANAGEMENT->value,
                    'action' => LogAction::STATUS_UPDATE->value,
                    'target_id' => $user->employee_code,
                    'company_id' => $user->company_id,
                ]);
            }

            if ($isRoleChanged) {
                $this->logService->create([
                    'category' => LogCategory::EMPLOYEE_MANAGEMENT->value,
                    'action' => LogAction::ROLE_UPDATE->value,
                    'target_id' => $user->employee_code,
                    'company_id' => $user->company_id,
                ]);
            }

            if ($isOtherChanged) {
                $this->logService->create([
                    'category' => LogCategory::EMPLOYEE_MANAGEMENT->value,
                    'action' => LogAction::UPDATE->value,
                    'target_id' => $user->employee_code,
                    'company_id' => $user->company_id,
                ]);
            }

            return $user->load('userProfile');
        });
    }

    public function show(User $user): User
    {
        return $user->load(['userProfile', 'roles']);
    }

    public function requestDelete(User $user)
    {
        if ($user->status === UserStatus::PendingDeletion->value) {
            return $user;
        }
        /** @var User $currentUser */
        $currentUser = Auth::user();

        $user->update([
            'status' => UserStatus::PendingDeletion->value,
            'deletion_requested_at' => now(),
            'deletion_requested_by' => $currentUser->id,
        ]);

        $company = $currentUser->company;
        $deadlineDate = now()->addWeekdays(5)->format('Y/m/d');

        Mail::to('cs@stomiru.jp')->send(new EmployeeRequestDeleteMail(
            companyName: $company->company_name,
            adminName: $currentUser->name,
            targetEmployeeName: $user->name,
            targetEmployeeId: $user->employee_code,
            deadlineDate: $deadlineDate,
        ));

        return $user;
    }

    public function confirmDeleteRequest(User $user, Request $request)
    {
        if ($user->status !== UserStatus::PendingDeletion->value) {
            return $user;
        }

        if ($request->input('approve')) {
            $this->delete($user);
        } else {
            $user->update([
                'status' => UserStatus::Active->value,
                'deletion_requested_at' => null,
                'deletion_requested_by' => null,
            ]);
        }

        return $user;
    }

    public function setupPassword(User $user, array $data): User
    {
        $userProfile = $user->userProfile;

        $requestBirthday = $data['birthday'];
        $dbBirthday = $userProfile->birthday ? date('Ymd', strtotime($userProfile->birthday)) : null;

        if ($dbBirthday !== $requestBirthday) {
            throw ValidationException::withMessages([
                'birthday' => ['生年月日が一致しません'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'temp_password' => null,
            'status' => UserStatus::Active,
        ])->save();

        return $user;
    }

    public function exportEmployees(Request $request): string
    {
        /** @var User $currentUser */
        $currentUser = Auth::user();
        $targetCompanyId = $currentUser->hasRole(RoleName::SuperAdmin->value) ? $request->input('company_id', 0) : $currentUser->company_id;

        $employees = User::with(['userProfile', 'roles'])
            ->where('company_id', $targetCompanyId)
            ->whereHas('roles', function ($q) {
                $q->whereIn('name', [RoleName::Staff->value, RoleName::StaffManager->value]);
            })
            ->orderBy('employee_code', 'asc')
            ->get();

        $headers = [
            '従業員番号*',
            '姓*',
            '名*',
            '性別*',
            '誕生日*（西暦、YYYYMMDD）',
            'メールアドレス',
            '部署',
            '役職',
            '権限*',
            'ステータス*',
        ];

        $genderMap = ['male' => '男性', 'female' => '女性'];
        $roleMap = [RoleName::Staff->value => '社員', RoleName::StaffManager->value => 'マネージャー'];
        $statusMap = ['active' => '在職中', 'on_leave' => '休職中', 'retired' => '退職'];

        $rows = [];
        $rows[] = $headers;

        foreach ($employees as $employee) {
            $profile = $employee->userProfile;
            $roleKey = $employee->roles->first()?->name;
            $birthday = $profile?->birthday ? date('Ymd', strtotime($profile->birthday)) : '';

            $rows[] = [
                $employee->employee_code ?? '',
                $employee->last_name,
                $employee->first_name,
                $genderMap[$profile?->gender ?? ''] ?? ($profile?->gender ?? ''),
                $birthday,
                $employee->email ?? '',
                $profile?->department ?? '',
                $profile?->position ?? '',
                $roleMap[$roleKey ?? ''] ?? ($roleKey ?? ''),
                $statusMap[$employee->employee_status ?? ''] ?? ($employee->employee_status ?? ''),
            ];
        }

        $output = '';
        foreach ($rows as $row) {
            $escapedCells = array_map(function ($cell) {
                $cell = (string) $cell;
                if (str_contains($cell, ',') || str_contains($cell, '"') || str_contains($cell, "\n")) {
                    $cell = '"'.str_replace('"', '""', $cell).'"';
                }

                return $cell;
            }, $row);
            $output .= implode(',', $escapedCells)."\r\n";
        }

        $this->logService->create([
            'category' => LogCategory::EMPLOYEE_MANAGEMENT->value,
            'action' => LogAction::CSV_OUTPUT->value,
            'company_id' => $targetCompanyId,
        ]);

        return $output;
    }

    public function importFromCsv(UploadedFile $file, int $targetCompanyId): array
    {
        /** @var User $currentUser */
        $currentUser = Auth::user();
        $companyId = $currentUser->hasRole(RoleName::SuperAdmin->value) ? $targetCompanyId : $currentUser->company_id;

        return DB::transaction(function () use ($file, $companyId) {
            $company = Company::where('id', $companyId)->lockForUpdate()->first();
            $currentTotalCount = User::where('company_id', $companyId)
                ->whereHas('roles', function ($q) {
                    $q->whereIn('name', [RoleName::StaffManager->value, RoleName::Staff->value]);
                })->count();

            $handle = fopen($file->getRealPath(), 'r');
            $errors = [];
            $successCount = 0;
            $rowNumber = 0;

            // Maps for parsing Japanese labels
            $genderMap = ['男性' => 'male', '女' => 'female', '女性' => 'female', '男' => 'male'];
            $roleMap = ['社員' => RoleName::Staff->value, 'マネージャー' => RoleName::StaffManager->value];
            $statusMap = ['在職中' => 'active', '休職中' => 'on_leave', '退職' => 'retired'];

            // Non-updatable fields for existing employees (Figma requirement)
            $nonUpdatableFields = ['last_name' => '姓', 'first_name' => '名', 'gender' => '性別', 'birthday' => '誕生日', 'role' => '権限', 'employee_status' => 'ステータス'];

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                // Detect and strip BOM on first row
                if ($rowNumber === 1) {
                    $row[0] = ltrim($row[0], "\xEF\xBB\xBF");
                }

                // Skip header row
                if ($rowNumber === 1) {
                    continue;
                }

                // Ensure minimum columns
                if (count($row) < 10) {
                    $row = array_pad($row, 10, '');
                }

                [$employeeCode, $lastName, $firstName, $genderLabel, $birthday, $email, $deptName, $position, $roleLabel, $statusLabel] = array_map('trim', $row);

                $rowErrors = [];

                // ── Required fields validation
                $missingRequired = [];
                if (empty($employeeCode)) {
                    $missingRequired[] = '従業員番号';
                }
                if (empty($lastName)) {
                    $missingRequired[] = '姓';
                }
                if (empty($firstName)) {
                    $missingRequired[] = '名';
                }
                if (empty($genderLabel)) {
                    $missingRequired[] = '性別';
                }
                if (empty($birthday)) {
                    $missingRequired[] = '誕生日';
                }
                if (empty($roleLabel)) {
                    $missingRequired[] = '権限';
                }
                if (empty($statusLabel)) {
                    $missingRequired[] = 'ステータス';
                }

                if (! empty($missingRequired)) {
                $errors[] = ['row' => $rowNumber, 'message' => $rowNumber.'行：必須項目が入力されていません。（'.implode('、', $missingRequired).'）'];

                    continue;
                }

                // ── Format validations
                if (! preg_match('/^\d{8}$/', $birthday)) {
                $rowErrors[] = $rowNumber.'行：生年月日は半角数字８桁で入力してください。';
                }

                if (! preg_match('/^[A-Za-z0-9]+$/', $employeeCode)) {
                $rowErrors[] = $rowNumber.'行：従業員番号は半角英数字で入力してください。';
                }

                if (! empty($email) && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rowErrors[] = $rowNumber.'行：メールの形式を確認ください。';
                }

                if (! isset($genderMap[$genderLabel])) {
                $rowErrors[] = $rowNumber.'行：性別は「男性」または「女性」で入力してください。';
                }

                if (! isset($roleMap[$roleLabel])) {
                $rowErrors[] = $rowNumber.'行：権限は「社員」または「マネージャー」で入力してください。';
                }

                if (! isset($statusMap[$statusLabel])) {
                $rowErrors[] = $rowNumber.'行：ステータスは「在職中」「休職中」「退職」のいずれかで入力してください。';
                }

                if (! empty($rowErrors)) {
                $errors = array_merge($errors, array_map(fn ($msg) => ['row' => $rowNumber, 'message' => $msg], $rowErrors));

                    continue;
                }

                $gender = $genderMap[$genderLabel];
                $role = $roleMap[$roleLabel];
                $employeeStatus = $statusMap[$statusLabel];
                $department = $deptName !== '' ? $deptName : null;

                // ── Check if employee already exists
                $trimmedCode = ltrim($employeeCode, '0') ?: '0';
                $existingEmployee = User::where('company_id', $companyId)
                    ->whereRaw("TRIM(LEADING '0' FROM employee_code) = ?", [$trimmedCode])
                    ->with(['userProfile', 'roles'])
                    ->first();

                if ($existingEmployee) {
                    // Existing employee: only allow update of email, department, position
                    $restrictedChanges = [];

                    if ($existingEmployee->last_name !== $lastName) {
                        $restrictedChanges[] = '姓';
                    }
                    if ($existingEmployee->first_name !== $firstName) {
                        $restrictedChanges[] = '名';
                    }
                    if (($existingEmployee->userProfile?->gender ?? '') !== $gender) {
                        $restrictedChanges[] = '性別';
                    }

                    $dbBirthday = $existingEmployee->userProfile?->birthday ? date('Ymd', strtotime($existingEmployee->userProfile->birthday)) : '';
                    if ($dbBirthday !== $birthday) {
                        $restrictedChanges[] = '誕生日';
                    }

                    $existingRole = $existingEmployee->roles->first()?->name ?? '';
                    if ($existingRole !== $role) {
                        $restrictedChanges[] = '権限';
                    }
                    if (($existingEmployee->employee_status ?? '') !== $employeeStatus) {
                        $restrictedChanges[] = 'ステータス';
                    }

                    if (! empty($restrictedChanges)) {
                    $errors[] = ['row' => $rowNumber, 'message' => $rowNumber.'行：更新できない項目が含まれています。（対象：'.implode('、', $restrictedChanges).'）'];

                        continue;
                    }

                    // Update allowed fields
                    $existingEmployee->update(['email' => $email ?: null]);
                    $profile = $existingEmployee->userProfile()->firstOrNew(['user_id' => $existingEmployee->id]);
                    $profile->fill(['department' => $department, 'position' => $position ?: null]);
                    $profile->save();

                    $successCount++;
                } else {
                    // New employee: check for duplicate email within company
                    if (! empty($email) && User::where('company_id', $companyId)->where('email', $email)->exists()) {
                    $errors[] = ['row' => $rowNumber, 'message' => $rowNumber.'行：このメールアドレスはすでに登録されています。'];

                        continue;
                    }

                    if ($currentTotalCount >= $company->max_employee_accounts) {
                        $errors[] = ['row' => $rowNumber, 'message' => $rowNumber . "行：選択された権限は、登録可能な人数の上限に達しているため登録できません。 （現在の契約枠：マネージャー{$company->max_manager_accounts}名、従業員{$company->max_employee_accounts}名）\nプランの変更や人数の追加をご希望の場合は、こちらよりお問い合わせください。"];
                        continue;
                    }


                    $currentTotalCount++;

                    $tempPassword = Str::random(12);

                    $tempPasswordHashed = Hash::make($tempPassword);
                    $user = User::create([
                        'company_id' => $companyId,
                        'login_id' => $employeeCode,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email ?: null,
                        'employee_code' => $employeeCode,
                        'employee_status' => $employeeStatus,
                        'status' => UserStatus::Pending->value,
                        'temp_password' => $tempPasswordHashed,
                        'password' => $tempPasswordHashed,
                        'is_disabled' => false,
                    ]);

                    $user->userProfile()->create([
                        'gender' => $gender,
                        'birthday' => $birthday,
                        'department' => $department,
                        'position' => $position ?: null,
                    ]);

                    $user->addRole($role);

                    $successCount++;
                }
            }

            fclose($handle);

            $this->logService->create([
                'category' => LogCategory::EMPLOYEE_MANAGEMENT->value,
                'action' => LogAction::CSV_REGISTER->value,
                'company_id' => $companyId,
            ]);

            return [
                'success_count' => $successCount,
                'errors' => $errors,
            ];
        });
    }

    public function delete(User $user)
    {
        return DB::transaction(function () use ($user) {
            $user->delete();

            $this->logService->create([
                'category' => LogCategory::EMPLOYEE_MANAGEMENT->value,
                'action' => LogAction::DELETE->value,
                'target_id' => $user->employee_code,
                'company_id' => $user->company_id,
            ]);
        });
    }
}
