import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { CheckIn } from '@/types/models/check_in';
import { ExportBatch } from '@/types/models/pdf_template';
import { Shift } from '@/types/models/shift';
import { WorkHistoryEditRequest } from '@/types/models/work_history_edit_request';
import { WorkTimeReportWithRelation } from '@/types/models/work_time_report';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';
import { EDIT_REQUESTS_QUERY_KEY } from '@/pages/manage/work-history/edit-requests/api';

type GetWorkHistoryResponse = ApiResponse<PaginatedData<CheckIn>>;

export const useGetWorkHistoryQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['work-history', params],
        queryFn: async (): Promise<GetWorkHistoryResponse> => {
            return ApiHelper.get(ApiConfig.MANAGE_WORK_HISTORIES, { params });
        },
        enabled: hasPermission,
    });
};

export const useGetMonthlyWorkHistoryQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['work-history/monthly', params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<WorkTimeReportWithRelation>>> => {
            return ApiHelper.get(ApiConfig.MANAGE_WORK_HISTORIES_MONTHLY, { params });
        },
        enabled: hasPermission,
    });
};

export const useUpdateCheckInMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: {
            id: string;
            data: Record<string, any>;
        }): Promise<ApiResponse<WorkHistoryEditRequest>> => {
            return ApiHelper.patch(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_WORK_HISTORIES_DETAIL, { id: data.id }),
                data.data,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['work-history'] });
            await queryClient.invalidateQueries({ queryKey: EDIT_REQUESTS_QUERY_KEY });
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts'] });
            await queryClient.invalidateQueries({ queryKey: ['report'] });
        },
    });
};

// Shared between the inline "Duyệt" action on Work_History_List and the dedicated
// Work_History_Edit_Request_List page, so an approve from either place refreshes both views.
export const useApproveWorkHistoryEditRequestMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            id,
            force = false,
        }: {
            id: number;
            force?: boolean;
        }): Promise<ApiResponse<WorkHistoryEditRequest>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_WORK_HISTORY_EDIT_REQUESTS_APPROVE, { id }),
                force ? { force } : {},
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['work-history'] });
            await queryClient.invalidateQueries({ queryKey: EDIT_REQUESTS_QUERY_KEY });
        },
    });
};

export const useAddUsersToShiftMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: { shiftId: string; userIds: string[] }) => {
            return ApiHelper.post(ApiConfig.MANAGE_WORK_HISTORIES_ADD, {
                shift_id: data.shiftId,
                user_ids: data.userIds,
            });
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['work-history'] });
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts'] });
            await queryClient.invalidateQueries({ queryKey: ['report'] });
        },
    });
};

export const useGetShiftsSimpleQuery = (date: string, workplaceId: string) => {
    return useQuery({
        queryKey: ['shifts/simple', date, workplaceId],
        queryFn: async (): Promise<ApiResponse<Shift[]>> => {
            return ApiHelper.get(ApiConfig.MANAGE_SHIFT_LIST_SIMPLE, { params: { date, workplace_id: workplaceId } });
        },
        enabled: !!date && !!workplaceId,
    });
};

export const requestDetailReportExport = async (params: ParsedUrlQuery): Promise<ApiResponse<ExportBatch>> => {
    return ApiHelper.post(ApiConfig.MANAGE_WORK_HISTORIES_EXPORT, params);
};

export const exportMonthlyReport = async (month: string, params: ParsedUrlQuery) => {
    const blob = await ApiHelper.downloadBinary(ApiConfig.MANAGE_WORK_HISTORIES_MONTHLY_EXPORT, { params });
    const objectUrl = URL.createObjectURL(blob);
    const link: HTMLAnchorElement = document.createElement('a');
    link.href = objectUrl;
    link.download = `work_history_${month}.xlsx`;
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
};
