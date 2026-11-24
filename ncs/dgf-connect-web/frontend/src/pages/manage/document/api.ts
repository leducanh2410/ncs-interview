import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { Document } from '@/types/models/document';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export const useGetDocumentsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['documents', params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<Document>>> => {
            return ApiHelper.get(ApiConfig.MANAGE_DOCUMENT_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useGetDocumentQuery = (id: string) => {
    return useQuery({
        queryKey: ['document', id],
        queryFn: async (): Promise<any> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_DOCUMENT_DETAIL, { id }));
        },
    });
};

export const useCreateDocumentMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: FormData): Promise<ApiResponse<void>> => {
            return ApiHelper.postForm(ApiConfig.MANAGE_DOCUMENT_CREATE, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['documents'] });
        },
    });
};

export const useUpdateDocumentMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: FormData): Promise<ApiResponse<void>> => {
            return ApiHelper.postForm(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_DOCUMENT_UPDATE, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['documents'] });
            await queryClient.invalidateQueries({ queryKey: ['document', id] });
        },
    });
};

export const useDeleteDocumentMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: string): Promise<ApiResponse<void>> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_DOCUMENT_DELETE, { id }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['documents'] });
        },
    });
};
