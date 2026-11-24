import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useSetAtom } from "jotai";
import { authAtom } from "@/global.state";
import type { ApiResponse } from "@/types/api";
import type { Role } from "@/types/models/role";
import type { User } from "@/types/models/user";
import { ApiConfig } from "@/configs/api.config";
import ApiHelper, {
  type GetRequestOptions,
  type ParsedUrlQuery,
} from "@/helpers/api.helper";
import CommonHelper from "@/helpers/common.helper";
import { AUTH_TOKEN } from "@/constants/auth-constant";

export type AuthData = {
  user: User;
  roles: Role[];
};

export type AuthCheckResponse = ApiResponse<AuthData>;

export type LoginResponse = ApiResponse<AuthData & { token: string }>;

export const authCheck = async (
  options?: GetRequestOptions
): Promise<AuthCheckResponse> => {
  return ApiHelper.get(ApiConfig.AUTH_CHECK, options);
};

export const useLoginMutation = () => {
  return useMutation({
    mutationFn: async (
      data: Record<string, unknown>
    ): Promise<LoginResponse> => {
      return ApiHelper.post(ApiConfig.AUTH_LOGIN, data);
    },
  });
};

export const useRegisterMutation = () => {
  return useMutation({
    mutationFn: async (
      data: Record<string, unknown>
    ): Promise<ApiResponse<Record<string, unknown>>> => {
      return ApiHelper.post(ApiConfig.AUTH_REGISTER, data);
    },
  });
};

export const useForgotPasswordMutation = () => {
  return useMutation({
    mutationFn: async (
      data: Record<string, unknown>
    ): Promise<ApiResponse<unknown>> => {
      return ApiHelper.post(ApiConfig.AUTH_FORGOT_PASSWORD, data);
    },
  });
};

export const useLogoutMutation = () => {
  const setAuth = useSetAtom(authAtom);
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<ApiResponse<unknown>> => {
      return ApiHelper.delete(ApiConfig.AUTH_LOGOUT);
    },
    onSuccess: () => {
      localStorage.removeItem(AUTH_TOKEN);
      setAuth(null);
      queryClient.clear();
    },
    onError: () => {
      localStorage.removeItem(AUTH_TOKEN);
      setAuth(null);
      queryClient.clear();
    },
  });
};

export const useSetupPasswordMutation = (
  userId: number,
  params?: Record<string, unknown>
) => {
  const queryString = params
    ? CommonHelper.urlQueryToString(params as ParsedUrlQuery)
    : {};
  const endpoint =
    CommonHelper.replaceUrlParam(ApiConfig.SETUP_PASSWORD_ADMIN, { userId }) +
    "?" +
    queryString;

  return useMutation({
    mutationFn: async (
      data: Record<string, unknown>
    ): Promise<ApiResponse<unknown>> => {
      return ApiHelper.post(endpoint, data);
    },
  });
};

export const useSetFirstPasswordMutation = (
  userId: number,
  params?: Record<string, unknown>
) => {
  const queryString = params
    ? CommonHelper.urlQueryToString(params as ParsedUrlQuery)
    : {};
  const endpoint =
    CommonHelper.replaceUrlParam(ApiConfig.SET_FIRST_PASSWORD, { userId }) +
    "?" +
    queryString;

  return useMutation({
    mutationFn: async (
      data: Record<string, unknown>
    ): Promise<ApiResponse<unknown>> => {
      return ApiHelper.post(endpoint, data);
    },
  });
};

export const useSetFirstPasswordWithToken = (
  token: string,
) => {
  return useMutation({
    mutationFn: async (
      data: Record<string, unknown>
    ): Promise<LoginResponse> => {
      return ApiHelper.post(ApiConfig.STAFF_SET_FIRST_PASSWORD, data, {
        headers: {
          Authorization: `Bearer ${token}`,
        },
      });
    },
  });
};
