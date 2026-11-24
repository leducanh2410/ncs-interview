import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import { CompanyHolidayCalendar } from '@/types/models/company_holiday';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

const QUERY_KEY = ['company-holidays'];

export const useGetCompanyHolidaysQuery = (year: number, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: [...QUERY_KEY, year],
        queryFn: async (): Promise<ApiResponse<CompanyHolidayCalendar>> => {
            return ApiHelper.get(ApiConfig.ADMIN_COMPANY_HOLIDAY_LIST, { params: { year: year.toString() } });
        },
        enabled: hasPermission,
    });
};

export const useGenerateCompanyHolidaysMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (year: number): Promise<ApiResponse<{ inserted: number }>> => {
            return ApiHelper.post(ApiConfig.ADMIN_COMPANY_HOLIDAY_GENERATE, { year });
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
    });
};

export type CompanyHolidayFormData = {
    date: string;
    name: string;
};

export const useCreateCompanyHolidayMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: CompanyHolidayFormData): Promise<ApiResponse<void>> => {
            return ApiHelper.post(ApiConfig.ADMIN_COMPANY_HOLIDAY_CREATE, data);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
    });
};

export const useUpdateCompanyHolidayMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, data }: { id: number; data: CompanyHolidayFormData }): Promise<ApiResponse<void>> => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_COMPANY_HOLIDAY_DETAIL, { id }), data);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
    });
};

export const useDeleteCompanyHolidayMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<ApiResponse<void>> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_COMPANY_HOLIDAY_DETAIL, { id }));
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
    });
};

export const useUpdateCompanyHolidaySettingMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: { year: number; exclude_weekends: boolean }): Promise<ApiResponse<void>> => {
            return ApiHelper.put(ApiConfig.ADMIN_COMPANY_HOLIDAY_SETTINGS, data);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
    });
};
