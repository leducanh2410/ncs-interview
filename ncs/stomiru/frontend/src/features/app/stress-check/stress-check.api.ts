import {
  keepPreviousData,
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import type { ApiResponse, PaginatedData } from "@/types/api";
import type { ExamResultData, StressCheck } from "@/types/models/stress-check";
import { ApiConfig } from "@/configs/api.config";
import type { ParsedUrlQuery } from "@/helpers/api.helper";
import ApiHelper from "@/helpers/api.helper";
import CommonHelper from "@/helpers/common.helper";
import type { StressProjectReportResponse, StressProjectResponse } from "@/features/admin/stress-project/stress-project.api";

type GetStressChecksResponse = ApiResponse<PaginatedData<StressCheck>>;
type GetStressCheckExamResponse = ApiResponse<StressCheck>;

export const useGetActiveStressChecksQuery = () => {
  return useQuery({
    queryKey: ["app/stress-checks-active"],
    queryFn: async (): Promise<ApiResponse<StressCheck[]>> => {
      return ApiHelper.get(ApiConfig.APP_STRESS_CHECK_ACTIVE);
    },
  });
};

export const useGetStressCheckHistoryQuery = (params: ParsedUrlQuery) => {
  return useQuery({
    queryKey: ["app/stress-checks-history", params],
    queryFn: async (): Promise<GetStressChecksResponse> => {
      return ApiHelper.get(ApiConfig.APP_STRESS_CHECKS, { params });
    },
    placeholderData: keepPreviousData,
  });
};

export const useSubmitStressCheckExamMutation = (id: number) => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (
      data: Partial<Record<string, unknown>>
    ): Promise<ApiResponse<void>> => {
      return ApiHelper.post(
        CommonHelper.replaceUrlParam(ApiConfig.APP_STRESS_CHECK_EXAM, { id }),
        data
      );
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ["app/stress-check-exam", id],
      });
    },
  });
};

export const stressCheckExamQueryOptions = (id: number) =>
  queryOptions({
    queryKey: ["app/stress-check-exam", id],
    queryFn: (): Promise<GetStressCheckExamResponse> =>
      ApiHelper.get(
        CommonHelper.replaceUrlParam(ApiConfig.APP_STRESS_CHECK_EXAM, { id })
      ),
  });

export const stressCheckResultQueryOptions = (id: number) =>
  queryOptions({
    queryKey: ["app/stress-check-result", id],
    queryFn: (): Promise<ApiResponse<ExamResultData>> =>
      ApiHelper.get(
        CommonHelper.replaceUrlParam(ApiConfig.APP_STRESS_CHECK_RESULT, { id })
      ),
  });

export const staffManagerStressProjectQueryOptions = (id: number) =>
  queryOptions({
    queryKey: ["staff/stress-project", id],
    queryFn: (): Promise<StressProjectResponse> =>
      ApiHelper.get(
        CommonHelper.replaceUrlParam(ApiConfig.APP_STRESS_CHECK_DETAIL, { id })
      ),
  });

export const staffManagerstressProjectReportQueryOptions = (id: number) =>
  queryOptions({
    queryKey: ["admin/stress-project-report", id],
    queryFn: (): Promise<StressProjectReportResponse> =>
      ApiHelper.get(
        CommonHelper.replaceUrlParam(ApiConfig.APP_STRESS_CHECK_STAFF_REPORT, { id })
      ),
  });
