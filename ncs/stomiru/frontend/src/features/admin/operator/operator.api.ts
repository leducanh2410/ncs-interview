import {
  keepPreviousData,
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import type { ApiResponse, PaginatedData } from "@/types/api";
import type { User } from "@/types/models/user";
import { ApiConfig } from "@/configs/api.config";
import ApiHelper, { type ParsedUrlQuery } from "@/helpers/api.helper";
import CommonHelper from "@/helpers/common.helper";

export interface Operator extends User {}

type GetOperatorsResponse = ApiResponse<PaginatedData<Operator>>;
type CreateOperatorResponse = ApiResponse<Partial<Operator>>;
type UpdateOperatorResponse = ApiResponse<Partial<Operator>>;
type OperatorResponse = ApiResponse<Operator>;

export const useGetOperatorsQuery = (params: ParsedUrlQuery) => {
  return useQuery({
    queryKey: ["admin/operators", params],
    queryFn: async (): Promise<GetOperatorsResponse> => {
      return ApiHelper.get(ApiConfig.ADMIN_OPERATORS, { params });
    },
    placeholderData: keepPreviousData,
  });
};

export const useCreateOperatorMutation = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (
      data: Partial<Operator>
    ): Promise<CreateOperatorResponse> => {
      return ApiHelper.post(ApiConfig.ADMIN_OPERATORS, data);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin/operators"] });
    },
  });
};

export const useUpdateOperatorMutation = (id: number) => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (
      data: Partial<Operator>
    ): Promise<UpdateOperatorResponse> => {
      return ApiHelper.put(ApiConfig.ADMIN_OPERATORS + "/" + id, data);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin/operators"] });
      void queryClient.invalidateQueries({ queryKey: ["admin/operators", id] });
    },
  });
};

export const operatorQueryOptions = (id: number) =>
  queryOptions({
    queryKey: ["admin/operators", id],
    queryFn: (): Promise<OperatorResponse> =>
      ApiHelper.get(ApiConfig.ADMIN_OPERATORS + "/" + id),
  });

export const useDeleteAdminMutation = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: number): Promise<ApiResponse<unknown>> => {
      return ApiHelper.delete(
        CommonHelper.replaceUrlParam(ApiConfig.SUPER_ADMIN_DELETE_ADMIN, { id })
      );
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin/operators"] });
    },
  });
};

export const useRequestDeleteAdminMutation = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: number): Promise<ApiResponse<unknown>> => {
      return ApiHelper.post(
        CommonHelper.replaceUrlParam(ApiConfig.ADMIN_REQUEST_DELETE, { id }),
        {}
      );
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin/operators"] });
    },
  });
};

export const useResendAdminInvitationMutation = (id: number) => {
  return useMutation({
    mutationFn: async (): Promise<ApiResponse<unknown>> => {
      return ApiHelper.post(
        CommonHelper.replaceUrlParam(ApiConfig.ADMIN_RESEND_INVITATION, { id }),
        {}
      );
    },
  });
};
