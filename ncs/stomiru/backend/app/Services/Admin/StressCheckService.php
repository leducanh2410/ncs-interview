<?php

namespace App\Services\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\ErrorCode;
use App\Enums\ExamSessionStatus;
use App\Enums\LogAction;
use App\Enums\LogCategory;
use App\Enums\RoleName;
use App\Enums\StressCheckPeriodStatus;
use App\Helpers\ResponseHelper;
use App\Models\StressCheckPeriod;
use App\Models\User;
use App\Services\SuperAdmin\LogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StressCheckService
{
    protected $logService;

    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
    }

    public function listStressCheck(array $conditions): LengthAwarePaginator
    {
        /** @var User $currentUser */
        $currentUser = Auth::user();
        $companyId = $currentUser->hasRole(RoleName::SuperAdmin->value) && isset($conditions['company_id'])
            ? $conditions['company_id']
            : $currentUser->company_id;
        $statusDirection = null;
        $orderBy = '-id';

        if (! empty($conditions['sort'])) {
            $sort = $conditions['sort'];

            // Check if sorting by status (computed field)
            if ($sort === 'status' || $sort === '-status') {
                $statusDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';
                // Only use -id as the fallback sort for getFilteredQuery
                $orderBy = '-id';
            } else {
                $orderBy = $sort.',-id';
            }
        }

        $query = StressCheckPeriod::getFilteredQuery($conditions, [], $orderBy);
        $query->where('company_id', $companyId);

        // Apply status sorting via CASE expression since status is a computed accessor
        if ($statusDirection) {
            $today = now()->toDateString();
            // Clear existing ORDER BY (id DESC from getFilteredQuery) so status sort takes priority
            $query->reorder();
            $query->orderByRaw("
                CASE
                    WHEN start_date IS NULL OR end_date IS NULL THEN 0
                    WHEN ? < start_date THEN 1
                    WHEN ? <= end_date THEN 2
                    ELSE 3
                END {$statusDirection}
            ", [$today, $today]);
            $query->orderBy('id', 'desc');
        }

        return $query->paginate($conditions['page_size'] ?? 10);
    }

    public function create(array $params): StressCheckPeriod
    {
        return DB::transaction(function () use ($params) {
            /** @var User $currentUser */
            $currentUser = Auth::user();
            $targetCompanyId = $currentUser->hasRole(RoleName::SuperAdmin->value)
                ? $params['company_id']
                : $currentUser->company_id;

            $data = array_merge($params, [
                'user_id' => $currentUser->id,
                'company_id' => $targetCompanyId,
                'question_set_id' => 1,
            ]);

            if (isset($data['start_date'], $data['end_date'])) {
                $exists = StressCheckPeriod::where('company_id', $data['company_id'])
                    ->where('start_date', '<=', $data['end_date'])
                    ->where('end_date', '>=', $data['start_date'])
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        'start_date' => ['ストレスチェックの実施期間が既存の期間と重複しています'],
                    ]);
                }
            }

            $stressCheckPeriod = StressCheckPeriod::create($data);

            $this->logService->create([
                'category' => LogCategory::STRESS_CHECK_MANAGEMENT->value,
                'action' => LogAction::CREATE->value,
                'target_id' => $stressCheckPeriod->id,
                'company_id' => $stressCheckPeriod->company_id,
            ]);

            return $stressCheckPeriod;
        });
    }

    public function update(StressCheckPeriod $stressCheckPeriod, array $params): StressCheckPeriod
    {
        return DB::transaction(function () use ($stressCheckPeriod, $params) {
            if (Auth::user()->role === RoleName::Admin->value && $stressCheckPeriod->company_id !== Auth::user()->company_id) {
                throw new HttpResponseException(
                    ResponseHelper::forbidden(ErrorCode::Forbidden, 'Unauthorized action.')
                );
            }

            if (isset($params['start_date']) || isset($params['end_date'])) {

                $startDate = $params['start_date'] ?? $stressCheckPeriod->start_date;
                $endDate = $params['end_date'] ?? $stressCheckPeriod->end_date;
                $exists = StressCheckPeriod::where('company_id', $stressCheckPeriod->company_id)
                    ->where('id', '!=', $stressCheckPeriod->id)
                    ->where('start_date', '<=', $endDate)
                    ->where('end_date', '>=', $startDate)
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        'start_date' => ['ストレスチェックの実施期間が既存の期間と重複しています'],
                    ]);
                }
            }

            $stressCheckPeriod->update($params);

            $this->logService->create([
                'category' => LogCategory::STRESS_CHECK_MANAGEMENT->value,
                'action' => LogAction::UPDATE->value,
                'target_id' => $stressCheckPeriod->id,
                'company_id' => $stressCheckPeriod->company_id,
            ]);

            return $stressCheckPeriod;
        });
    }

    public function show(StressCheckPeriod $stressCheckPeriod): StressCheckPeriod
    {
        if (Auth::user()->role === RoleName::Staff->value) {
            throw new HttpResponseException(
                ResponseHelper::forbidden(ErrorCode::Forbidden, 'Unauthorized action.')
            );
        }

        if (in_array(Auth::user()->role, [RoleName::Admin->value, RoleName::Doctor->value, RoleName::StaffManager->value]) && $stressCheckPeriod->company_id !== Auth::user()->company_id) {
            throw new HttpResponseException(
                ResponseHelper::forbidden(ErrorCode::Forbidden, 'Unauthorized action.')
            );
        }

        return $stressCheckPeriod;
    }

    public function delete(StressCheckPeriod $stressCheckPeriod)
    {
        return DB::transaction(function () use ($stressCheckPeriod) {
            if (Auth::user()->role === RoleName::Admin->value && $stressCheckPeriod->company_id !== Auth::user()->company_id) {
                throw new HttpResponseException(
                    ResponseHelper::forbidden(ErrorCode::Forbidden, 'Unauthorized action.')
                );
            }

            $isStartedByDate = $stressCheckPeriod->start_date <= now()->toDateString();

            if ($stressCheckPeriod->status !== StressCheckPeriodStatus::NotStarted->value || $isStartedByDate) {
                throw new HttpResponseException(
                    ResponseHelper::forbidden(ErrorCode::Forbidden, 'Cannot delete stress check period that is already started.')
                );
            }

            $stressCheckPeriod->delete();

            $this->logService->create([
                'category' => LogCategory::STRESS_CHECK_MANAGEMENT->value,
                'action' => LogAction::DELETE->value,
                'target_id' => $stressCheckPeriod->id,
                'company_id' => $stressCheckPeriod->company_id,
            ]);

            return $stressCheckPeriod;
        });
    }

    public function getExaminees(StressCheckPeriod $stressCheckPeriod, array $conditions): LengthAwarePaginator
    {
        /** @var User $user */
        $user = Auth::user();

        if ($stressCheckPeriod->company_id !== $user->company_id && ! $user->hasRole(RoleName::SuperAdmin->value)) {
            throw new HttpResponseException(
                ResponseHelper::forbidden(ErrorCode::Forbidden, 'Unauthorized action.')
            );
        }

        $company_id = $stressCheckPeriod->company_id;

        $customSort = null;
        $customDirection = null;
        $orderBy = null;

        if (! empty($conditions['sort'])) {
            $sort = str_replace(['search', '-search'], ['name', '-name'], $conditions['sort']);
            $sortField = str_starts_with($sort, '-') ? substr($sort, 1) : $sort;

            $customFields = ['gender', 'name', 'department', 'role', 'response_status', 'judgement'];

            if (in_array($sortField, $customFields)) {
                $customSort = $sortField;
                $customDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';
            } else {
                $orderBy = $sort;
            }
        }

        $query = User::getFilteredQuery($conditions, ['userProfile', 'roles'], $orderBy)
            ->where('users.company_id', $company_id)
            ->whereHas('roles', function ($q) {
                $q->whereIn('name', [RoleName::Staff->value, RoleName::StaffManager->value]);
            })
            ->leftJoin('exam_sessions', function ($join) use ($stressCheckPeriod) {
                $join->on('users.id', '=', 'exam_sessions.user_id')
                    ->where('exam_sessions.stress_check_period_id', '=', $stressCheckPeriod->id);
            })
            ->leftJoin('exam_results', 'exam_sessions.id', '=', 'exam_results.exam_session_id')
            ->select(
                'users.*',
                DB::raw("
                    CASE
                        WHEN users.employee_status = '".EmployeeStatus::Retired->value."' THEN 'retired'
                        WHEN users.employee_status = '".EmployeeStatus::OnLeave->value."' THEN 'on_leave'
                        WHEN exam_sessions.status = '".ExamSessionStatus::Submitted->value."' THEN 'answered'
                        WHEN exam_sessions.status = '".ExamSessionStatus::InProgress->value."' THEN 'in_progress'
                        ELSE 'unanswered'
                    END as response_status
                "),
                DB::raw("
                    CASE
                        WHEN users.employee_status IN ('".EmployeeStatus::Retired->value."', '".EmployeeStatus::OnLeave->value."') THEN 'none'
                        WHEN exam_sessions.status = '".ExamSessionStatus::Submitted->value."' THEN COALESCE(exam_results.stress_level, 'none')
                        ELSE 'none'
                    END as judgement
                ")
            );

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
            } elseif ($customSort === 'response_status') {
                $query->orderBy('response_status', $customDirection);
            } elseif ($customSort === 'judgement') {
                $query->orderBy('judgement', $customDirection);
            }
            $query->orderBy('users.login_id', 'asc');
        } else {
            if (! empty($conditions['sort'])) {
                $query->orderBy('users.login_id', 'asc');
            } else {
                $query->orderBy('users.employee_code', 'asc');
            }
        }

        return $query->paginateFilter($conditions['page_size'] ?? 40);
    }

    public function exportExaminees(StressCheckPeriod $stressCheckPeriod): string
    {
        /** @var User $user */
        $user = Auth::user();

        if ($stressCheckPeriod->company_id !== $user->company_id && ! $user->hasRole(RoleName::SuperAdmin->value)) {
            throw new HttpResponseException(
                ResponseHelper::forbidden(ErrorCode::Forbidden, 'Unauthorized action.')
            );
        }

        $company_id = $stressCheckPeriod->company_id;
        $query = User::with(['userProfile', 'roles'])
            ->where('users.company_id', $company_id)
            ->whereHas('roles', function ($q) {
                $q->whereIn('name', [RoleName::Staff->value, RoleName::StaffManager->value]);
            })
            ->join('exam_sessions', function ($join) use ($stressCheckPeriod) {
                $join->on('users.id', '=', 'exam_sessions.user_id')
                    ->where('exam_sessions.stress_check_period_id', '=', $stressCheckPeriod->id);
            })
            ->leftJoin('exam_results', 'exam_sessions.id', '=', 'exam_results.exam_session_id')
            ->select(
                'users.*',
                DB::raw("
                    CASE
                        WHEN users.employee_status = '".EmployeeStatus::Retired->value."' THEN 'retired'
                        WHEN users.employee_status = '".EmployeeStatus::OnLeave->value."' THEN 'on_leave'
                        WHEN exam_sessions.status = '".ExamSessionStatus::Submitted->value."' THEN 'answered'
                        WHEN exam_sessions.status = '".ExamSessionStatus::InProgress->value."' THEN 'in_progress'
                        ELSE 'unanswered'
                    END as response_status
                "),
                DB::raw("
                    CASE
                        WHEN users.employee_status IN ('".EmployeeStatus::Retired->value."', '".EmployeeStatus::OnLeave->value."') THEN 'none'
                        WHEN exam_sessions.status = '".ExamSessionStatus::Submitted->value."' THEN COALESCE(exam_results.stress_level, 'none')
                        ELSE 'none'
                    END as judgement
                ")
            );

        $query->orderBy('users.employee_code', 'asc');

        $examinees = $query->get();

        $headers = [
            '従業員番号',
            '氏名',
            '性別',
            '権限',
            '部署',
            '回答状況',
            '判定',
        ];

        $genderMap = ['male' => '男', 'female' => '女'];
        $roleMap = [RoleName::Staff->value => '社員', RoleName::StaffManager->value => 'マネージャー'];
        $responseStatusMap = ['answered' => '回答済', 'in_progress' => '回答中（未完了）', 'unanswered' => '未回答', 'retired' => '退職', 'on_leave' => '休職'];
        $judgementMap = ['high' => '高い', 'medium' => '普通', 'low' => '低い', 'none' => 'なし'];

        $rows = [];
        $rows[] = $headers;

        foreach ($examinees as $examinee) {
            $profile = $examinee->userProfile;
            $roleKey = $examinee->roles->first()?->name;

            $rows[] = [
                $examinee->employee_code ?? '',
                $examinee->last_name.' '.$examinee->first_name,
                $genderMap[$profile?->gender ?? ''] ?? ($profile?->gender ?? ''),
                $roleMap[$roleKey ?? ''] ?? ($roleKey ?? ''),
                $profile?->department ?? '',
                $responseStatusMap[$examinee->response_status ?? ''] ?? ($examinee->response_status ?? ''),
                $judgementMap[$examinee->judgement ?? ''] ?? ($examinee->judgement ?? ''),
            ];
        }

        $output = '';
        foreach ($rows as $row) {
            $escapedCells = array_map(function ($cell) {
                $cell = (string) $cell;
                if (str_contains($cell, ',') || str_contains($cell, '"') || str_contains($cell, "\n") || str_contains($cell, "\r")) {
                    $cell = '"'.str_replace('"', '""', $cell).'"';
                }

                return $cell;
            }, $row);
            $output .= implode(',', $escapedCells)."\r\n";
        }

        $this->logService->create([
            'category' => LogCategory::EXAMINEE_LIST->value,
            'action' => LogAction::CSV_OUTPUT->value,
            'company_id' => $stressCheckPeriod->company_id,
        ]);

        return $output;
    }
}
