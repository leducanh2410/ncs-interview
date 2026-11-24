import { useQuery } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import { Role } from '@/types/models/role';
import { User } from '@/types/models/user';
import { WorkplaceSimple } from '@/types/models/workplace';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import { WorkplaceAccess } from '@/pages/global.state';

export const useGetMasterData = (enabled: boolean) => {
    return useQuery({
        queryKey: ['master/all'],
        queryFn: async (): Promise<
            ApiResponse<{
                workplaces: WorkplaceSimple[];
                roles: Role[];
                workplace_access: WorkplaceAccess;
            }>
        > => {
            return ApiHelper.get(ApiConfig.ADMIN_MASTER);
        },
        enabled,
    });
};

export const useSearchUsersQuery = (query: string, workplaceId?: string) => {
    return useQuery({
        queryKey: ['master/users', query],
        queryFn: async (): Promise<ApiResponse<User[]>> => {
            return ApiHelper.get(ApiConfig.ADMIN_MASTER_USER_SEARCH, {
                params: { q: query, workplace_id: workplaceId },
            });
        },
        enabled: query.length > 2,
    });
};

export const useGetRolesQuery = (query: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['master/roles', query],
        queryFn: async (): Promise<ApiResponse<Role[]>> => {
            return ApiHelper.get(ApiConfig.ADMIN_MASTER_ROLES);
        },
        enabled: hasPermission,
    });
};
