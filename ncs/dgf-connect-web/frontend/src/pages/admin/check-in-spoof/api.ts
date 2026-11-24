import { useQuery } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { CheckInVerification } from '@/types/models/check_in';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';

export const useGetSpoofedCheckInsQuery = (params: ParsedUrlQuery, hasPermission: boolean) => {
    return useQuery({
        queryKey: ['admin', 'check-in-spoofed', params],
        queryFn: (): Promise<ApiResponse<PaginatedData<CheckInVerification>>> =>
            ApiHelper.get(ApiConfig.ADMIN_CHECK_IN_SPOOF_LIST, {
                params,
            }),
        enabled: hasPermission,
    });
};
