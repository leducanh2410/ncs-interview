import { useMutation, useQuery } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import {
    ShiftOrderConfirmDecision,
    ShiftOrderConfirmResult,
    ShiftOrderImportPreviewResponse,
} from '@/types/models/import_batch';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export const useCreateShiftOrderPreviewMutation = () => {
    return useMutation({
        mutationFn: async (data: FormData): Promise<ApiResponse<ShiftOrderImportPreviewResponse>> => {
            return ApiHelper.postForm(ApiConfig.MANAGE_SHIFT_ORDER_IMPORT_PREVIEW, data);
        },
    });
};

export const useGetShiftOrderPreviewQuery = (id: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['shift-order-import', id],
        queryFn: async (): Promise<ApiResponse<ShiftOrderImportPreviewResponse>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_ORDER_IMPORT_DETAIL, { id }));
        },
        enabled: hasPermission && Boolean(id),
    });
};

export const useUpdateShiftOrderMonthMutation = (id: string) => {
    return useMutation({
        mutationFn: async (month: string): Promise<ApiResponse<ShiftOrderImportPreviewResponse>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_ORDER_IMPORT_MONTH, { id }), {
                month,
            });
        },
    });
};

export const useConfirmShiftOrderImportMutation = (id: string) => {
    return useMutation({
        mutationFn: async (decisions: ShiftOrderConfirmDecision[]): Promise<ApiResponse<ShiftOrderConfirmResult>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_ORDER_IMPORT_CONFIRM, { id }), {
                decisions,
            });
        },
    });
};
