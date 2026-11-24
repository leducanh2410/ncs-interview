<?php

namespace App\Services\Manage;

use App\Enums\PermissionName;
use App\Models\ShiftReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ShiftReportService
{
    public function list(array $conditions, User $user): LengthAwarePaginator
    {
        $query = ShiftReport::getFilteredQuery(
            $conditions,
            ['shiftUser.shift.workplaceShift', 'shiftUser.shift.workplace', 'user'],
            ['id' => 'asc']
        );

        return $query
            ->applyWorkplaceFilter($user, PermissionName::WorkReportList->value)
            ->paginate($conditions['page_size'] ?? null);
    }

    public function listMonthly(array $conditions, User $user): LengthAwarePaginator
    {
        $month = $conditions['month'] ? Carbon::parse($conditions['month']) : now();

        $query = User::query()
            ->select([
                'users.*',
                DB::raw('COUNT(shift_reports.late_minutes) as late_count'),
                DB::raw('SUM(shift_reports.late_minutes) as late_minutes'),
                DB::raw('COUNT(shift_reports.early_minutes) as early_count'),
                DB::raw('SUM(shift_reports.early_minutes) as early_minutes'),
                DB::raw('SUM(shift_reports.is_absent) as absent_count'),
            ])
            ->join('shift_reports', 'shift_reports.user_id', '=', 'users.id')
            ->join('shift_users', 'shift_reports.shift_user_id', '=', 'shift_users.id')
            ->join('shifts', 'shift_users.shift_id', '=', 'shifts.id')
            ->whereBetween('shift_reports.date', [$month->startOfMonth(), $month->clone()->endOfMonth()])
            ->when($conditions['user_id'] ?? null, function ($query, $userId) {
                return $query->where('users.id', $userId);
            })
            ->groupBy('users.id')
            ->orderBy('users.id');

        $workplaceIds = $user->getWorkplaceIdsByPermission(PermissionName::WorkReportList->value);
        if ($workplaceIds !== null) {
            if (empty($workplaceIds)) {
                // Return empty paginator if user has no access
                return new LengthAwarePaginator(collect(), 0, $conditions['page_size'] ?? 15);
            }
            $query->whereIn('shifts.workplace_id', $workplaceIds);
        }

        return $query->paginate($conditions['page_size'] ?? null);
    }
}
