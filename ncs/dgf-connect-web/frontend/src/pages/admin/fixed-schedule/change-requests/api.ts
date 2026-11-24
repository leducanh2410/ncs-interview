import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { FixedScheduleChangeRequest } from '@/types/models/fixed_schedule_change_request';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';
import DateHelper from '@/helpers/date.helper';

const QUERY_KEY = ['fixed-schedule-change-requests'];

export const useGetFixedScheduleChangeRequestsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: [...QUERY_KEY, params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<FixedScheduleChangeRequest>>> => {
            return ApiHelper.get(ApiConfig.ADMIN_FIXED_SCHEDULE_CHANGE_REQUEST_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useApproveFixedScheduleChangeRequestMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<ApiResponse<FixedScheduleChangeRequest>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_FIXED_SCHEDULE_CHANGE_REQUEST_APPROVE, { id }),
                {},
            );
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: QUERY_KEY });
        },
    });
};

export const useRejectFixedScheduleChangeRequestMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<ApiResponse<FixedScheduleChangeRequest>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_FIXED_SCHEDULE_CHANGE_REQUEST_REJECT, { id }),
                {},
            );
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: QUERY_KEY });
        },
    });
};

export const useBulkResolveFixedScheduleChangeRequestsMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: {
            ids: number[];
            action: 'approve' | 'reject';
        }): Promise<ApiResponse<{ resolved: FixedScheduleChangeRequest[] }>> => {
            return ApiHelper.post(ApiConfig.ADMIN_FIXED_SCHEDULE_CHANGE_REQUEST_BULK_RESOLVE, data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: QUERY_KEY });
        },
    });
};

export const exportFixedScheduleChangeRequests = async (params: ParsedUrlQuery): Promise<void> => {
    const blob = await ApiHelper.downloadBinary(ApiConfig.ADMIN_FIXED_SCHEDULE_CHANGE_REQUEST_EXPORT, { params });
    const objectUrl = URL.createObjectURL(blob);
    const link: HTMLAnchorElement = document.createElement('a');
    const now = DateHelper.now().format('YYYYMMDDHHmmss');
    link.href = objectUrl;
    link.download = `fixed_schedule_change_requests_${now}.xlsx`;
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
};
