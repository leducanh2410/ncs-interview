import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import {
    AssignSupportAdminsPayload,
    ChatSupportConversationListItem,
    SupportAdminsResult,
    SupportType,
    SupportTypeAdminUser,
    SupportTypeConfig,
} from '@/types/models/chat';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';
import { ADMIN_CHAT_CONVERSATION_QUERY_KEY, ADMIN_CHAT_MESSAGES_QUERY_KEY } from '@/components/chat/api';

export const ADMIN_CHAT_SUPPORT_TYPES_QUERY_KEY = 'admin-chat-support-types';
export const ADMIN_CHAT_SUPPORT_CONVERSATIONS_QUERY_KEY = 'admin-chat-support-conversations';

export const useSupportConversationsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: [ADMIN_CHAT_SUPPORT_CONVERSATIONS_QUERY_KEY, params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<ChatSupportConversationListItem>>> => {
            return ApiHelper.get(ApiConfig.ADMIN_CHAT_SUPPORT_CONVERSATIONS, { params });
        },
        enabled: hasPermission,
        refetchOnMount: 'always',
    });
};

export const useAssignSupportAdminsMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: AssignSupportAdminsPayload): Promise<ApiResponse<SupportAdminsResult>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_SUPPORT_ASSIGN, {
                    id: conversationId?.toString() ?? '',
                }),
                data,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_SUPPORT_CONVERSATIONS_QUERY_KEY] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_MESSAGES_QUERY_KEY, conversationId] });
        },
    });
};

export const useRemoveSupportAdminsMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: AssignSupportAdminsPayload): Promise<ApiResponse<SupportAdminsResult>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_SUPPORT_REMOVE, {
                    id: conversationId?.toString() ?? '',
                }),
                data,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_SUPPORT_CONVERSATIONS_QUERY_KEY] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_MESSAGES_QUERY_KEY, conversationId] });
        },
    });
};

export const useSupportTypeConfigsQuery = (hasPermission: boolean = false) => {
    return useQuery({
        queryKey: [ADMIN_CHAT_SUPPORT_TYPES_QUERY_KEY],
        queryFn: async (): Promise<SupportTypeConfig[]> => {
            const res: ApiResponse<SupportTypeConfig[]> = await ApiHelper.get(ApiConfig.ADMIN_CHAT_SUPPORT_TYPES);
            return res.data ?? [];
        },
        enabled: hasPermission,
    });
};

export const useAddSupportTypeAdminMutation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({
            type,
            userId,
        }: {
            type: SupportType;
            userId: number;
        }): Promise<ApiResponse<SupportTypeAdminUser>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_SUPPORT_TYPE_ADMINS, { type }), {
                user_id: userId,
            });
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_SUPPORT_TYPES_QUERY_KEY] });
        },
    });
};

export const useRemoveSupportTypeAdminMutation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ type, userId }: { type: SupportType; userId: number }): Promise<void> => {
            await ApiHelper.delete(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_SUPPORT_TYPE_ADMIN, {
                    type,
                    userId: userId.toString(),
                }),
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_SUPPORT_TYPES_QUERY_KEY] });
        },
    });
};
