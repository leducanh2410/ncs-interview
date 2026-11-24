import { useQuery } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { MonthlyReport, ShiftReportWithRelation } from '@/types/models/shift_report';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';

export const useGetReportsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['report', params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<ShiftReportWithRelation>>> => {
            return ApiHelper.get(ApiConfig.MANAGE_REPORT_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useGetMonthlyReportsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['report/monthly', params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<MonthlyReport>>> => {
            return ApiHelper.get(ApiConfig.MANAGE_MONTHLY_REPORT_LIST, { params });
        },
        enabled: hasPermission,
    });
};
