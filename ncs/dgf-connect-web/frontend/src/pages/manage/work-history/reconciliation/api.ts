import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { ReconciliationBatch } from '@/types/models/reconciliation_batch';
import { ReconciliationUserSummary } from '@/types/models/reconciliation_row';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export const RECONCILIATION_QUERY_KEY = ['work-history-reconciliations'];

export const useGetReconciliationBatchesQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: [...RECONCILIATION_QUERY_KEY, params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<ReconciliationBatch>>> => {
            return ApiHelper.get(ApiConfig.MANAGE_WORK_HISTORY_RECONCILIATIONS, { params });
        },
        enabled: hasPermission,
    });
};

export const useGetReconciliationBatchQuery = (id: string | undefined, hasPermission: boolean) => {
    return useQuery({
        queryKey: [...RECONCILIATION_QUERY_KEY, id],
        queryFn: async (): Promise<ApiResponse<ReconciliationBatch>> => {
            return ApiHelper.get(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_WORK_HISTORY_RECONCILIATIONS_DETAIL, { id: id! }),
            );
        },
        enabled: hasPermission && Boolean(id),
    });
};

export const useCreateReconciliationBatchMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (form: FormData): Promise<ApiResponse<ReconciliationBatch>> => {
            return ApiHelper.postForm(ApiConfig.MANAGE_WORK_HISTORY_RECONCILIATIONS, form);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: RECONCILIATION_QUERY_KEY });
        },
    });
};

export const usePatchReconciliationRowMutation = (batchId: number) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (payload: {
            rowId: number;
            data: Record<string, unknown>;
        }): Promise<ApiResponse<ReconciliationBatch>> => {
            return ApiHelper.patch(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_WORK_HISTORY_RECONCILIATIONS_ROW, {
                    id: batchId,
                    rowId: payload.rowId,
                }),
                payload.data,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [...RECONCILIATION_QUERY_KEY, String(batchId)] });
            await queryClient.invalidateQueries({ queryKey: RECONCILIATION_QUERY_KEY });
        },
    });
};

export const useBulkDecideReconciliationMutation = (batchId: number) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (side: 'amazon' | 'dgf'): Promise<ApiResponse<ReconciliationBatch>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_WORK_HISTORY_RECONCILIATIONS_BULK_DECIDE, {
                    id: batchId,
                }),
                { side },
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [...RECONCILIATION_QUERY_KEY, String(batchId)] });
        },
    });
};

export const useConfirmReconciliationMutation = (batchId: number) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<ApiResponse<ReconciliationBatch>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_WORK_HISTORY_RECONCILIATIONS_CONFIRM, { id: batchId }),
                {},
            );
        },
        // Confirm persists the refreshed compare_status/decisions before it can
        // fail with RECONCILIATION_DECISIONS_INCOMPLETE/RECONCILIATION_DUPLICATE_CHECK_IN,
        // so the batch must be refetched on error too, not only on success —
        // otherwise the screen keeps showing pre-refresh values that no longer
        // match what the server just saved.
        onSettled: async () => {
            await queryClient.invalidateQueries({ queryKey: [...RECONCILIATION_QUERY_KEY, String(batchId)] });
            await queryClient.invalidateQueries({ queryKey: RECONCILIATION_QUERY_KEY });
        },
    });
};

export const useExportReconciliationMutation = (batchId: number) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<void> => {
            const exported: ApiResponse<{ filename: string; content_type: string }> = await ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_WORK_HISTORY_RECONCILIATIONS_EXPORT, { id: batchId }),
                {},
            );
            const blob = await ApiHelper.downloadBinary(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_WORK_HISTORY_RECONCILIATIONS_EXPORT, { id: batchId }),
            );
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = exported.data?.filename ?? `reconciliation-${batchId}.xlsx`;
            anchor.click();
            anchor.remove();
            URL.revokeObjectURL(url);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [...RECONCILIATION_QUERY_KEY, String(batchId)] });
        },
    });
};

export const downloadReconciliationOriginal = async (batchId: number, filename: string): Promise<void> => {
    const blob = await ApiHelper.downloadBinary(
        CommonHelper.replaceUrlParam(ApiConfig.MANAGE_WORK_HISTORY_RECONCILIATIONS_ORIGINAL, { id: batchId }),
    );
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
};

export const searchReconciliationUsers = async (
    q: string,
    workDate?: string,
): Promise<ApiResponse<ReconciliationUserSummary[]>> => {
    return ApiHelper.get(ApiConfig.MANAGE_WORK_HISTORY_RECONCILIATIONS_USERS, {
        params: { q, work_date: workDate },
    });
};
