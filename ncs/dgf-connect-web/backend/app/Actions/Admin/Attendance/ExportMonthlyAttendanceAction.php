<?php

namespace App\Actions\Admin\Attendance;

use App\Enums\PermissionName;
use App\Exports\AdminAttendanceMonthlyExport;
use App\Models\AdminAttendanceSession;
use Illuminate\Support\Carbon;

class ExportMonthlyAttendanceAction
{
    private const REQUIRED_PERMISSIONS = [
        PermissionName::ManagerAuth->value,
        PermissionName::ManagerAttendanceCheckIn->value,
    ];

    public function execute(string $month, array $userIds = []): ?array
    {
        $monthStart = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        $sessions = AdminAttendanceSession::query()
            ->with(['events', 'workplace:id,name', 'user:id,first_name,last_name,short_name,staff_no'])
            ->whereBetween('work_date', [
                $monthStart->format('Y-m-d'),
                $monthStart->copy()->endOfMonth()->format('Y-m-d'),
            ])
            ->when($userIds !== [], fn($query) => $query->whereIn('user_id', $userIds))
            ->whereHas('user', fn($query) => $query->filter(['permission' => self::REQUIRED_PERMISSIONS]))
            ->orderBy('user_id')
            ->orderBy('work_date')
            ->orderBy('first_check_in_at')
            ->get();

        if ($sessions->isEmpty()) {
            return null;
        }

        return [
            'filename' => 'admin_attendance_' . $monthStart->format('Ym') . '.xlsx',
            'report' => new AdminAttendanceMonthlyExport($sessions, $monthStart),
        ];
    }
}
