import { keepPreviousData, useQuery } from "@tanstack/react-query";
import type { ApiResponse, PaginatedData } from "@/types/api";
import type { Log } from "@/types/models/log";
import { ApiConfig } from "@/configs/api.config";
import type { ParsedUrlQuery } from "@/helpers/api.helper";
import ApiHelper from "@/helpers/api.helper";
import CommonHelper from "@/helpers/common.helper";

type GetLogsResponse = ApiResponse<PaginatedData<Log>>;

export const useGetLogsQuery = (
  companyId: number,
  params: ParsedUrlQuery,
  enabled = true
) => {
  return useQuery({
    queryKey: ["super-admin/companies", companyId, "logs", params],
    queryFn: async (): Promise<GetLogsResponse> =>
      ApiHelper.get(
        CommonHelper.replaceUrlParam(ApiConfig.SUPER_ADMIN_COMPANY_LOG, {
          id: companyId,
        }),
        { params }
      ),
    placeholderData: keepPreviousData,
    enabled,
  });
};
