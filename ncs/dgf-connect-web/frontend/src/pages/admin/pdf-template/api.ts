import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import i18n from '@/locales/i18n';
import { ApiResponse, PaginatedData } from '@/types/api';
import { DataSource, ExportBatch, ExportBatchItem, PdfTemplate, PdfTemplateDetail } from '@/types/models/pdf_template';
import type { UserExportFilters } from '@/types/user_export';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper, { ApiError } from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

type PdfTemplateId = string | number;
type ExportBatchId = string | number;
type ExportBatchItemId = string | number;

type GetPdfTemplatesResponse = ApiResponse<PaginatedData<PdfTemplate>>;
type GetPdfTemplateDetailResponse = ApiResponse<PdfTemplateDetail>;
type GetDataSourcesResponse = ApiResponse<DataSource[]>;
type PdfTemplateResponse = ApiResponse<PdfTemplate>;
type PdfTemplateDetailResponse = ApiResponse<PdfTemplateDetail>;
type ExportBatchResponse = ApiResponse<ExportBatch>;
type GetExportHistoryResponse = ApiResponse<PaginatedData<ExportBatch>>;
type GetExportBatchItemsResponse = ApiResponse<PaginatedData<ExportBatchItem>>;
type DownloadUrlResponse = ApiResponse<{ download_url?: string; download_urls?: string[] }>;

export type SavePdfTemplateFieldPayload = Pick<
    PdfTemplateDetail['fields'][number],
    | 'id'
    | 'page'
    | 'shape'
    | 'x'
    | 'y'
    | 'width'
    | 'height'
    | 'data_source_key'
    | 'placeholder'
    | 'invert_value'
    | 'fill_mode'
    | 'text_config'
    | 'shape_config'
>;

type SavePdfTemplateFieldsPayload = {
    fields: SavePdfTemplateFieldPayload[];
};

type UpdatePdfTemplatePayload = {
    name: string;
};

type LockTemplatePayload = {
    reason: 'editing';
};

type CreateBatchExportPayload = {
    template_id: number | string;
    user_ids?: Array<number | string>;
    select_all?: boolean;
    filters?: UserExportFilters;
};

const t = i18n.t.bind(i18n);

const buildApiUrl = (uri: string) => {
    return `${ApiConfig.BASE_URL}/${uri}`;
};

const prepareBinaryHeaders = () => {
    const headers: Record<string, string> = {
        'Accept': 'application/pdf',
        'Content-Type': 'application/json',
    };

    if (typeof window !== 'undefined') {
        const token = localStorage.getItem('access_token');

        if (token) {
            headers.Authorization = `Bearer ${token}`;
        }
    }

    return headers;
};

const postBinary = async (uri: string, data: Record<string, any> | null = null): Promise<Blob> => {
    const response = await fetch(buildApiUrl(uri), {
        method: 'POST',
        headers: prepareBinaryHeaders(),
        body: data ? JSON.stringify(data) : null,
    });

    if (!response.ok) {
        let body: Record<string, any> | null = null;

        try {
            body = await response.json();
        } catch {
            body = null;
        }

        throw new ApiError({
            status: response.status,
            message: body?.error ?? 'binary_download_failed',
            data: body?.data ?? {},
        });
    }

    return response.blob();
};

const getDownloadUrls = async (uri: string): Promise<string[]> => {
    const response = await ApiHelper.get<DownloadUrlResponse['data']>(uri);
    const downloadUrls = response.data?.download_urls?.filter(Boolean) ?? [];

    if (downloadUrls.length > 0) {
        return downloadUrls;
    }

    const downloadUrl = response.data?.download_url;

    if (!downloadUrl) {
        throw new Error(t('Download URL not found.'));
    }

    return [downloadUrl];
};

export const openDownloadUrl = (downloadUrl: string) => {
    if (typeof window === 'undefined') {
        return downloadUrl;
    }

    const link = document.createElement('a');
    link.href = downloadUrl;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    document.body.appendChild(link);
    link.click();
    link.remove();

    return downloadUrl;
};

export const useGetPdfTemplatesQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['pdf-templates', params],
        queryFn: async (): Promise<GetPdfTemplatesResponse> => {
            return ApiHelper.get(ApiConfig.ADMIN_PDF_TEMPLATE_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useGetPdfTemplateDetailQuery = (id: PdfTemplateId, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['pdf-template', id],
        queryFn: async (): Promise<GetPdfTemplateDetailResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PDF_TEMPLATE_DETAIL, { id }));
        },
        enabled: !!id && hasPermission,
    });
};

export const useGetDataSourcesQuery = (hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['pdf-template-data-sources'],
        queryFn: async (): Promise<GetDataSourcesResponse> => {
            return ApiHelper.get(ApiConfig.ADMIN_PDF_TEMPLATE_DATA_SOURCES);
        },
        enabled: hasPermission,
    });
};

export const useCreatePdfTemplateMutation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: FormData): Promise<PdfTemplateResponse> => {
            return ApiHelper.postForm(ApiConfig.ADMIN_PDF_TEMPLATE_LIST, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['pdf-templates'] });
        },
    });
};

