import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { NotificationWithRelation } from '@/types/models/notification';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export const useGetNotificationsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['notifications', params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<NotificationWithRelation>>> => {
            return ApiHelper.get(ApiConfig.MANAGE_NOTIFICATION_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useCreateNotificationMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery): Promise<ApiResponse<void>> => {
            return ApiHelper.post(ApiConfig.MANAGE_NOTIFICATION_LIST, data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['notifications'] });
        },
    });
};

export const useUpdateNotificationMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, data }: { id: string; data: ParsedUrlQuery }): Promise<ApiResponse<void>> => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_NOTIFICATION_DETAIL, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['notifications'] });
        },
    });
};

export const useDeleteNotificationMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: string): Promise<ApiResponse<void>> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_NOTIFICATION_DETAIL, { id }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['notifications'] });
        },
    });
};
