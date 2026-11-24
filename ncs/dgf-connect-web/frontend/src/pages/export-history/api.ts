import { useMutation } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

type DownloadUrlResponse = ApiResponse<{ download_url: string }>;

export const useGetWorkHistoryExportDownloadUrlMutation = () => {
    return useMutation({
        mutationFn: async (id: number | string): Promise<DownloadUrlResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_EXPORT_HISTORY_DOWNLOAD, { id }));
        },
    });
};
