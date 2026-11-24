import { useMutation } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import { Role } from '@/types/models/role';
import { User } from '@/types/models/user';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper, { GetRequestOptions } from '@/helpers/api.helper';

type AuthData = {
    user: User;
    permissions: string[];
    roles: Role[];
};

type AuthCheckResponse = ApiResponse<AuthData>;

type LoginDataSuccess = AuthData & { token: string };

type LoginDataTfa = {
    tfa: {
        key: string;
        qr?: string;
    };
};

type LoginResponse = ApiResponse<LoginDataSuccess | LoginDataTfa>;

const isTfaRequired = (data: LoginDataSuccess | LoginDataTfa): data is LoginDataTfa => {
    return 'tfa' in data;
};

export const authCheck = async (options?: GetRequestOptions): Promise<AuthCheckResponse> => {
    return ApiHelper.get(ApiConfig.AUTH_CHECK, options);
};

export const useLoginMutation = () => {
    return useMutation({
        mutationFn: async (data: Record<string, any>): Promise<LoginResponse> => {
            return ApiHelper.post(ApiConfig.AUTH_LOGIN_ADMIN, data);
        },
    });
};

export const useLoginTfaMutation = () => {
    return useMutation({
        mutationFn: async (data: { key: string; code: string }): Promise<ApiResponse<LoginDataSuccess>> => {
            return ApiHelper.post(ApiConfig.AUTH_LOGIN_ADMIN_TFA, data);
        },
    });
};

export const useLogoutMutation = () => {
    return useMutation({
        mutationKey: ['logout'],
        mutationFn: async (): Promise<ApiResponse<any>> => {
            return ApiHelper.delete(ApiConfig.AUTH_LOGOUT);
        },
    });
};

export const useRequestResetPasswordMutation = () => {
    return useMutation({
        mutationFn: async (data: { email: string; turnstile_token: string }): Promise<ApiResponse<any>> => {
            return ApiHelper.post(ApiConfig.AUTH_RESET_PASSWORD_REQUEST, data);
        },
    });
};

export const useResetPasswordMutation = () => {
    return useMutation({
        mutationFn: async (data: Record<string, any>): Promise<ApiResponse<any>> => {
            return ApiHelper.post(ApiConfig.AUTH_RESET_PASSWORD_PROCESS, data);
        },
    });
};
