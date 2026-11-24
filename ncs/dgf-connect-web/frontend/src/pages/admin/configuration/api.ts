import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse } from '@/types/api';
import { Config } from '@/types/models/config';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';

type ConfigResponse = ApiResponse<Config>;

export const useGetConfigsQuery = (hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['config'],
        queryFn: async (): Promise<ConfigResponse> => {
            return ApiHelper.get(ApiConfig.ADMIN_CONFIGS);
        },
        enabled: hasPermission,
    });
};

export const useUpdateSettingMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery): Promise<ConfigResponse> => {
            return ApiHelper.put(ApiConfig.ADMIN_CONFIGS, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['config'] });
        },
    });
};
