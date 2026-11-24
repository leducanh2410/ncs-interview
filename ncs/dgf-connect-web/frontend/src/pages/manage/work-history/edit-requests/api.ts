import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { WorkHistoryEditRequest, WorkHistoryEditRequestField } from '@/types/models/work_history_edit_request';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export const EDIT_REQUESTS_QUERY_KEY = ['work-history-edit-requests'];

export interface ImportPreviewItem {
    field: WorkHistoryEditRequestField;
    old_value: string | null;
    new_value: string | null;
    // break_minutes only: the effective "before" value (falls through to the shift/workplace
    // default when there is no per-check-in override), for display purposes only - old_value
    // itself must stay the raw check-in column so the commit-time drift check keeps comparing
    // against it. Not sent back to the commit endpoint's validated fields.
    old_value_display?: string | null;
}

export interface ImportPreviewRow {
    check_in_id: number;
    user_id: number;
    employee_name: string;
    employee_avatar: string | null;
    workplace_id: number;
    workplace_name: string;
    date: string;
    items: ImportPreviewItem[];
}

export type ImportPreviewResponse = ApiResponse<{ rows: ImportPreviewRow[] }>;

export interface ImportCommitRow {
    check_in_id: number;
    items: ImportPreviewItem[];
    decision: 'apply' | 'reject';
    reject_reason?: string;
}

export type ImportCommitResponse = ApiResponse<{ applied_count: number; rejected_count: number }>;

export const useGetWorkHistoryEditRequestsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: [...EDIT_REQUESTS_QUERY_KEY, params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<WorkHistoryEditRequest>>> => {
            return ApiHelper.get(ApiConfig.MANAGE_WORK_HISTORY_EDIT_REQUESTS, { params });
        },
        enabled: hasPermission,
    });
};

export const useRejectWorkHistoryEditRequestMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: {
            id: number;
            reject_reason: string;
        }): Promise<ApiResponse<WorkHistoryEditRequest>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_WORK_HISTORY_EDIT_REQUESTS_REJECT, { id: data.id }),
                { reject_reason: data.reject_reason },
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: EDIT_REQUESTS_QUERY_KEY });
            await queryClient.invalidateQueries({ queryKey: ['work-history'] });
        },
    });
};

export const useBulkResolveWorkHistoryEditRequestsMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: {
            ids: number[];
            action: 'approve' | 'reject';
            reject_reason?: string;
        }): Promise<ApiResponse<{ resolved: WorkHistoryEditRequest[] }>> => {
            return ApiHelper.post(ApiConfig.MANAGE_WORK_HISTORY_EDIT_REQUESTS_BULK_RESOLVE, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: EDIT_REQUESTS_QUERY_KEY });
            await queryClient.invalidateQueries({ queryKey: ['work-history'] });
        },
    });
};

// A structural or content import error never reaches react-query's normal onError handling as a
// generic failure toast - the caller (ImportModal) needs the raw ApiError (message + data) to
// render Requirement 10's row-by-row breakdown, so this mutation intentionally does not swallow
// or transform the error.
export const usePreviewImportWorkHistoryEditRequestMutation = () => {
    return useMutation({
        mutationFn: async (file: File): Promise<ImportPreviewResponse> => {
            const formData = new FormData();
            formData.append('file', file);

            return ApiHelper.postForm(ApiConfig.MANAGE_WORK_HISTORY_EDIT_REQUESTS_IMPORT_PREVIEW, formData);
        },
    });
};

export const useCommitImportWorkHistoryEditRequestMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (rows: ImportCommitRow[]): Promise<ImportCommitResponse> => {
            return ApiHelper.post(ApiConfig.MANAGE_WORK_HISTORY_EDIT_REQUESTS_IMPORT_COMMIT, { rows });
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: EDIT_REQUESTS_QUERY_KEY });
            await queryClient.invalidateQueries({ queryKey: ['work-history'] });
        },
    });
};
