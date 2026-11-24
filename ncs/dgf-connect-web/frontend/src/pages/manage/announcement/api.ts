import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { AnnouncementWithRelation } from '@/types/models/announcement';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export const useGetAnnouncementsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['announcements', params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<AnnouncementWithRelation>>> => {
            return ApiHelper.get(ApiConfig.MANAGE_ANNOUNCEMENT_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useCreateAnnouncementMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: FormData): Promise<ApiResponse<AnnouncementWithRelation>> => {
            return ApiHelper.postForm(ApiConfig.MANAGE_ANNOUNCEMENT_LIST, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['announcements'] });
        },
    });
};

export const useUpdateAnnouncementMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            id,
            data,
        }: {
            id: string;
            data: FormData;
        }): Promise<ApiResponse<AnnouncementWithRelation>> => {
            return ApiHelper.postForm(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_ANNOUNCEMENT_DETAIL, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['announcements'] });
        },
    });
};

export const useDeleteAnnouncementMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: string): Promise<ApiResponse<void>> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_ANNOUNCEMENT_DETAIL, { id }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['announcements'] });
        },
    });
};
