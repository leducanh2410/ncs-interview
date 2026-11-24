import {
  keepPreviousData,
  queryOptions,
  useQuery,
} from "@tanstack/react-query";
import type { ApiResponse, PaginatedData } from "@/types/api";
import type { Examinee, ExamResultData } from "@/types/models/stress-check";
import { ApiConfig } from "@/configs/api.config";
import ApiHelper, { type ParsedUrlQuery } from "@/helpers/api.helper";
import CommonHelper from "@/helpers/common.helper";

type GetExamineesResponse = ApiResponse<PaginatedData<Examinee>>;

export const useGetExamineesQuery = (stressProjectId: number, params: ParsedUrlQuery) => {
  return useQuery({
    queryKey: ["admin/examinees", params],
    queryFn: async (): Promise<GetExamineesResponse> => {
      return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_EXAMINEES, { id: stressProjectId }), { params });
    },
    placeholderData: keepPreviousData,
  });
};

export const examineeResultQueryOptions = (stressCheckId: number, examineeId: number) =>
  queryOptions({
    queryKey: ["admin/examinees-result", stressCheckId, examineeId],
    queryFn: (): Promise<ApiResponse<ExamResultData>> =>
      ApiHelper.get(
        CommonHelper.replaceUrlParam(ApiConfig.ADMIN_EXAMINEE_RESULT, { id: stressCheckId, examineeId })
      ),
  });