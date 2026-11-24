import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { ExamStatistics } from '@/types/models/exam';
import { ApiConfig } from '@/configs/api.config';
import { ExamContentLanguage, TranslatableText } from '@/configs/exam_language.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

type ExamOption = {
    id: number;
    question_id: number;
    content: TranslatableText;
    is_correct: boolean;
};

export type ExamQuestion = {
    id: number;
    exam_id: number;
    content: TranslatableText;
    no: number;
    options: ExamOption[];
};

export type ExamDocument = {
    id: number;
    title: string;
    content: string;
    created_at: string;
    updated_at: string;
    pivot: {
        exam_id: number;
        document_id: number;
    };
};

export type ExamWorkplace = {
    id: number;
    name: string;
    address: string;
    latitude: string;
    longitude: string;
    salary_per_hour: number | null;
    created_at: string;
    updated_at: string;
    coordinate: string;
    pivot: {
        exam_id: number;
        workplace_id: number;
    };
};

export type ExamCompletionStats = {
    eligible_count: number;
    completed_count: number;
    pending_count: number;
    completion_rate: number;
};

export type ExamResponse = {
    id: number;
    title: TranslatableText;
    languages: ExamContentLanguage[];
    type: 'assessment' | 'external_form_prep';
    is_required: number;
    completion_stats: ExamCompletionStats;
    // Server-assigned (0/0/config default) and not user-editable when type = 'external_form_prep'.
    retry_limit: number;
    min_score: number;
    time_limit: number;
    external_url: TranslatableText;
    start_at: string | null;
    end_at: string | null;
    notified_at: string | null;
    status: 'scheduled' | 'active' | 'ended';
    show_result_immediately?: boolean;
    has_results?: boolean;
    created_at: string;
    updated_at: string;
    documents: ExamDocument[];
    workplaces: ExamWorkplace[];
    questions: ExamQuestion[];
};

export const useCreateExamMutation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: any): Promise<ApiResponse<void>> => {
            return ApiHelper.post(ApiConfig.MANAGE_EXAMS_CREATE, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['exams'] });
            await queryClient.invalidateQueries({ queryKey: ['exam'] });
        },
    });
};

export const useUpdateExamMutation = (id: string) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: any): Promise<ApiResponse<void>> => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_EXAMS_UPDATE, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['exams'] });
            await queryClient.invalidateQueries({ queryKey: ['exam'] });
            await queryClient.invalidateQueries({ queryKey: ['exam-statistics', id] });
        },
    });
};

export const useGetExamQuery = (id: string, enabled: boolean = true) => {
    return useQuery({
        queryKey: ['exam', id],
        queryFn: async (): Promise<any> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_EXAMS_DETAIL, { id }));
        },
        enabled,
    });
};

export const useGetExamsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['exams', params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<ExamResponse>>> => {
            return ApiHelper.get(ApiConfig.MANAGE_EXAMS_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useDeleteExamMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: string): Promise<ApiResponse<void>> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_EXAMS_DELETE, { id }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['exams'] });
        },
    });
};

export type ExamStatisticsParams = {
    workplace_id?: string | null;
    occurrence?: string | null;
    from?: string | null;
    to?: string | null;
};

export const useGetExamStatisticsQuery = (id: string, params: ExamStatisticsParams, enabled: boolean = true) => {
    return useQuery({
        queryKey: ['exam-statistics', id, params],
        queryFn: async (): Promise<ApiResponse<ExamStatistics>> => {
            const query: ParsedUrlQuery = {};
            for (const [key, value] of Object.entries(params)) {
                if (value) {
                    query[key] = value;
                }
            }
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_EXAMS_STATISTICS, { id }), {
                params: query,
            });
        },
        enabled: enabled && !!id,
        placeholderData: keepPreviousData,
    });
};
