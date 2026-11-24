import { useQuery } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import { WorkLoadDay } from '@/pages/manage/shift/api';

export type WeeklyHoursSummary = {
    start: string;
    end: string;
    total_hours: number;
    actual_hours: number;
    scheduled_hours: number;
    max_hour_per_week: number;
    is_custom_limit: boolean;
    is_limit_changed: boolean;
    status: string;
};

export type WeeklyWorkHoursRow = {
    user: {
        id: number;
        name: string;
        staff_no: string | null;
        profile_picture: string | null;
    };
    residence_status: string | null;
    days: WorkLoadDay[];
    weeks: WeeklyHoursSummary[];
    status: string;
};

export type WeekRange = {
    start: string;
    end: string;
};

export type GetWeeklyWorkHoursResponse = ApiResponse<PaginatedData<WeeklyWorkHoursRow> & { weeks: WeekRange[] }>;

const PAGINATION_PARAMS = ['page', 'page_size'];

export const exportWeeklyWorkHours = async (params: ParsedUrlQuery) => {
    const exportParams = Object.fromEntries(
        Object.entries(params).filter(([key]) => !PAGINATION_PARAMS.includes(key)),
    ) as ParsedUrlQuery;
    const blob = await ApiHelper.downloadBinary(ApiConfig.MANAGE_SHIFT_WEEKLY_WORK_HOURS_EXPORT, {
        params: exportParams,
    });
    const [from, to] = Array.isArray(exportParams.dates) ? exportParams.dates : ['', ''];
    ApiHelper.downloadBlob(blob, `weekly_work_hours_${from}_${to}.xlsx`);
};

export const useGetWeeklyWorkHoursQuery = (params: ParsedUrlQuery, isEnabled: boolean) => {
    return useQuery({
        queryKey: ['manage/shifts/weekly-work-hours', params],
        queryFn: async (): Promise<GetWeeklyWorkHoursResponse> => {
            return ApiHelper.get(ApiConfig.MANAGE_SHIFT_WEEKLY_WORK_HOURS, { params });
        },
        enabled: isEnabled,
    });
};
