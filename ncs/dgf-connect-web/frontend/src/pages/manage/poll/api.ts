import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { Poll } from '@/types/models/poll';
import { PollResponse } from '@/types/models/poll_response';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

type GetPollsResponse = ApiResponse<PaginatedData<Poll>>;
type GetPollDetailResponse = ApiResponse<Poll>;
type GetPollResponsesResponse = ApiResponse<PaginatedData<PollResponse>>;
type PollMutationResponse = ApiResponse<void>;

export const usePollsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['polls', params],
        queryFn: async (): Promise<GetPollsResponse> => {
            return ApiHelper.get(ApiConfig.MANAGE_POLL_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const usePollQuery = (id: string, occurrence: string | null = null) => {
    return useQuery({
        queryKey: ['poll', id, occurrence],
        queryFn: async (): Promise<GetPollDetailResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_POLL_DETAIL, { id }), {
                params: occurrence ? { occurrence } : {},
            });
        },
        enabled: !!id,
    });
};

export const usePollResponsesQuery = (id: string, params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['poll-responses', id, params],
        queryFn: async (): Promise<GetPollResponsesResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_POLL_RESPONSES, { id }), { params });
        },
        enabled: !!id && hasPermission,
    });
};

export const useCreatePollMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, any>): Promise<PollMutationResponse> => {
            return ApiHelper.post(ApiConfig.MANAGE_POLL_LIST, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['polls'] });
        },
    });
};

export const useUpdatePollMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, any>): Promise<PollMutationResponse> => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_POLL_DETAIL, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['polls'] });
            await queryClient.invalidateQueries({ queryKey: ['poll', id] });
        },
    });
};

export const useDeletePollMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: string): Promise<PollMutationResponse> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_POLL_DETAIL, { id }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['polls'] });
        },
    });
};

export const useActivatePollMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: string): Promise<PollMutationResponse> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_POLL_ACTIVATE, { id }), {});
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['polls'] });
            await queryClient.invalidateQueries({ queryKey: ['poll'] });
        },
    });
};

export const useDeactivatePollMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: string): Promise<PollMutationResponse> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_POLL_DEACTIVATE, { id }), {});
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['polls'] });
            await queryClient.invalidateQueries({ queryKey: ['poll'] });
        },
    });
};
