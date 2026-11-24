import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { UserWithInfo } from '@/types/models/user';
import { Workplace } from '@/types/models/workplace';
import { WorkplaceImage } from '@/types/models/workplace_image';
import { WorkplaceInstruction } from '@/types/models/workplace_instruction';
import { WorkplacePosition } from '@/types/models/workplace_position';
import { WorkplaceShift, WorkplaceShiftWithRelation } from '@/types/models/workplace_shift';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export type WorkplaceResponseData = Workplace & {
    images: WorkplaceImage[];
    positions: WorkplacePosition[];
    shifts: WorkplaceShift[];
    instructions: WorkplaceInstruction[];
};

type GetWorkplacesResponse = ApiResponse<PaginatedData<Workplace>>;
type GetWorkplaceDetailResponse = ApiResponse<WorkplaceResponseData>;
type WorkplaceResponse = ApiResponse<void>;

/****************************************
 * Workplace                            *
 ****************************************/

export const useGetWorkplacesQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['workplaces', params],
        queryFn: async (): Promise<GetWorkplacesResponse> => {
            return ApiHelper.get(ApiConfig.ADMIN_WORKPLACE_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useGetWorkplaceDetailQuery = (id: string) => {
    return useQuery({
        queryKey: ['workplace', id],
        queryFn: async (): Promise<GetWorkplaceDetailResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_DETAIL, { id }));
        },
    });
};

export const useCreateWorkplaceMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, any>): Promise<WorkplaceResponse> => {
            return ApiHelper.post(ApiConfig.ADMIN_WORKPLACE_LIST, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['workplaces'] });
            await queryClient.invalidateQueries({ queryKey: ['workplace'] });
            await queryClient.invalidateQueries({ queryKey: ['master/all'] });
        },
    });
};

export const useUpdateWorkplaceMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery): Promise<WorkplaceResponse> => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_DETAIL, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['workplaces'] });
            await queryClient.invalidateQueries({ queryKey: ['workplace', id] });
            await queryClient.invalidateQueries({ queryKey: ['master/all'] });
        },
    });
};

export const useDeleteWorkplaceMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: string): Promise<WorkplaceResponse> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_DETAIL, { id }));
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['workplaces'] });
            await queryClient.invalidateQueries({ queryKey: ['master/all'] });
        },
    });
};

/****************************************
 * Images                               *
 ****************************************/

export const useGetWorkplaceImagesQuery = (id: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['workplace-images', id],
        queryFn: async (): Promise<ApiResponse<WorkplaceImage[]>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_IMAGE_LIST, { id }));
        },
        enabled: hasPermission,
    });
};

export const useUploadWorkplaceImagesMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: FormData): Promise<WorkplaceResponse> => {
            return ApiHelper.postForm(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_IMAGE_LIST, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['workplace-images', id] });
        },
    });
};

export const useDeleteWorkplaceImageMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (imageId: string): Promise<WorkplaceResponse> => {
            return ApiHelper.delete(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_IMAGE_DETAIL, { id, imageId }),
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['workplace-images', id] });
        },
    });
};

/****************************************
 * Position                             *
 ****************************************/

export const useGetWorkplacePositionsQuery = (id: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['workplace-positions', id],
        queryFn: async (): Promise<ApiResponse<WorkplacePosition[]>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_POSITION_LIST, { id }));
        },
        enabled: !!id && hasPermission,
    });
};

export const useCreateWorkplacePositionMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery): Promise<WorkplaceResponse> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_POSITION_LIST, { id }), data);
        },
        onSuccess: async () => {
            queryClient.clear();
        },
    });
};

export const useUpdateWorkplacePositionMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            positionId,
            data,
        }: {
            positionId: string;
            data: ParsedUrlQuery;
        }): Promise<WorkplaceResponse> => {
            return ApiHelper.put(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_POSITION_DETAIL, { id, positionId }),
                data,
            );
        },
        onSuccess: async () => {
            queryClient.clear();
        },
    });
};

export const useDeleteWorkplacePositionMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (positionId: string): Promise<ApiResponse<any>> => {
            return ApiHelper.delete(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_POSITION_DETAIL, { id, positionId }),
            );
        },
        onSuccess: async () => {
            queryClient.clear();
        },
    });
};

export const useReorderWorkplacePositionsMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (positions_order: number[]): Promise<ApiResponse<WorkplacePosition[]>> => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_POSITION_REORDER, { id }), {
                positions_order,
            });
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['workplace-positions', id] });
        },
    });
};

/****************************************
 * Shifts                               *
 ****************************************/

export const useGetWorkplaceShiftsQuery = (id: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['workplace-shifts', id],
        queryFn: async (): Promise<ApiResponse<WorkplaceShiftWithRelation[]>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_SHIFT_LIST, { id }));
        },
        enabled: !!id && hasPermission,
    });
};

export const useCreateWorkplaceShiftMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, any>): Promise<ApiResponse<any>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_SHIFT_LIST, { id }), data);
        },
        onSuccess: async () => {
            queryClient.clear();
        },
    });
};

export const useUpdateWorkplaceShiftMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            shiftId,
            data,
        }: {
            shiftId: string;
            data: Record<string, any>;
        }): Promise<ApiResponse<any>> => {
            return ApiHelper.put(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_SHIFT_DETAIL, { id, shiftId }),
                data,
            );
        },
        onSuccess: async () => {
            queryClient.clear();
        },
    });
};

export const useDeleteWorkplaceShiftMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (shiftId: string): Promise<ApiResponse<any>> => {
            return ApiHelper.delete(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_SHIFT_DETAIL, { id, shiftId }),
            );
        },
        onSuccess: async () => {
            queryClient.clear();
        },
    });
};

/****************************************
 * Instruction                          *
 ****************************************/

export const useGetWorkplaceInstructionsQuery = (id: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['workplace-instructions', id],
        queryFn: async (): Promise<ApiResponse<WorkplaceInstruction[]>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_INSTRUCTION_LIST, { id }));
        },
        enabled: hasPermission,
    });
};

export const useCreateWorkplaceInstructionMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery | FormData): Promise<WorkplaceResponse> => {
            const url = CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_INSTRUCTION_LIST, { id });
            if (data instanceof FormData) {
                return ApiHelper.postForm(url, data);
            } else {
                return ApiHelper.post(url, data);
            }
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['workplace-instructions', id] });
        },
    });
};

export const useUpdateWorkplaceInstructionMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            instructionId,
            data,
        }: {
            instructionId: string;
            data: ParsedUrlQuery | FormData;
        }): Promise<WorkplaceResponse> => {
            const url = CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_INSTRUCTION_DETAIL, {
                id,
                instructionId,
            });
            if (data instanceof FormData) {
                data.append('_method', 'PUT');
                return ApiHelper.postForm(url, data);
            } else {
                return ApiHelper.put(url, data);
            }
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['workplace-instructions', id] });
        },
    });
};

export const useDeleteWorkplaceInstructionMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (instructionId: string): Promise<WorkplaceResponse> => {
            return ApiHelper.delete(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_INSTRUCTION_DETAIL, {
                    id,
                    instructionId,
                }),
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['workplace-instructions', id] });
        },
    });
};

/****************************************
 * User                                 *
 ****************************************/
export const useGetWorkplaceUsersQuery = (id: string, page: number, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['workplace-users', id, page],
        queryFn: async (): Promise<ApiResponse<PaginatedData<UserWithInfo>>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_USER_LIST, { id }), {
                params: { page: page.toString() },
            });
        },
        enabled: !!id && hasPermission,
    });
};

export const useAddUsersToWorkplaceMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (userIds: string[]): Promise<WorkplaceResponse> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_USER_LIST, {
                    id,
                }),
                { user_ids: userIds },
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['workplace-users', id] });
        },
    });
};

export const useRemoveUserFromWorkplaceMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (userId: string): Promise<WorkplaceResponse> => {
            return ApiHelper.delete(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_WORKPLACE_USER_DETAIL, {
                    id,
                    userId: userId,
                }),
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['workplace-users', id] });
        },
    });
};
