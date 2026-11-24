import {
  keepPreviousData,
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import type { ApiResponse, PaginatedData } from "@/types/api";
import type { Company } from "@/types/models/company";
import { ApiConfig } from "@/configs/api.config";
import ApiHelper, { type ParsedUrlQuery } from "@/helpers/api.helper";
import CommonHelper from "@/helpers/common.helper";

export type GetCompaniesResponse = ApiResponse<PaginatedData<Company>>;
export type CreateCompanyResponse = ApiResponse<Partial<Company>>;
export type UpdateCompanyResponse = ApiResponse<Partial<Company>>;
export type CompanyResponse = ApiResponse<Company>;

export const useGetCompaniesQuery = (params: ParsedUrlQuery) => {
  return useQuery({
    queryKey: ["super-admin/companies", params],
    queryFn: async (): Promise<GetCompaniesResponse> => {
      return ApiHelper.get(ApiConfig.SUPER_ADMIN_COMPANIES, { params });
    },
    placeholderData: keepPreviousData,
  });
};

export const useSearchCompaniesQuery = (searchText?: string) => {
  return useQuery({
    queryKey: ["super-admin/companies-search", searchText],
    queryFn: async (): Promise<GetCompaniesResponse> => {
      return ApiHelper.get(ApiConfig.SUPER_ADMIN_COMPANIES, {
        params: { searchText },
      });
    },
    placeholderData: keepPreviousData,
    enabled: searchText !== undefined,
  });
};

export const useCreateCompanyMutation = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (
      data: Partial<Company>
    ): Promise<CreateCompanyResponse> => {
      return ApiHelper.post(ApiConfig.SUPER_ADMIN_COMPANIES, data);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ["super-admin/companies"],
      });
      void queryClient.invalidateQueries({
        queryKey: ["super-admin/companies-search"],
      });
    },
  });
};

export const useUpdateCompanyMutation = (id: number) => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (
      data: Partial<Company>
    ): Promise<UpdateCompanyResponse> => {
      return ApiHelper.put(ApiConfig.SUPER_ADMIN_COMPANIES + "/" + id, data);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ["super-admin/companies"],
      });
      void queryClient.invalidateQueries({
        queryKey: ["super-admin/companies", id],
      });
    },
  });
};

export const useDeleteCompanyMutation = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: number): Promise<ApiResponse<void>> => {
      return ApiHelper.delete(ApiConfig.SUPER_ADMIN_COMPANIES + "/" + id);
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ["super-admin/companies"],
      });
    },
  });
};

export const useDeleteCompaniesMutation = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (ids: string[]): Promise<ApiResponse<void>> => {
      return ApiHelper.delete(ApiConfig.SUPER_ADMIN_COMPANIES, {
        params: { ids },
      });
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ["super-admin/companies"],
      });
    },
  });
};

export const companyQueryOptions = (id: number) =>
  queryOptions({
    queryKey: ["super-admin/companies", id],
    queryFn: (): Promise<CompanyResponse> =>
      ApiHelper.get(ApiConfig.SUPER_ADMIN_COMPANIES + "/" + id),
  });

export const useRestoreCompaniesMutation = (id: number) => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (): Promise<ApiResponse<void>> => {
      return ApiHelper.post(
        CommonHelper.replaceUrlParam(ApiConfig.SUPER_ADMIN_COMPANY_RESTORE, {
          id,
        }),
        null
      );
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries(companyQueryOptions(id));
    },
  });
};

export const useApproveCompanyMutation = (id: number) => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (): Promise<ApiResponse<unknown>> => {
      return ApiHelper.post(
        CommonHelper.replaceUrlParam(ApiConfig.SUPER_ADMIN_COMPANY_APPROVE, {
          id,
        }),
        null
      );
    },
    onSuccess: () => {
      queryClient.invalidateQueries(companyQueryOptions(id));
    },
  });
};

export const useUpdateCompanyNoteMutation = (id: number) => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (data: {
      note: string;
    }): Promise<ApiResponse<unknown>> => {
      return ApiHelper.put(
        ApiConfig.SUPER_ADMIN_COMPANIES + "/" + id + "/note",
        data
      );
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ["super-admin/companies", id],
      });
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
