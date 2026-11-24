import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { ParamType } from '@/types/form';
import { SalaryProcess } from '@/types/models/salary';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

type GetSalaryProcessesResponse = ApiResponse<PaginatedData<SalaryProcess>>;

export const useGetSalaryProcessesQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['salary-process', params],
        queryFn: async (): Promise<GetSalaryProcessesResponse> => {
            return ApiHelper.get(ApiConfig.MANAGE_SALARY_PROCESSES, { params });
        },
        enabled: hasPermission,
    });
};

export const useCreateSalaryProcessMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, ParamType>) => {
            return ApiHelper.post(ApiConfig.MANAGE_SALARY_PROCESSES, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['salary-process'] });
        },
    });
};

export const useUpdateSalaryProcessMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, data }: { id: string | number; data: Record<string, ParamType> }) => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SALARY_PROCESS_DETAIL, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['salary-process'] });
        },
    });
};

export const useDeleteSalaryProcessMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: number) => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SALARY_PROCESS_DETAIL, { id }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['salary-process'] });
        },
    });
};
