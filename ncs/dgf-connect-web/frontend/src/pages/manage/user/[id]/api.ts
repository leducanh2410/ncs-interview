import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import { ShiftUser, ShiftUserSchedule } from '@/types/models/shift_user';
import { User } from '@/types/models/user';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export const useGetUserScheduleQuery = (userId: string, params: { start_date: string; end_date: string }) => {
    return useQuery({
        queryKey: ['user-schedule', userId, params],
        queryFn: async (): Promise<ApiResponse<{ user: User; shifts: ShiftUserSchedule[] }>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SCHEDULE_DETAIL, { userId }), {
                params,
            });
        },
    });
};

export type ToggleAbsentResult = ShiftUser & {
    is_salary_settled: boolean;
};

export const useToggleAbsentMutation = (shiftUserId: number) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (): Promise<ApiResponse<ToggleAbsentResult>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SCHEDULE_TOGGLE_ABSENT, { shiftUserId }),
                null,
            );
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['user-schedule'] });
            queryClient.invalidateQueries({ queryKey: ['manage/shifts'] });
            queryClient.invalidateQueries({ queryKey: ['work-history'] });
            queryClient.invalidateQueries({ queryKey: ['work-history/monthly'] });
            queryClient.invalidateQueries({ queryKey: ['report'] });
            queryClient.invalidateQueries({ queryKey: ['report/monthly'] });
        },
    });
};
