import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { DocumentReadConfirmation } from '@/types/models/document_read_confirmation';
import { DocumentRequiredConfig } from '@/types/models/document_required_config';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

type ConfigListResponse = ApiResponse<PaginatedData<DocumentRequiredConfig>>;
type ConfigResponse = ApiResponse<DocumentRequiredConfig>;
type VoidResponse = ApiResponse<void>;
type BackfillCountResponse = ApiResponse<{ affected_count: number }>;
type ConfirmationsResponse = ApiResponse<PaginatedData<DocumentReadConfirmation>>;
type StatsResponse = ApiResponse<{
    eligible_count: number;
    confirmed_count: number;
    pending_count: number;
    confirmation_rate: number;
}>;

export const useDocumentReadConfigsQuery = (params: ParsedUrlQuery, enabled: boolean = true) => {
    return useQuery({
        queryKey: ['document-read-configs', params],
        queryFn: async (): Promise<ConfigListResponse> => {
            return ApiHelper.get(ApiConfig.MANAGE_DOCUMENT_READ_CONFIG_LIST, { params });
        },
        enabled,
    });
};

export const useDocumentReadConfigQuery = (id: number | string | null, enabled: boolean = true) => {
    return useQuery({
        queryKey: ['document-read-config', id],
        queryFn: async (): Promise<ConfigResponse> => {
            return ApiHelper.get(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_DOCUMENT_READ_CONFIG_DETAIL, { id: id! }),
            );
        },
        enabled: enabled && !!id,
    });
};

export const useCreateDocumentReadConfigMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, any>): Promise<ConfigResponse> => {
            return ApiHelper.post(ApiConfig.MANAGE_DOCUMENT_READ_CONFIG_LIST, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['document-read-configs'] });
        },
    });
};

export const useCreateWorkplaceDocumentReadConfigMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            workplaceId,
            data,
        }: {
            workplaceId: number | string;
            data: Record<string, any>;
        }): Promise<ConfigResponse> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_DOCUMENT_READ_CONFIG_LIST, {
                    id: workplaceId,
                }),
                data,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['document-read-configs'] });
        },
    });
};

export const useUpdateDocumentReadConfigMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            id,
            data,
        }: {
            id: number | string;
            data: Record<string, any>;
        }): Promise<ConfigResponse> => {
            return ApiHelper.put(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_DOCUMENT_READ_CONFIG_DETAIL, { id }),
                data,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['document-read-configs'] });
        },
    });
};

export const useUpdateWorkplaceDocumentReadConfigMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            workplaceId,
            configId,
            data,
        }: {
            workplaceId: number | string;
            configId: number | string;
            data: Record<string, any>;
        }): Promise<ConfigResponse> => {
            return ApiHelper.put(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_DOCUMENT_READ_CONFIG_DETAIL, {
                    id: workplaceId,
                    configId,
                }),
                data,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['document-read-configs'] });
        },
    });
};

export const useDeleteDocumentReadConfigMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: number | string): Promise<VoidResponse> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_DOCUMENT_READ_CONFIG_DETAIL, { id }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['document-read-configs'] });
        },
    });
};

export const useBackfillCountQuery = (configId: number | string | null, enabled: boolean = true) => {
    return useQuery({
        queryKey: ['document-read-config-backfill-count', configId],
        queryFn: async (): Promise<BackfillCountResponse> => {
            return ApiHelper.get(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_DOCUMENT_READ_CONFIG_BACKFILL_COUNT, {
                    id: configId!,
                }),
            );
        },
        enabled: enabled && !!configId,
    });
};

export const useTriggerBackfillMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: number | string): Promise<BackfillCountResponse> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_DOCUMENT_READ_CONFIG_BACKFILL, { id }),
                {},
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['document-read-configs'] });
        },
    });
};

export const useConfirmationsQuery = (configId: number | string, params: ParsedUrlQuery, enabled: boolean = true) => {
    return useQuery({
        queryKey: ['document-read-config-confirmations', configId, params],
        queryFn: async (): Promise<ConfirmationsResponse> => {
            return ApiHelper.get(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_DOCUMENT_READ_CONFIG_CONFIRMATIONS, {
                    id: configId,
                }),
                { params },
            );
        },
        enabled: enabled && !!configId,
    });
};

export const useAllConfirmationsQuery = (params: ParsedUrlQuery, enabled: boolean = true) => {
    return useQuery({
        queryKey: ['document-read-confirmations', params],
        queryFn: async (): Promise<ConfirmationsResponse> => {
            return ApiHelper.get(ApiConfig.MANAGE_DOCUMENT_READ_CONFIRMATIONS, { params });
        },
        enabled,
    });
};

export const useDocumentReadConfigStatsQuery = (configId: number | string | null, enabled: boolean = true) => {
    return useQuery({
        queryKey: ['document-read-config-stats', configId],
        queryFn: async (): Promise<StatsResponse> => {
            return ApiHelper.get(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_DOCUMENT_READ_CONFIG_STATS, {
                    id: configId!,
                }),
            );
        },
        enabled: enabled && !!configId,
    });
};
