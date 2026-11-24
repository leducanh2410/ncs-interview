import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse } from '@/types/api';
import { Role, RoleWithPermissions } from '@/types/models/role';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export const useGetRolesQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['roles', params],
        queryFn: async (): Promise<ApiResponse<Role[]>> => {
            return ApiHelper.get(ApiConfig.ADMIN_ROLE_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useGetRoleQuery = (id: string | number) => {
    return useQuery({
        queryKey: ['role', id],
        queryFn: async (): Promise<ApiResponse<RoleWithPermissions>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_ROLE_DETAIL, { id }));
        },
    });
};

export const useCreateRoleMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, any>): Promise<ApiResponse<any>> => {
            return ApiHelper.post(ApiConfig.ADMIN_ROLE_LIST, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['roles'] });
            await queryClient.invalidateQueries({ queryKey: ['master/all'] });
        },
    });
};

export const useUpdateRoleMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, any>): Promise<ApiResponse<any>> => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_ROLE_DETAIL, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['roles'] });
            await queryClient.invalidateQueries({ queryKey: ['role', id] });
            await queryClient.invalidateQueries({ queryKey: ['master/all'] });
        },
    });
};

export const useDeleteRoleMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: string | number): Promise<ApiResponse<any>> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_ROLE_DETAIL, { id }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['roles'] });
            await queryClient.invalidateQueries({ queryKey: ['master/all'] });
        },
    });
};
