import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import { ExcelExportLimits, ExcelExportPreview } from '@/types/models/pdf_export_excel';
import { ExportBatch } from '@/types/models/pdf_template';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';
import { openDownloadUrl } from '@/pages/admin/pdf-template/api';

type ExcelExportPreviewResponse = ApiResponse<ExcelExportPreview>;
type ExcelExportLimitsResponse = ApiResponse<ExcelExportLimits>;
type ExportBatchResponse = ApiResponse<ExportBatch>;
type SourceFileDownloadResponse = ApiResponse<{ download_url: string; file_name: string | null }>;

export type ExcelExportPayload = {
    templateId: number | string;
    file: File;
};

const SAMPLE_FILE_NAME = 'pdf_export_user_list_sample.xlsx';

const buildFormData = ({ templateId, file }: ExcelExportPayload) => {
    const formData = new FormData();
    formData.append('template_id', String(templateId));
    formData.append('file', file);

    return formData;
};

const saveBlob = (blob: Blob, fileName: string) => {
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
};

export const useGetExcelExportLimitsQuery = (hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['pdf-export-excel-limits'],
        queryFn: async (): Promise<ExcelExportLimitsResponse> => {
            return ApiHelper.get(ApiConfig.ADMIN_PDF_EXPORT_EXCEL_LIMITS);
        },
        enabled: hasPermission,
    });
};

export const usePreviewExcelExportMutation = () => {
    return useMutation({
        mutationFn: async (payload: ExcelExportPayload): Promise<ExcelExportPreviewResponse> => {
            return ApiHelper.postForm(ApiConfig.ADMIN_PDF_EXPORT_EXCEL_PREVIEW, buildFormData(payload));
        },
    });
};

export const useCreateExcelBatchExportMutation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (payload: ExcelExportPayload): Promise<ExportBatchResponse> => {
            return ApiHelper.postForm(ApiConfig.ADMIN_PDF_EXPORT_EXCEL_BATCH_EXPORT, buildFormData(payload));
        },
        onSuccess: async (_response, variables) => {
            await queryClient.invalidateQueries({ queryKey: ['pdf-templates'] });
            await queryClient.invalidateQueries({ queryKey: ['pdf-template', variables.templateId] });
            await queryClient.invalidateQueries({ queryKey: ['export-history'] });
            await queryClient.invalidateQueries({ queryKey: ['user-export-batch'] });
        },
    });
};

export const useDownloadExcelSampleMutation = () => {
    return useMutation({
        mutationFn: async (): Promise<void> => {
            const blob = await ApiHelper.downloadBinary(ApiConfig.ADMIN_PDF_EXPORT_EXCEL_SAMPLE);
            saveBlob(blob, SAMPLE_FILE_NAME);
        },
    });
};

export const useDownloadSourceFileMutation = () => {
    return useMutation({
        mutationFn: async (batchId: number | string): Promise<string> => {
            const response: SourceFileDownloadResponse = await ApiHelper.get(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_EXPORT_HISTORY_SOURCE_FILE, { id: batchId }),
            );
            const downloadUrl = response.data?.download_url;

            if (!downloadUrl) {
                throw new Error('download_url_missing');
            }

            return openDownloadUrl(downloadUrl);
        },
    });
};
