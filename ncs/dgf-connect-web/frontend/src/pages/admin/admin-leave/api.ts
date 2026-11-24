import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { AdminLeaveRequest, AdminLeaveRequestDetail } from '@/types/models/admin_leave_request';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';
import DateHelper, { DATE_STORE_FORMAT } from '@/helpers/date.helper';

export const QUERY_KEY = ['admin-leave-requests'];
export const DETAIL_QUERY_KEY = ['admin-leave-request-detail'];

const normalizeParams = (params: ParsedUrlQuery): ParsedUrlQuery => {
    const normalizedParams: ParsedUrlQuery = { ...params };
    const dates = normalizedParams.dates;

    delete normalizedParams.dates;

    if (Array.isArray(dates)) {
        if (dates[0]) {
            normalizedParams.date_from = dates[0];
        }
        if (dates[1]) {
            normalizedParams.date_to = dates[1];
        }
    } else if (dates) {
        normalizedParams.date_from = dates;
    }

    // Requirement 7.10: when no filter is supplied, the date range defaults to the current
    // month rather than showing every request ever submitted.
    if (!normalizedParams.date_from && !normalizedParams.date_to) {
        const now = DateHelper.now();
        normalizedParams.date_from = now.startOf('month').format(DATE_STORE_FORMAT);
        normalizedParams.date_to = now.endOf('month').format(DATE_STORE_FORMAT);
    }

    if (!normalizedParams.requester_id) {
        delete normalizedParams.requester_id;
    }

    return normalizedParams;
};

export const useGetAdminLeaveRequestsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: [...QUERY_KEY, params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<AdminLeaveRequest>>> => {
            return ApiHelper.get(ApiConfig.ADMIN_LEAVE_REQUEST_LIST, { params: normalizeParams(params) });
        },
        enabled: hasPermission,
    });
};

export const useGetAdminLeaveRequestDetailQuery = (id: number | null, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: [...DETAIL_QUERY_KEY, id],
        queryFn: async (): Promise<ApiResponse<AdminLeaveRequestDetail>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_LEAVE_REQUEST_DETAIL, { id: id! }));
        },
        enabled: hasPermission && id !== null,
        // Attachment URLs are time-limited: refetch on every open instead of trusting the
        // 5-minute default staleTime, so the modal never shows an expired link.
        staleTime: 0,
    });
};

export const useApproveAdminLeaveRequestMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<ApiResponse<AdminLeaveRequest>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_LEAVE_REQUEST_APPROVE, { id }), {});
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: QUERY_KEY });
            queryClient.invalidateQueries({ queryKey: DETAIL_QUERY_KEY });
        },
    });
};

export const useRejectAdminLeaveRequestMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            id,
            reject_reason,
        }: {
            id: number;
            reject_reason: string;
        }): Promise<ApiResponse<AdminLeaveRequest>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_LEAVE_REQUEST_REJECT, { id }), {
                reject_reason,
            });
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: QUERY_KEY });
            queryClient.invalidateQueries({ queryKey: DETAIL_QUERY_KEY });
        },
    });
};
