import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiResponse, PaginatedData } from '@/types/api';
import {
    AddChatParticipantsPayload,
    AssignParticipantRolePayload,
    ChatConversationDetail,
    ChatConversationListItem,
    ChatMessage,
    ChatMessageReadReceipts,
    ChatMessagesResponse,
    ChatParticipant,
    ChatParticipantsPaginatedResponse,
    ChatParticipantsResult,
    CloseConversationPayload,
    CreateChatConversationPayload,
    CreatedChatConversation,
    PinMessageResult,
    SendChatMessagePayload,
    UpdateEmployeePermissionsPayload,
} from '@/types/models/chat';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export const ADMIN_CHAT_CONVERSATIONS_QUERY_KEY = 'admin-chat-conversations';
export const ADMIN_CHAT_CONVERSATION_QUERY_KEY = 'admin-chat-conversation';
export const ADMIN_CHAT_MESSAGES_QUERY_KEY = 'admin-chat-messages';

/**
 * Looks up the existing one-to-one conversation with an employee. A mutation rather than a
 * query: callers (one per table, not one per row) call `mutateAsync(userId)` at click time, so
 * a roster of rows shares a single lookup hook instead of each row owning its own disabled
 * query observer.
 */
export const useFindDirectConversationMutation = () => {
    return useMutation({
        mutationFn: async (userId: number): Promise<ApiResponse<{ conversation_id: number | null }>> => {
            return ApiHelper.get(ApiConfig.ADMIN_CHAT_DIRECT_CONVERSATION, {
                params: { user_id: String(userId) },
            });
        },
    });
};

export type ChatConversationStatusFilter = 'open' | 'closed' | 'all';

export const useAdminChatConversationsQuery = (
    enabled: boolean,
    search?: string,
    status: ChatConversationStatusFilter = 'open',
) => {
    return useQuery({
        queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY, search ?? '', status],
        queryFn: async (): Promise<ApiResponse<PaginatedData<ChatConversationListItem>>> => {
            const params: Record<string, string> = { page_size: '50', status };
            if (search) params.search = search;
            return ApiHelper.get(ApiConfig.ADMIN_CHAT_CONVERSATIONS, { params });
        },
        enabled,
        staleTime: 30000,
        refetchOnMount: 'always',
    });
};

export const useAdminChatConversationQuery = (conversationId: number | null, enabled: boolean) => {
    return useQuery({
        queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId],
        queryFn: async (): Promise<ApiResponse<ChatConversationDetail>> => {
            return ApiHelper.get(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_DETAIL, {
                    id: conversationId?.toString() ?? '',
                }),
            );
        },
        enabled: enabled && conversationId !== null,
    });
};

export const useAdminChatMessagesQuery = (conversationId: number | null, enabled: boolean) => {
    return useQuery({
        queryKey: [ADMIN_CHAT_MESSAGES_QUERY_KEY, conversationId],
        queryFn: async (): Promise<ApiResponse<ChatMessagesResponse>> => {
            return ApiHelper.get(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_MESSAGES, {
                    id: conversationId?.toString() ?? '',
                }),
                { params: { limit: '50' } },
            );
        },
        enabled: enabled && conversationId !== null,
        staleTime: 10000,
    });
};

export const useCreateAdminChatConversationMutation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: CreateChatConversationPayload): Promise<ApiResponse<CreatedChatConversation>> => {
            return ApiHelper.post(ApiConfig.ADMIN_CHAT_CONVERSATIONS, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
        },
    });
};

/**
 * Fetch a page of messages for a conversation. Used for backscroll (`before_id`) and forward
 * sync (`after_id`); both cursors are mutually exclusive on the server.
 */
export const fetchAdminChatMessages = (
    conversationId: number,
    params: { limit?: number; before_id?: number; after_id?: number } = {},
): Promise<ApiResponse<ChatMessagesResponse>> => {
    return ApiHelper.get(
        CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_MESSAGES, {
            id: conversationId.toString(),
        }),
        {
            params: Object.fromEntries(
                Object.entries({ limit: '50', ...params }).map(([key, value]) => [key, String(value)]),
            ),
        },
    );
};

export const useSendAdminChatMessageMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: SendChatMessagePayload): Promise<ApiResponse<ChatMessage>> => {
            const uri = CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_MESSAGES, {
                id: conversationId?.toString() ?? '',
            });

            const files = data.files ?? [];
            if (files.length === 0) {
                const { files: _files, ...jsonPayload } = data;
                return ApiHelper.post(uri, jsonPayload);
            }

            // Attachments require a multipart body; the server reads body + files[] together.
            const formData = new FormData();
            if (data.body) {
                formData.append('body', data.body);
            }
            if (data.client_message_id) {
                formData.append('client_message_id', data.client_message_id);
            }
            files.forEach((file) => formData.append('files[]', file));
            return ApiHelper.postForm(uri, formData);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
        },
    });
};

export const useCloseAdminChatConversationMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: CloseConversationPayload): Promise<ApiResponse<unknown>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_CLOSE, {
                    id: conversationId?.toString() ?? '',
                }),
                data,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
        },
    });
};

export const useReopenAdminChatConversationMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (): Promise<ApiResponse<unknown>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_REOPEN, {
                    id: conversationId?.toString() ?? '',
                }),
                null,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
        },
    });
};

export const useUpdateEmployeePermissionsMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: UpdateEmployeePermissionsPayload): Promise<ApiResponse<unknown>> => {
            return ApiHelper.patch(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_EMPLOYEE_PERMISSIONS, {
                    id: conversationId?.toString() ?? '',
                }),
                data,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
        },
    });
};

export const useAddChatParticipantsMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (data: AddChatParticipantsPayload): Promise<ApiResponse<ChatParticipantsResult>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_PARTICIPANTS, {
                    id: conversationId?.toString() ?? '',
                }),
                data,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_MESSAGES_QUERY_KEY, conversationId] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_PARTICIPANTS_QUERY_KEY, conversationId] });
        },
    });
};

export const useRemoveChatParticipantMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (userId: number): Promise<ApiResponse<ChatParticipantsResult>> => {
            return ApiHelper.delete(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_PARTICIPANT, {
                    id: conversationId?.toString() ?? '',
                    userId: userId.toString(),
                }),
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_MESSAGES_QUERY_KEY, conversationId] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_PARTICIPANTS_QUERY_KEY, conversationId] });
        },
    });
};

export const usePinAdminChatMessageMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (messageId: number): Promise<ApiResponse<PinMessageResult>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_PINNED_MESSAGE, {
                    id: conversationId?.toString() ?? '',
                }),
                { message_id: messageId },
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
        },
    });
};

export const useUnpinAdminChatMessageMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (): Promise<ApiResponse<PinMessageResult>> => {
            return ApiHelper.delete(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_PINNED_MESSAGE, {
                    id: conversationId?.toString() ?? '',
                }),
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
        },
    });
};

export const useRenameAdminChatConversationMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (title: string): Promise<ApiResponse<{ id: number; title: string }>> => {
            return ApiHelper.patch(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_TITLE, {
                    id: conversationId?.toString() ?? '',
                }),
                { title },
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
        },
    });
};

export const useMarkAdminChatReadMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (messageId: number): Promise<ApiResponse<unknown>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_READ, {
                    id: conversationId?.toString() ?? '',
                }),
                { message_id: messageId },
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
        },
    });
};

export const ADMIN_CHAT_MESSAGE_RECEIPTS_QUERY_KEY = 'admin-chat-message-receipts';

/**
 * Who has seen one message. Only fetched when the receipts dialog opens — the avatars under a
 * message are drawn from the participant read markers already on the conversation detail.
 */
export const useAdminChatMessageReceiptsQuery = (conversationId: number | null, messageId: number | null) => {
    return useQuery({
        queryKey: [ADMIN_CHAT_MESSAGE_RECEIPTS_QUERY_KEY, conversationId, messageId],
        queryFn: async (): Promise<ApiResponse<ChatMessageReadReceipts>> => {
            return ApiHelper.get(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_MESSAGE_RECEIPTS, {
                    id: conversationId?.toString() ?? '',
                    messageId: messageId?.toString() ?? '',
                }),
            );
        },
        enabled: conversationId !== null && messageId !== null,
        staleTime: 0,
    });
};

export const ADMIN_CHAT_PARTICIPANTS_QUERY_KEY = 'admin-chat-participants';

export const useAdminChatParticipantsQuery = (
    conversationId: number | null,
    search: string,
    page: number,
    enabled: boolean,
) => {
    return useQuery({
        queryKey: [ADMIN_CHAT_PARTICIPANTS_QUERY_KEY, conversationId, search, page],
        queryFn: async (): Promise<ApiResponse<ChatParticipantsPaginatedResponse>> => {
            const params: Record<string, string> = { page: String(page), per_page: '20' };
            if (search.trim()) params.search = search.trim();
            return ApiHelper.get(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_PARTICIPANTS, {
                    id: conversationId?.toString() ?? '',
                }),
                { params },
            );
        },
        enabled: enabled && conversationId !== null,
    });
};

export const useAssignChatParticipantRoleMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({
            userId,
            role,
        }: AssignParticipantRolePayload & { userId: number }): Promise<ApiResponse<ChatParticipant>> => {
            return ApiHelper.patch(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_PARTICIPANT_ROLE, {
                    id: conversationId?.toString() ?? '',
                    userId: userId.toString(),
                }),
                { role },
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({
                queryKey: [ADMIN_CHAT_PARTICIPANTS_QUERY_KEY, conversationId],
            });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_MESSAGES_QUERY_KEY, conversationId] });
        },
    });
};

export const useDeleteAdminChatMessageMutation = (conversationId: number | null) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (messageId: number): Promise<ApiResponse<unknown>> => {
            return ApiHelper.delete(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_MESSAGE, {
                    id: conversationId?.toString() ?? '',
                    messageId: messageId.toString(),
                }),
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, conversationId] });
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_MESSAGES_QUERY_KEY, conversationId] });
        },
    });
};

export const useDeleteAdminChatConversationMutation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (conversationId: number): Promise<ApiResponse<unknown>> => {
            return ApiHelper.delete(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_CHAT_CONVERSATION_DETAIL, {
                    id: conversationId.toString(),
                }),
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
        },
    });
};
