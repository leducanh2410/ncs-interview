import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { CreateGroupPayload, Group, GroupDetail, UpdateGroupPayload } from '@/types/group';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

type GetGroupsResponse = ApiResponse<PaginatedData<Group>>;
type GetGroupResponse = ApiResponse<GroupDetail>;
type GetUserGroupsResponse = ApiResponse<Group[]>;

export const useGetGroupsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['groups', params],
        queryFn: async (): Promise<GetGroupsResponse> => {
            return ApiHelper.get(ApiConfig.ADMIN_GROUP_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useGetGroupQuery = (id: string | number, params: ParsedUrlQuery = {}, hasPermission: boolean = true) => {
    return useQuery({
        queryKey: ['group', String(id), params],
        queryFn: async (): Promise<GetGroupResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_GROUP_DETAIL, { id }), { params });
        },
        enabled: hasPermission,
    });
};

export const useGetUserGroupsQuery = (id: string | number, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['user-groups', id],
        queryFn: async (): Promise<GetUserGroupsResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_GROUP_LIST, { id }));
        },
        enabled: hasPermission,
    });
};

export const useCreateGroupMutation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: CreateGroupPayload): Promise<ApiResponse<Group>> => {
            return ApiHelper.post(ApiConfig.ADMIN_GROUP_LIST, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['groups'] });
        },
    });
};

export const useUpdateGroupMutation = (id: string | number) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: UpdateGroupPayload): Promise<ApiResponse<Group>> => {
            return ApiHelper.patch(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_GROUP_DETAIL, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['groups'] });
            await queryClient.invalidateQueries({ queryKey: ['group', String(id)] });
        },
    });
};

export const useDeleteGroupMutation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: string | number): Promise<ApiResponse<null>> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_GROUP_DETAIL, { id }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['groups'] });
        },
    });
};

export const useAttachGroupMemberMutation = (id: string | number) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (userId: string | number): Promise<ApiResponse<null>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_GROUP_MEMBER_DETAIL, { id, userId }),
                null,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['groups'] });
            await queryClient.invalidateQueries({ queryKey: ['group', String(id)] });
            await queryClient.invalidateQueries({ queryKey: ['users'] });
            await queryClient.invalidateQueries({ queryKey: ['user-groups'] });
        },
    });
};

export const useBatchAttachGroupMembersMutation = (id: string | number) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (userIds: (string | number)[]): Promise<ApiResponse<null>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_GROUP_BATCH_ATTACH_MEMBERS, { id }), {
                user_ids: userIds,
            });
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['groups'] });
            await queryClient.invalidateQueries({ queryKey: ['group', String(id)], exact: false });
            await queryClient.invalidateQueries({ queryKey: ['users'] });
            await queryClient.invalidateQueries({ queryKey: ['user-groups'] });
        },
    });
};

export const useDetachGroupMemberMutation = (id: string | number) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (userId: string | number): Promise<ApiResponse<null>> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_GROUP_MEMBER_DETAIL, { id, userId }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['groups'] });
            await queryClient.invalidateQueries({ queryKey: ['group', String(id)], exact: false });
            await queryClient.invalidateQueries({ queryKey: ['users'] });
            await queryClient.invalidateQueries({ queryKey: ['user-groups'] });
        },
    });
};
