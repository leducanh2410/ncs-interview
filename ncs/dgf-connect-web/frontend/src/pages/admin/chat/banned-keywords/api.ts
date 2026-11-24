import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import { ChatBannedKeyword } from '@/types/models/chat';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';

type BannedKeywordPayload = {
    keyword: string;
    locale: string | null;
    action: 'block' | 'mask';
    is_active?: boolean;
};

const QUERY_KEY = ['chat-banned-keywords'];

export const useGetBannedKeywordsQuery = (hasPermission: boolean = false) => {
    return useQuery({
        queryKey: QUERY_KEY,
        queryFn: async (): Promise<ApiResponse<ChatBannedKeyword[]>> => {
            return ApiHelper.get(ApiConfig.ADMIN_CHAT_BANNED_KEYWORDS);
        },
        enabled: hasPermission,
    });
};

export const useCreateBannedKeywordMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: BannedKeywordPayload): Promise<ApiResponse<ChatBannedKeyword>> => {
            return ApiHelper.post(ApiConfig.ADMIN_CHAT_BANNED_KEYWORDS, data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: QUERY_KEY });
        },
    });
};

export const useUpdateBannedKeywordMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            id,
            data,
        }: {
            id: number;
            data: Partial<BannedKeywordPayload>;
        }): Promise<ApiResponse<ChatBannedKeyword>> => {
            return ApiHelper.patch(ApiConfig.ADMIN_CHAT_BANNED_KEYWORD.replace(':id', String(id)), data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: QUERY_KEY });
        },
    });
};

export const useDeleteBannedKeywordMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<ApiResponse<null>> => {
            return ApiHelper.delete(ApiConfig.ADMIN_CHAT_BANNED_KEYWORD.replace(':id', String(id)));
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: QUERY_KEY });
        },
    });
};
