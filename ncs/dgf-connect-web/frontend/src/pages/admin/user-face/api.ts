import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { UserFace } from '@/types/models/user_face';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export const useGetUserFacesQuery = (params: ParsedUrlQuery) => {
    return useQuery({
        queryKey: ['admin', 'user-faces', params],
        queryFn: () => ApiHelper.get<UserFace[]>(ApiConfig.ADMIN_USER_FACE_LIST, { params }),
    });
};

export const useCreateUserFaceMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: (data: { user_id: number }) => ApiHelper.post(ApiConfig.ADMIN_USER_FACE_LIST, data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['admin', 'user-faces'] });
        },
    });
};

export const useUpdateUserFaceMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: (userId: number) => {
            const url = CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_FACE_DETAIL, { userId });
            return ApiHelper.put(url, {});
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['admin', 'user-faces'] });
        },
    });
};

export const useDeleteUserFaceMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: (userId: number) => {
            const url = CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_FACE_DETAIL, { userId });
            return ApiHelper.delete(url);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['admin', 'user-faces'] });
        },
    });
};
