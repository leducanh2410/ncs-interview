import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import { FixedScheduleChangeConfig } from '@/types/models/fixed_schedule_change_config';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

type ConfigPayload = {
    is_enabled: boolean;
    deadline: string | null;
};

const QUERY_KEY = ['fixed-schedule-change-configs'];

export const useGetFixedScheduleChangeConfigsQuery = (hasPermission: boolean = false) => {
    return useQuery({
        queryKey: QUERY_KEY,
        queryFn: async (): Promise<ApiResponse<FixedScheduleChangeConfig[]>> => {
            return ApiHelper.get(ApiConfig.ADMIN_FIXED_SCHEDULE_CHANGE_CONFIG_LIST);
        },
        enabled: hasPermission,
    });
};

export const useUpdateFixedScheduleChangeConfigMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            workplaceId,
            data,
        }: {
            workplaceId: number;
            data: ConfigPayload;
        }): Promise<ApiResponse<FixedScheduleChangeConfig>> => {
            return ApiHelper.put(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_FIXED_SCHEDULE_CHANGE_CONFIG_DETAIL, { id: workplaceId }),
                data,
            );
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: QUERY_KEY });
        },
    });
};

export const useBulkUpdateFixedScheduleChangeConfigMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (
            data: ConfigPayload & { workplace_ids: number[] },
        ): Promise<ApiResponse<FixedScheduleChangeConfig[]>> => {
            return ApiHelper.put(ApiConfig.ADMIN_FIXED_SCHEDULE_CHANGE_CONFIG_BULK, data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: QUERY_KEY });
        },
    });
};
