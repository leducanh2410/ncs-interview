import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { BankAccount, BankAccountDetail, BankAccountListRow } from '@/types/models/bank_account';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export const BANK_ACCOUNTS_QUERY_KEY = 'admin-bank-accounts';

export const useGetBankAccountsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: [BANK_ACCOUNTS_QUERY_KEY, params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<BankAccountListRow>>> => {
            return ApiHelper.get(ApiConfig.ADMIN_BANK_ACCOUNT_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useGetBankAccountDetailQuery = (id: number | null, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: [BANK_ACCOUNTS_QUERY_KEY, 'detail', id],
        queryFn: async (): Promise<ApiResponse<BankAccountDetail>> => {
            return ApiHelper.get(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_BANK_ACCOUNT_DETAIL, { id: id as number }),
            );
        },
        enabled: hasPermission && id !== null,
    });
};

export const useApproveBankAccountMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<ApiResponse<BankAccount>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_BANK_ACCOUNT_APPROVE, { id }), {});
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [BANK_ACCOUNTS_QUERY_KEY] });
        },
    });
};

export const useRejectBankAccountMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, note }: { id: number; note: string }): Promise<ApiResponse<BankAccount>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_BANK_ACCOUNT_REJECT, { id }), { note });
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [BANK_ACCOUNTS_QUERY_KEY] });
        },
    });
};
