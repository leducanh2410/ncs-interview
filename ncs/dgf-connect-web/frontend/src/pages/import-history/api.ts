import { useMutation, useQuery } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { ImportBatch, ImportBatchDetail } from '@/types/models/import_batch';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export const useGetImportHistoryQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['import-history', params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<ImportBatch>>> => {
            return ApiHelper.get(ApiConfig.ADMIN_IMPORT_HISTORY, { params });
        },
        enabled: hasPermission,
    });
};

export const useGetImportBatchQuery = (id: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['import-batch', id],
        queryFn: async (): Promise<ApiResponse<ImportBatchDetail>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_IMPORT_HISTORY_DETAIL, { id }));
        },
        enabled: hasPermission && Boolean(id),
    });
};

export const useGetImportBatchDownloadUrlMutation = () => {
    return useMutation({
        mutationFn: async (id: number | string): Promise<ApiResponse<{ download_url: string }>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_IMPORT_HISTORY_DOWNLOAD, { id }));
        },
    });
};
