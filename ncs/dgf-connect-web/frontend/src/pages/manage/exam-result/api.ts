import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { ExamResultWithRelation } from '@/types/models/exam_result';
import { Question } from '@/types/models/question';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';
import DateHelper from '@/helpers/date.helper';

export const useCompleteExternalOverrideMutation = (id: string) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (): Promise<ApiResponse<ExamResultWithRelation>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_EXAMS_RESULT_COMPLETE_EXTERNAL_OVERRIDE, { id }),
                {},
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['exam-result', id] });
            await queryClient.invalidateQueries({ queryKey: ['exam-results'] });
        },
    });
};

export const useGetDetailExamResultQuery = (id: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['exam-result', id],
        queryFn: async (): Promise<
            ApiResponse<{
                exam_result: ExamResultWithRelation;
                questions: Question[];
            }>
        > => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_EXAMS_RESULT_DETAIL, { id }));
        },
        enabled: hasPermission,
    });
};

export const exportExamResult = async (params: ParsedUrlQuery) => {
    const blob = await ApiHelper.downloadBinary(ApiConfig.MANAGE_EXAMS_RESULT_EXPORT, { params });
    const objectUrl = URL.createObjectURL(blob);
    const link: HTMLAnchorElement = document.createElement('a');
    const now = DateHelper.now().format('YYYYMMDDHHmmss');
    link.href = objectUrl;
    link.download = `exam_result_${now}.csv`;
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
};

export const useGetAllExamResultQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['exam-results', params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<ExamResultWithRelation>>> => {
            return ApiHelper.get(ApiConfig.MANAGE_EXAMS_RESULT, { params });
        },
        enabled: hasPermission,
    });
};
