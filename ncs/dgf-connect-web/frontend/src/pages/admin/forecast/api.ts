import { useMutation, useQuery } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import { ForecastOptionsResponse, ForecastRequest, ForecastResponse } from '@/pages/admin/forecast/types';

export const useForecastOptionsQuery = () => {
    return useQuery({
        queryKey: ['forecast', 'options'],
        queryFn: async (): Promise<ApiResponse<ForecastOptionsResponse>> => {
            return ApiHelper.get(ApiConfig.ADMIN_FORECAST_OPTIONS);
        },
    });
};

export const useGenerateForecastMutation = () => {
    return useMutation({
        mutationFn: async (data: ForecastRequest): Promise<ApiResponse<ForecastResponse>> => {
            return ApiHelper.post(ApiConfig.ADMIN_FORECAST, data);
        },
    });
};
