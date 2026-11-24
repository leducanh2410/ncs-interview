import { useQuery } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { AdminAttendanceDailySummary, AdminAttendanceSession } from '@/types/models/admin_attendance_session';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';

export const useGetAttendanceDailyQuery = (params: ParsedUrlQuery, enabled: boolean) => {
    return useQuery({
        queryKey: ['admin', 'attendance', 'daily', params],
        queryFn: (): Promise<ApiResponse<PaginatedData<AdminAttendanceDailySummary>>> =>
            ApiHelper.get(ApiConfig.ADMIN_ATTENDANCE_DAILY, { params }),
        enabled,
    });
};

export const exportMonthlyAttendance = async (month: string, userIds: string[] = []) => {
    const blob = await ApiHelper.downloadBinary(ApiConfig.ADMIN_ATTENDANCE_EXPORT, {
        params: { month, ...(userIds.length ? { user_ids: userIds } : {}) },
    });
    const objectUrl = URL.createObjectURL(blob);
    const link: HTMLAnchorElement = document.createElement('a');
    link.href = objectUrl;
    link.download = `admin_attendance_${month.replaceAll('-', '')}.xlsx`;
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
};

export const useGetAttendanceDayQuery = (userId: number | null, date: string | null) => {
    return useQuery({
        queryKey: ['admin', 'attendance', 'day', userId, date],
        queryFn: (): Promise<ApiResponse<AdminAttendanceSession[]>> =>
            ApiHelper.get(ApiConfig.ADMIN_ATTENDANCE_DAY, {
                params: { user_id: userId ? String(userId) : undefined, date: date ?? undefined },
            }),
        enabled: !!userId && !!date,
    });
};
