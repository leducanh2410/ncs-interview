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

type GetEmployeesResponse = ApiResponse<PaginatedData<User>>;
type CreateEmployeeResponse = ApiResponse<Partial<User>>;
type UpdateEmployeeResponse = ApiResponse<Partial<User>>;
type EmployeeResponse = ApiResponse<User>;

export const useGetEmployeesQuery = (params: ParsedUrlQuery) => {
  return useQuery({
    queryKey: ["admin/employees", params],
    queryFn: async (): Promise<GetEmployeesResponse> => {
      return ApiHelper.get(ApiConfig.ADMIN_EMPLOYEES, { params });
    },
    placeholderData: keepPreviousData,
  });
};

export const useCreateEmployeeMutation = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (
      data: Partial<User>
    ): Promise<CreateEmployeeResponse> => {
      return ApiHelper.post(ApiConfig.ADMIN_EMPLOYEES, data);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin/employees"] });
    },
  });
};

export const useUpdateEmployeeMutation = (id: number) => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (
      data: Partial<User>
    ): Promise<UpdateEmployeeResponse> => {
      return ApiHelper.put(ApiConfig.ADMIN_EMPLOYEES + "/" + id, data);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin/employees"] });
      void queryClient.invalidateQueries({ queryKey: ["admin/employees", id] });
    },
  });
};

export const employeeQueryOptions = (id: number) =>
  queryOptions({
    queryKey: ["admin/employees", id],
    queryFn: (): Promise<EmployeeResponse> =>
      ApiHelper.get(ApiConfig.ADMIN_EMPLOYEES + "/" + id),
  });

export const useRequestDeleteEmployeeMutation = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: number): Promise<ApiResponse<void>> => {
      return ApiHelper.post(ApiConfig.ADMIN_EMPLOYEES + "/" + id + "/request-delete", {});
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin/employees"] });
    },
  });
};

export const useConfirmRequestDeleteEmployeeMutation = (id: number) => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (approve: boolean): Promise<ApiResponse<void>> => {
      return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CONFIRM_DELETE_EMPLOYEE, { id }), { approve });
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin/employees"] });
    },
  });
};

export const useForceDeleteEmployeeMutation = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: number): Promise<ApiResponse<void>> => {
      return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.SUPER_ADMIN_DELETE_EMPLOYEE, { id }));
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin/employees"] });
    },
  });
};