export const useUpdatePdfTemplateMutation = (id: PdfTemplateId) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: UpdatePdfTemplatePayload): Promise<PdfTemplateResponse> => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PDF_TEMPLATE_DETAIL, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['pdf-templates'] });
            await queryClient.invalidateQueries({ queryKey: ['pdf-template', id] });
        },
    });
};

export const useDeletePdfTemplateMutation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: PdfTemplateId): Promise<ApiResponse<void>> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PDF_TEMPLATE_DETAIL, { id }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['pdf-templates'] });
        },
    });
};

export const useSaveFieldsMutation = (id: PdfTemplateId) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: SavePdfTemplateFieldsPayload): Promise<PdfTemplateDetailResponse> => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PDF_TEMPLATE_FIELDS, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['pdf-templates'] });
            await queryClient.invalidateQueries({ queryKey: ['pdf-template', id] });
        },
    });
};

export const useReUploadPdfMutation = (id: PdfTemplateId) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: FormData): Promise<PdfTemplateResponse> => {
            return ApiHelper.postForm(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PDF_TEMPLATE_RE_UPLOAD, { id }),
                data,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['pdf-templates'] });
            await queryClient.invalidateQueries({ queryKey: ['pdf-template', id] });
        },
    });
};

export const useLockTemplateMutation = (id: PdfTemplateId) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: LockTemplatePayload): Promise<PdfTemplateDetailResponse> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PDF_TEMPLATE_LOCK, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['pdf-template', id] });
        },
    });
};

export const useRefreshLockMutation = (id: PdfTemplateId) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<PdfTemplateDetailResponse> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PDF_TEMPLATE_REFRESH_LOCK, { id }),
                null,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['pdf-template', id] });
        },
    });
};

export const useUnlockTemplateMutation = (id: PdfTemplateId) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<PdfTemplateDetailResponse> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PDF_TEMPLATE_UNLOCK, { id }), null);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['pdf-template', id] });
        },
    });
};

export const usePreviewPdfMutation = (id: PdfTemplateId) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<Blob> => {
            return postBinary(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PDF_TEMPLATE_PREVIEW, { id }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['pdf-template', id] });
        },
    });
};

export const useCreateBatchExportMutation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: CreateBatchExportPayload): Promise<ExportBatchResponse> => {
            return ApiHelper.post(ApiConfig.ADMIN_PDF_EXPORT_BATCH_EXPORT, data);
        },
        onSuccess: async (_response, variables) => {
            await queryClient.invalidateQueries({ queryKey: ['pdf-templates'] });
            await queryClient.invalidateQueries({ queryKey: ['pdf-template', variables.template_id] });
            await queryClient.invalidateQueries({ queryKey: ['export-history'] });
            await queryClient.invalidateQueries({ queryKey: ['user-export-batch'] });
        },
    });
};

export const useGetExportHistoryQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['export-history', params],
        queryFn: async (): Promise<GetExportHistoryResponse> => {
            return ApiHelper.get(ApiConfig.ADMIN_EXPORT_HISTORY, { params });
        },
        enabled: hasPermission,
    });
};

export const useGetUserExportBatchQuery = (
    batchId: ExportBatchId,
    hasPermission: boolean = false,
    options?: { pollWhileInProgress?: boolean; pollInterval?: number },
) => {
    return useQuery({
        queryKey: ['user-export-batch', batchId],
        queryFn: async (): Promise<ExportBatchResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_EXPORT_HISTORY_DETAIL, { id: batchId }));
        },
        enabled: !!batchId && hasPermission,
        refetchInterval: (query) => {
            const batch = query.state.data?.data;
            const isInProgress = batch?.status === 'pending' || batch?.status === 'processing';

            return options?.pollWhileInProgress && isInProgress ? (options.pollInterval ?? 15000) : false;
        },
    });
};

export const useGetExportBatchItemsQuery = (
    batchId: ExportBatchId,
    params: ParsedUrlQuery,
    hasPermission: boolean = false,
    pollInterval: number = 0,
) => {
    return useQuery({
        queryKey: ['pdf-export-batch-items', batchId, params],
        queryFn: async (): Promise<GetExportBatchItemsResponse> => {
            return ApiHelper.get(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PDF_EXPORT_BATCH_ITEMS, { id: batchId }),
                {
                    params,
                },
            );
        },
        enabled: !!batchId && hasPermission,
        refetchInterval: pollInterval > 0 ? pollInterval : false,
    });
};

export const useDownloadExportItem = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (itemId: ExportBatchItemId): Promise<string> => {
            const [downloadUrl] = await getDownloadUrls(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PDF_EXPORT_ITEM_DOWNLOAD, { id: itemId }),
            );

            return openDownloadUrl(downloadUrl);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['export-history'] });
        },
    });
};

export const useDownloadBatchZip = (batchId: ExportBatchId) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<string[]> => {
            return getDownloadUrls(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PDF_EXPORT_BATCH_DOWNLOAD, { id: batchId }),
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['export-history'] });
        },
    });
};
