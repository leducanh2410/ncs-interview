import { useQuery } from "@tanstack/react-query";
import type { ApiResponse } from "@/types/api";
import { ApiConfig } from "@/configs/api.config";
import type { ParsedUrlQuery } from "@/helpers/api.helper";
import ApiHelper from "@/helpers/api.helper";

export interface DashboardData {
  is_empty: boolean;
  latest_period: {
    id: number;
    title: string | null;
    year: number | null;
    start_date: string | null;
    end_date: string | null;
    status: string | null;
    total_examinees: number;
    answered_examinees: number;
    response_rate: number | null;
  } | null;
  high_stress_ratio: number | null;
  high_stress_ratio_previous: number | null;
  high_stress_evaluation: string | null;
  health_risk: number | null;
  health_risk_previous: number | null;
  health_risk_evaluation: string | null;
  chart_data: {
    psychological_burden: {
      current: number | null;
      previous: number | null;
      evaluation: string | null;
    };
    job_control: {
      current: number | null;
      previous: number | null;
      evaluation: string | null;
    };
    supervisor_support: {
      current: number | null;
      previous: number | null;
      evaluation: string | null;
    };
    colleague_support: {
      current: number | null;
      previous: number | null;
      evaluation: string | null;
    };
  } | null;
}

export const useGetDashboardDataQuery = (params: ParsedUrlQuery) => {
  return useQuery({
    queryKey: ["admin/dashboard", params],
    queryFn: async (): Promise<ApiResponse<DashboardData>> => {
      return ApiHelper.get(ApiConfig.ADMIN_DASHBOARD, { params });
    },
  });
};
