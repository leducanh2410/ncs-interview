import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { FixedScheduleWithRelation } from '@/types/models/fixed_schedule';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

type FixedScheduleResponse = ApiResponse<void>;
export const useGetFixedSchedulesQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['fixed-schedules', params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<FixedScheduleWithRelation>>> => {
            return ApiHelper.get(ApiConfig.ADMIN_FIXED_SCHEDULE_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useCreateFixedScheduleMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, any>): Promise<FixedScheduleResponse> => {
            return ApiHelper.post(ApiConfig.ADMIN_FIXED_SCHEDULE_LIST, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['fixed-schedules'] });
        },
    });
};

export const useUpdateFixedScheduleMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, data }: { id: string; data: Record<string, any> }): Promise<FixedScheduleResponse> => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_FIXED_SCHEDULE_DETAIL, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['fixed-schedules'] });
        },
    });
};

export const useDeleteFixedScheduleMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: string): Promise<ApiResponse<any>> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_FIXED_SCHEDULE_DETAIL, { id }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['fixed-schedules'] });
        },
    });
};
