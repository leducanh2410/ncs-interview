import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import { CsvExportBatch, CsvPropertyMeta } from '@/types/models/csv_export';
import type { UserExportFilters } from '@/types/user_export';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

type CsvExportBatchId = string | number;

type GetCsvPropertiesResponse = ApiResponse<CsvPropertyMeta[]>;
type CsvExportBatchResponse = ApiResponse<CsvExportBatch>;
type CsvDownloadUrlResponse = ApiResponse<{ download_url?: string }>;

type CreateCsvExportPayload = {
    selected_keys: string[];
    ordered_keys: string[];
    encoding?: string;
    user_ids?: number[];
    select_all?: boolean;
    filters?: UserExportFilters;
};

export const useGetCsvPropertiesQuery = (hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['csv-export-properties'],
        queryFn: async (): Promise<GetCsvPropertiesResponse> => {
            return ApiHelper.get(ApiConfig.ADMIN_CSV_EXPORT_PROPERTIES);
        },
        enabled: hasPermission,
        staleTime: Infinity,
    });
};

export const useCreateCsvExportMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: CreateCsvExportPayload): Promise<CsvExportBatchResponse> => {
            return ApiHelper.post(ApiConfig.ADMIN_CSV_EXPORT_BATCH, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['export-history'] });
        },
    });
};

export const useGetCsvExportDownloadUrlMutation = () => {
    return useMutation({
        mutationFn: async (id: CsvExportBatchId): Promise<CsvDownloadUrlResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CSV_EXPORT_BATCH_DOWNLOAD, { id }));
        },
    });
};
