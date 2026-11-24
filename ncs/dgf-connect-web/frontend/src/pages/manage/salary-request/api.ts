import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { SalaryRequest, SalaryRequestWithRelation } from '@/types/models/salary';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';
import DateHelper from '@/helpers/date.helper';

type GetSalaryRequestResponse = ApiResponse<PaginatedData<SalaryRequest>>;

type GetSalaryInfoResponse = ApiResponse<{
    amount: {
        min: number;
        max: number;
        min_transfer: number;
        max_transfer: number;
    };
    salaries: { month: string; total_amount: number }[];
}>;

type ImportDeductionResponse = ApiResponse<{
    error_data: { line: number; staff_no: string | number | null; error_code: string }[];
    valid_data: Record<string, { total_deductions: number }>;
    skipped_data: { line: number; staff_no: string | number | null }[];
}>;

export const useGetSalaryRequestQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['salary-request', params],
        queryFn: async (): Promise<GetSalaryRequestResponse> => {
            return ApiHelper.get(ApiConfig.MANAGE_SALARY_REQUEST, { params });
        },
        enabled: hasPermission,
    });
};

export const useGetUserSalaryInfo = (userId: string) => {
    return useQuery({
        queryKey: ['salary-info', userId],
        queryFn: async (): Promise<GetSalaryInfoResponse> => {
            return ApiHelper.get(ApiConfig.MANAGE_SALARY_INFO, { params: { user_id: userId } });
        },
        enabled: false,
    });
};

export const useCreateSalaryRequestMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, string | number>) => {
            return ApiHelper.post(ApiConfig.MANAGE_SALARY_REQUEST, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['salary-request'] });
        },
    });
};

export const useGetSalaryRequestDetailQuery = (id: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['salary-request', id],
        queryFn: async (): Promise<ApiResponse<SalaryRequestWithRelation>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SALARY_REQUEST_DETAIL, { id }));
        },
        enabled: hasPermission,
    });
};

export const useApproveSalaryRequestMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async () => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SALARY_REQUEST_APPROVE, { id }), {});
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['salary-request'] });
            await queryClient.invalidateQueries({ queryKey: ['salary-request', id] });
        },
    });
};

export const useRejectSalaryRequestMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (reason: string) => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SALARY_REQUEST_REJECT, { id }), {
                reason,
            });
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['salary-request'] });
            await queryClient.invalidateQueries({ queryKey: ['salary-request', id] });
        },
    });
};

export const useConfirmPaidSalaryRequestMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async () => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SALARY_REQUEST_CONFIRM_PAID, { id }),
                {},
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['salary-request'] });
            await queryClient.invalidateQueries({ queryKey: ['salary-request', id] });
        },
    });
};

export const useImportSalaryDeductionMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: FormData): Promise<ImportDeductionResponse> => {
            return ApiHelper.postForm(ApiConfig.MANAGE_SALARY_IMPORT_DEDUCTION, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['salary-request'] });
        },
    });
};

export const useImportSalaryTransferMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: FormData): Promise<unknown> => {
            return ApiHelper.postForm(ApiConfig.MANAGE_SALARY_IMPORT_TRANSFER, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['salary-request'] });
        },
    });
};

export const useImportSalaryRemainingMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: FormData): Promise<unknown> => {
            return ApiHelper.postForm(ApiConfig.MANAGE_SALARY_IMPORT_REMAINING, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['salary-request'] });
        },
    });
};

export const exportSalaryRequest = async (params: ParsedUrlQuery) => {
    const blob = await ApiHelper.downloadBinary(ApiConfig.MANAGE_SALARY_REQUEST_EXPORT, { params });
    const objectUrl = URL.createObjectURL(blob);
    const link: HTMLAnchorElement = document.createElement('a');
    const now = DateHelper.now().format('YYYYMMDDHHmmss');
    link.href = objectUrl;
    link.download = `salary_request_${now}.xlsx`;
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
};
