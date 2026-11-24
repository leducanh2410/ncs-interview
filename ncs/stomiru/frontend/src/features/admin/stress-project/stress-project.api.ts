import {
  keepPreviousData,
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import type { ApiResponse, PaginatedData } from "@/types/api";
import type { StressCheck } from "@/types/models/stress-check";
import { ApiConfig } from "@/configs/api.config";
import ApiHelper, { type ParsedUrlQuery } from "@/helpers/api.helper";

type GetStressProjectsResponse = ApiResponse<PaginatedData<StressCheck>>;
type CreateStressProjectResponse = ApiResponse<Partial<StressCheck>>;
type UpdateStressProjectResponse = ApiResponse<Partial<StressCheck>>;
export type StressProjectResponse = ApiResponse<StressCheck>;

// Backend API response type
export type StressProjectReportApiResponse = {
  start_date?: string;
  end_date?: string;
  total_employees: number;
  participated_employees: number;
  not_completed_employees: number;
  participation_rate: number;
  high_stress_ratio: number;
  workload_score: number;
  control_score: number;
  supervisor_support_score: number;
  coworker_support_score: number;
  calculated_risk_workload: number | null;
  calculated_risk_support: number | null;
  previous_workload_score: number | null;
  previous_control_score: number | null;
  previous_supervisor_support_score: number | null;
  previous_coworker_support_score: number | null;
  previous_calculated_risk_workload: number | null;
  previous_calculated_risk_support: number | null;
  previous_high_stress_ratio: number | null;
  reaction_to_stress_score: number | null;
  comprehensive_risk_score: number;
  previous_comprehensive_risk_score: number | null;
  report_comments: Record<string, string>;
  scale_distributions: ScaleDistribution[];
};

export type ScaleDistribution = {
  id: number;
  admin_report_id: number;
  scale_id: number;
  bad_percentage: string;
  slightly_bad_percentage: string;
  average_percentage: string;
  slightly_good_percentage: string;
  good_percentage: string;
  scale_name: string;
  scale_translation_ja: string;
};

export type StressProjectReportResponse =
  ApiResponse<StressProjectReportApiResponse>;

export const useGetStressProjectsQuery = (params: ParsedUrlQuery) => {
  return useQuery({
    queryKey: ["admin/stress-projects", params],
    queryFn: async (): Promise<GetStressProjectsResponse> => {
      return ApiHelper.get(ApiConfig.ADMIN_STRESS_PROJECTS, { params });
    },
    placeholderData: keepPreviousData,
  });
};

export const useCreateStressProjectMutation = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (
      data: Partial<StressCheck>
    ): Promise<CreateStressProjectResponse> => {
      return ApiHelper.post(ApiConfig.ADMIN_STRESS_PROJECT, data);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ["admin/stress-projects"],
      });
    },
  });
};

export const useUpdateStressProjectMutation = (id: number) => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (
      data: Partial<StressCheck>
    ): Promise<UpdateStressProjectResponse> => {
      return ApiHelper.put(ApiConfig.ADMIN_STRESS_PROJECT + "/" + id, data);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ["admin/stress-projects"],
      });
      void queryClient.invalidateQueries({
        queryKey: ["admin/stress-projects", id],
      });
    },
  });
};

export const useDeleteStressProjectMutation = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: number): Promise<ApiResponse<void>> => {
      return ApiHelper.delete(ApiConfig.ADMIN_STRESS_PROJECT + "/" + id);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ["admin/stress-projects"],
      });
    },
  });
};

export const stressProjectQueryOptions = (id: number) =>
  queryOptions({
    queryKey: ["admin/stress-projects", id],
    queryFn: (): Promise<StressProjectResponse> =>
      ApiHelper.get(ApiConfig.ADMIN_STRESS_PROJECT + "/" + id),
  });

export const stressProjectReportQueryOptions = (id: number) =>
  queryOptions({
    queryKey: ["admin/stress-project-report", id],
    queryFn: (): Promise<StressProjectReportResponse> =>
      ApiHelper.get(
        ApiConfig.ADMIN_STRESS_PROJECT_REPORT.replace(":id", String(id))
      ),
  });
