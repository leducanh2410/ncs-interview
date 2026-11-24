import { useMutation } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import { User } from '@/types/models/user';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';

export const useUploadProfilePictureMutation = () => {
    return useMutation({
        mutationFn: async (image: File): Promise<ApiResponse<User>> => {
            const data = new FormData();
            data.append('_method', 'PUT');
            data.append('image', image);
            return ApiHelper.postForm(ApiConfig.MANAGE_PROFILE_PICTURE, data);
        },
    });
};

export const useDeleteProfilePictureMutation = () => {
    return useMutation({
        mutationFn: async (): Promise<ApiResponse<User>> => {
            return ApiHelper.delete(ApiConfig.MANAGE_PROFILE_PICTURE);
        },
    });
};

export const useUpdateUserInfoMutation = () => {
    return useMutation({
        mutationFn: async (data: Record<string, string>): Promise<ApiResponse<User>> => {
            return ApiHelper.patch(ApiConfig.MANAGE_PROFILE_INFO, data);
        },
    });
};

export const useChangePasswordMutation = () => {
    return useMutation({
        mutationFn: async (data: Record<string, string>): Promise<ApiResponse<User>> => {
            return ApiHelper.put(ApiConfig.MANAGE_PROFILE_PASSWORD, data);
        },
    });
};

export const useUpdateSettingMutation = () => {
    return useMutation({
        mutationFn: async (data: Record<string, string>): Promise<ApiResponse<User>> => {
            return ApiHelper.patch(ApiConfig.MANAGE_PROFILE_SETTINGS, data);
        },
    });
};
