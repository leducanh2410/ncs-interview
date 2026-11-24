<?php

namespace App\Services\SuperAdmin;

use App\Enums\CompanyStatus;
use App\Enums\EmployeeStatus;
use App\Enums\LogAction;
use App\Enums\LogCategory;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Mail\CompanyCancellationMail;
use App\Mail\CompanyReactivationMail;
use App\Mail\InvitationMail;
use App\Models\AccessToken;
use App\Models\Company;
use App\Models\StressCheckPeriod;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CompanyService
{
    protected $logService;

    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
    }

    public function listCompanies(array $conditions): LengthAwarePaginator
    {
        $orderBy = '-id';
        if (! empty($conditions['sort'])) {
            // Frontend passes id instead of company_name for 'search' column, map it here
            $sortParam = str_replace(['search', '-search'], ['company_name', '-company_name'], $conditions['sort']);
            $orderBy = $sortParam.',-id';
        }

        $query = Company::getFilteredQuery($conditions, null, $orderBy)
            ->withTrashed();

        $query->withCount([
            'users as employees_count',
            'users as on_leave_employees_count' => function ($query) {
                $query->where('employee_status', EmployeeStatus::OnLeave->value);
            },
            'users as retired_employees_count' => function ($query) {
                $query->where('employee_status', EmployeeStatus::Retired->value);
            },
        ]);

        return $query->paginateFilter($conditions['page_size'] ?? null);
    }

    public function create(array $params): Company
    {
        return DB::transaction(function () use ($params) {
            $data = array_merge($params, [
                'company_code' => Str::upper(Str::random(6)),
                'status' => CompanyStatus::Active->value,
                'contract_start_date' => now(),
            ]);

            $company = Company::query()->create($data);

            $this->logService->create([
                'category' => LogCategory::COMPANY_MANAGEMENT->value,
                'action' => LogAction::CREATE->value,
                'target_id' => $company->id,
                'company_id' => $company->id,
            ]);

            return $company;
        });
    }

    public function update(Company $company, array $params): Company
    {
        return DB::transaction(function () use ($company, $params) {
            $company->update($params);

            $this->logService->create([
                'category' => LogCategory::COMPANY_MANAGEMENT->value,
                'action' => LogAction::UPDATE->value,
                'target_id' => $company->id,
                'company_id' => $company->id,
            ]);

            return $company;
        });
    }

    public function updateNote(Company $company, array $params): Company
    {
        return DB::transaction(function () use ($company, $params) {
            $company->update($params);

            $this->logService->create([
                'category' => LogCategory::COMPANY_MANAGEMENT->value,
                'action' => LogAction::UPDATE->value,
                'target_id' => $company->id,
                'company_id' => $company->id,
            ]);

            return $company;
        });
    }

    public function show(Company $company, array $params): array
    {
        // Build orderBy for stressCheckPeriods
        $stressCheckOrderBy = ['id' => 'desc'];
        if (! empty($params['stress_check_order_by'])) {
            $primaryField = $params['stress_check_order_by'];
            $direction = $params['stress_check_direction'] ?? 'desc';

            $stressCheckOrderBy = [
                $primaryField => $direction,
            ];

            if ($primaryField !== 'id') {
                $stressCheckOrderBy['id'] = 'desc';
            }
        }

        $stressCheckPeriods = StressCheckPeriod::getFilteredQuery([], null, $stressCheckOrderBy)
            ->where('company_id', $company->id)
            ->paginateFilter($params['page_size'] ?? 3);

        $userOrderBy = null;
        if (! empty($params['user_order_by'])) {
            $direction = $params['user_direction'] ?? 'asc';
            if ($params['user_order_by'] === 'name') {
                $userOrderBy = [
                    'last_name' => $direction,
                    'first_name' => $direction,
                ];
            } else {
                $userOrderBy = [
                    $params['user_order_by'] => $direction,
                ];
            }
        }

        $company->load([
            'users' => function ($query) use ($userOrderBy) {
                $query->whereHas('roles', function ($q) {
                    $q->whereIn('name', [
                        RoleName::Admin->value,
                        RoleName::Doctor->value,
                    ]);
                })->with('roles');

                if ($userOrderBy) {
                    foreach ($userOrderBy as $field => $direction) {
                        $query->orderBy($field, $direction);
                    }
                }
            },
        ]);

        $company->loadCount([
            'users as employees_count',
            'users as on_leave_employees_count' => function ($query) {
                $query->where('employee_status', EmployeeStatus::OnLeave->value);
            },
            'users as retired_employees_count' => function ($query) {
                $query->where('employee_status', EmployeeStatus::Retired->value);
            },
        ]);

        $company->users->makeVisible(['created_at']);

        $stressCheckPeriodsData = [
            'data' => $stressCheckPeriods->items(),
            'current_page' => $stressCheckPeriods->currentPage(),
            'last_page' => $stressCheckPeriods->lastPage(),
            'per_page' => $stressCheckPeriods->perPage(),
            'total' => $stressCheckPeriods->total(),
        ];

        return [
            ...$company->toArray(),
            'stress_check_periods' => $stressCheckPeriodsData,
        ];
    }

    public function delete(Company $company)
    {
        return DB::transaction(function () use ($company) {
            $company->update([
                'status' => CompanyStatus::Cancelled->value,
            ]);

            User::query()
                ->where('company_id', $company->id)
                ->where('status', UserStatus::PendingDeletion->value)
                ->whereHas('roles', function ($query) {
                    $query->whereIn('name', [
                        RoleName::Staff->value,
                        RoleName::StaffManager->value,
                    ]);
                })
                ->update(['status' => UserStatus::Active->value]);

            AccessToken::query()
                ->whereIn('user_id', User::query()->select('id')->where('company_id', $company->id))
                ->delete();

            $company->delete();

            $this->logService->create([
                'category' => LogCategory::COMPANY_MANAGEMENT->value,
                'action' => LogAction::DELETE->value,
                'target_id' => $company->id,
                'company_id' => $company->id,
            ]);

            $adminName = "{$company->contact_last_name} {$company->contact_first_name}";
            $completionDate = now()->format('Y/m/d');

            Mail::to($company->contact_email)->send(
                new CompanyCancellationMail(
                    $company->company_name,
                    $adminName,
                    $completionDate,
                    $company->company_code
                )
            );

            return $company;
        });
    }

    public function restore(Company $company)
    {
        return DB::transaction(function () use ($company) {
            $company->restore();

            $company->update([
                'status' => CompanyStatus::Active->value,
            ]);

            $adminName = "{$company->contact_last_name} {$company->contact_first_name}";
            $completionDate = now()->format('Y/m/d');

            Mail::to($company->contact_email)->send(
                new CompanyReactivationMail(
                    $company->company_name,
                    $adminName,
                    $completionDate,
                    $company->company_code
                )
            );

            return $company;
        });
    }

    public function approve(Company $company)
    {
        return DB::transaction(function () use ($company) {

            if ($company->status == CompanyStatus::Pending->value) {
                $company->update([
                    'status' => CompanyStatus::Active->value,
                ]);

                // create admin
                $user = User::query()->create([
                    'company_id' => $company->id,
                    'login_id' => $company->contact_email,
                    'email' => $company->contact_email,
                    'first_name' => $company->contact_first_name,
                    'last_name' => $company->contact_last_name,
                    'temp_password' => Hash::make(Str::random(16)),
                    'status' => UserStatus::Pending,
                ]);
                $user->addRole(RoleName::Admin->value);

                $url = URL::temporarySignedRoute(
                    'admin.invitation.verify',
                    now()->addHours(1),
                    ['user' => $user->id]
                );

                $roleLabel = 'Administrator';

                Mail::to($user->login_id)->send(new InvitationMail(
                    $user->last_name,
                    $user->first_name,
                    $user->company->company_code,
                    $user->login_id,
                    $url,
                    $roleLabel,
                    'Administrator Account'
                ));
            }

            // write log

            return $company;
        });
    }
}
