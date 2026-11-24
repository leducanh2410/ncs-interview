import { QueryClient, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { UserConfig } from '@/types/models/config';
import { User } from '@/types/models/user';
import { UserDevice } from '@/types/models/user_device';
import { UserImage } from '@/types/models/user_image';
import { UserInfo } from '@/types/models/user_info';
import { UserLockHistory } from '@/types/models/user_lock_history';
import { UserPaperWithItems } from '@/types/models/user_paper';
import { UserUpcomingShift } from '@/types/models/user_upcoming_shift';
import { Workplace, WorkplaceSimple } from '@/types/models/workplace';
import { ApiConfig } from '@/configs/api.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import ApiHelper, { ApiError } from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

type GetUsersResponse = ApiResponse<
    PaginatedData<User & { info: UserInfo | null; workplaces: WorkplaceSimple[]; roleNames: string }>
>;

type GetUserConfigResponse = ApiResponse<UserConfig>;

type GetUserLockHistoriesResponse = ApiResponse<UserLockHistory[]>;

type GetUserUpcomingShiftsResponse = ApiResponse<UserUpcomingShift[]>;

export const useGetUsersQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['users', params],
        queryFn: async (): Promise<GetUsersResponse> => {
            return ApiHelper.get(ApiConfig.ADMIN_USER_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useCreateManagerMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery): Promise<ApiResponse<User>> => {
            return ApiHelper.post(ApiConfig.ADMIN_USER_LIST, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['users'] });
        },
    });
};

export type EmployeeInitUser = Pick<
    User,
    'email' | 'first_name' | 'last_name' | 'first_name_kana' | 'last_name_kana' | 'staff_no'
> & {
    cross_staff_id: string | null;
    info: Partial<UserInfo> | null;
};

export type EmployeeInitResponseData = {
    key: string;
    email: string;
    user: EmployeeInitUser | null;
};

export const useInitEmployeeMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery): Promise<ApiResponse<EmployeeInitResponseData>> => {
            return ApiHelper.post(ApiConfig.ADMIN_USER_EMPLOYEE_INIT, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['users'] });
        },
    });
};

export const useSetEmployeePasswordMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery): Promise<ApiResponse<boolean>> => {
            return ApiHelper.post(ApiConfig.ADMIN_USER_EMPLOYEE_SET_PASSWORD, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['users'] });
        },
    });
};

export const useRegisterEmployeeMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery): Promise<ApiResponse<User>> => {
            return ApiHelper.post(ApiConfig.ADMIN_USER_EMPLOYEE_REGISTER, data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['users'] });
        },
    });
};

export type UserResponseData = User & { info: UserInfo | null; images: UserImage[]; roleNames: string[] };

type GetUserResponse = ApiResponse<UserResponseData>;

export const useGetUserQuery = (id: string) => {
    return useQuery({
        queryKey: ['user', id],
        queryFn: async (): Promise<GetUserResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_DETAIL, { id }));
        },
    });
};

export const useUpdateUserMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery): Promise<ApiResponse<User>> => {
            return ApiHelper.patch(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_DETAIL, { id }), data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['users'] });
            queryClient.invalidateQueries({ queryKey: ['user', id] });
            // The papers tab reads the profile too: a 留学 file is held out of approval until
            // `student_card_expiry_date` is recorded, and this form is where that date is
            // written. Without this the tab keeps its stale warning and its disabled Submit
            // for the whole `staleTime`, and the admin who just did the right thing is told
            // it did not work — the tab is not remounted when it is returned to.
            queryClient.invalidateQueries({ queryKey: ['user-papers', id] });
        },
    });
};

const invalidateUserLockState = (queryClient: QueryClient, id: string) => {
    queryClient.invalidateQueries({ queryKey: ['users'] });
    queryClient.invalidateQueries({ queryKey: ['user', id] });
    queryClient.invalidateQueries({ queryKey: ['user-lock-history', id] });
    queryClient.invalidateQueries({ queryKey: ['user-upcoming-shifts', id] });
    queryClient.invalidateQueries({ queryKey: ['user-groups', id] });
    queryClient.invalidateQueries({ queryKey: ['groups'] });
    queryClient.invalidateQueries({ queryKey: ['manage/shift-planning'] });
    queryClient.invalidateQueries({ queryKey: ['manage/shifts'] });
};

export const isUserLockStateChangedError = (error: unknown) =>
    error instanceof ApiError &&
    (error.message === ErrorCodeEnum.USER_ALREADY_DISABLED.value ||
        error.message === ErrorCodeEnum.USER_ALREADY_ENABLED.value);

export const useDisableUserMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery): Promise<ApiResponse<any>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_DISABLE, { id }), data);
        },
        onSuccess: () => invalidateUserLockState(queryClient, id),
        onError: (error) => {
            if (isUserLockStateChangedError(error)) {
                invalidateUserLockState(queryClient, id);
            }
        },
    });
};

export const useEnableUserMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery): Promise<ApiResponse<any>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_ENABLE, { id }), data);
        },
        onSuccess: () => invalidateUserLockState(queryClient, id),
        onError: (error) => {
            if (isUserLockStateChangedError(error)) {
                invalidateUserLockState(queryClient, id);
            }
        },
    });
};

export const useResetPasswordMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery): Promise<ApiResponse<any>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_RESET_PASSWORD, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['users'] });
            await queryClient.invalidateQueries({ queryKey: ['user', id] });
        },
    });
};

export const useRestoreUserMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<ApiResponse<any>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_RESTORE, { id }), null);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['users'] });
            queryClient.invalidateQueries({ queryKey: ['user', id] });
        },
    });
};

/**
 * @param withinDays narrows the answer to a window starting now, dropping shifts that have
 *        already begun. Left out, the whole future from tomorrow is returned — what locking
 *        an account means by upcoming.
 */
export const useGetUserUpcomingShiftsQuery = (id: string, withinDays?: number) => {
    return useQuery({
        queryKey: ['user-upcoming-shifts', id, withinDays ?? null],
        queryFn: async (): Promise<GetUserUpcomingShiftsResponse> => {
            const url = CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_UPCOMING_SHIFT_LIST, { id });

            return ApiHelper.get(withinDays === undefined ? url : `${url}?within_days=${withinDays}`);
        },
        enabled: false,
    });
};

export const useGetUserLockHistoriesQuery = (id: string) => {
    return useQuery({
        queryKey: ['user-lock-history', id],
        queryFn: async (): Promise<GetUserLockHistoriesResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_LOCK_HISTORY_LIST, { id }));
        },
    });
};

export const useUploadImageMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: FormData): Promise<ApiResponse<UserInfo>> => {
            return ApiHelper.postForm(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_IMAGE_LIST, { id }), data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['users'] });
            queryClient.invalidateQueries({ queryKey: ['user', id] });
        },
    });
};

export const useGetUserDevicesQuery = (id: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['user-devices', id],
        queryFn: async (): Promise<ApiResponse<UserDevice[]>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_DEVICE_LIST, { id }));
        },
        enabled: hasPermission,
    });
};

export const useDeleteDeviceMutation = (userId: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: string): Promise<ApiResponse<any>> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_DEVICE_DETAIL, { userId, id }));
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['user-devices', userId] });
        },
    });
};

export const useGetUserWorkplacesQuery = (id: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['user-workplaces', id],
        queryFn: async (): Promise<ApiResponse<Workplace[]>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_WORKPLACE_LIST, { id }));
        },
        enabled: hasPermission,
    });
};

export const useDeleteWorkplaceMutation = (userId: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (id: string): Promise<ApiResponse<any>> => {
            return ApiHelper.delete(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_WORKPLACE_DETAIL, { userId, id }),
            );
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['users'] });
            queryClient.invalidateQueries({ queryKey: ['user-workplaces', userId] });
        },
    });
};

export const useAddWorkplacesMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (ids: string[]): Promise<ApiResponse<any>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_WORKPLACE_LIST, { id }), {
                workplace_ids: ids,
            });
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['users'] });
            queryClient.invalidateQueries({ queryKey: ['user-workplaces', id] });
        },
    });
};

export type UserRole = {
    id: number;
    name: string;
    team_id: number | null;
};

export const useGetUserRolesQuery = (id: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['user-roles', id],
        queryFn: async (): Promise<ApiResponse<UserRole[]>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_ROLE_LIST, { id }));
        },
        enabled: hasPermission,
    });
};

export const useAssignRoleMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: { role_name: string; workplace_id: number | null }): Promise<ApiResponse<any>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_ROLE_LIST, { id }), data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['users'] });
            queryClient.invalidateQueries({ queryKey: ['user', id] });
            queryClient.invalidateQueries({ queryKey: ['user-roles', id] });
        },
    });
};

export const useRemoveRoleMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: { role_name: string; workplace_id: number | null }): Promise<ApiResponse<any>> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_ROLE_DELETE, { id }), {
                params: {
                    role_name: data.role_name,
                    workplace_id: data.workplace_id?.toString() || '',
                },
            });
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['users'] });
            queryClient.invalidateQueries({ queryKey: ['user', id] });
            queryClient.invalidateQueries({ queryKey: ['user-roles', id] });
        },
    });
};

export const useGetUserPapersQuery = (id: string, hasPermission: boolean = true) => {
    return useQuery({
        queryKey: ['user-papers', id],
        queryFn: async (): Promise<ApiResponse<UserPaperWithItems | null>> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_PAPERS, { id }));
        },
        enabled: hasPermission,
    });
};

export const useApproveUserPaperMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: {
            items: Array<{ id: number; approved: boolean }>;
            rejected_note?: string;
        }): Promise<ApiResponse<any>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_PAPERS_APPROVE, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['user-papers', id] });
            await queryClient.invalidateQueries({ queryKey: ['user', id] });
        },
    });
};

/**
 * Sends an approved file back to `pending` so the employee must complete the documents
 * their residence status requires. Invalidates the list too: the badge that pointed here
 * is answered by the same status change.
 */
export const useRequestPaperCompletionMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<ApiResponse<any>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_PAPERS_REQUEST_COMPLETION, { id }),
                null,
            );
        },
        // Settled, not success: a refusal means the file moved on since the tab was loaded,
        // which is exactly when the screen most needs to catch up
        onSettled: async () => {
            await queryClient.invalidateQueries({ queryKey: ['user-papers', id] });
            await queryClient.invalidateQueries({ queryKey: ['user', id] });
            await queryClient.invalidateQueries({ queryKey: ['users'] });
        },
    });
};

export const useReopenPaperStepsMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<ApiResponse<any>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_PAPERS_REOPEN_STEPS, { id }), null);
        },
        onSettled: async () => {
            await queryClient.invalidateQueries({ queryKey: ['user-papers', id] });
        },
    });
};

export const useReopenEmptiedPaperStepsMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<ApiResponse<{ reopened_steps: string[]; cleared_steps: string[] }>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_PAPERS_REOPEN_EMPTIED_STEPS, { id }),
                null,
            );
        },
        onSettled: async () => {
            await queryClient.invalidateQueries({ queryKey: ['user-papers', id] });
            await queryClient.invalidateQueries({ queryKey: ['user', id] });
            await queryClient.invalidateQueries({ queryKey: ['users'] });
        },
    });
};

export const useMoveUserPaperMutation = (userId: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: { ids: number[]; folder: string }): Promise<ApiResponse<any>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_PAPERS_MOVE, { id: userId }), {
                ids: data.ids,
                folder: data.folder,
            });
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['user-papers', userId] });
        },
    });
};

export const useRestoreUserPaperMutation = (userId: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (ids: number[]): Promise<ApiResponse<any>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_PAPERS_RESTORE, { id: userId }), {
                ids,
            });
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['user-papers', userId] });
            await queryClient.invalidateQueries({ queryKey: ['user', userId] });
        },
    });
};

export type PaperOcrGroup = 'residence_card' | 'passport' | 'student_card';

export type PaperOcrGroupResult = {
    group: PaperOcrGroup;
    types: string[];
    status: 'ok' | 'failed';
    error: string | null;
    fields: Record<string, string | null> | null;
    warnings: string[];
    ocr_result_id: number;
    from_cache: boolean;
    cached_at: string | null;
};

export const useExtractUserPapersOcrMutation = (userId: string) => {
    return useMutation({
        mutationFn: async (params: {
            types: string[];
            force?: boolean;
        }): Promise<ApiResponse<{ results: PaperOcrGroupResult[] }>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_PAPERS_OCR, { id: userId }),
                params,
            );
        },
    });
};

export type PostalCodeConfidence = 'exact' | 'ambiguous' | 'city_default' | 'none';

export type PostalCodeCandidate = {
    postal_code: string;
    prefecture: string;
    city: string;
    town: string;
    town_detail: string | null;
};

export type PostalCodeResolution = {
    // Seven digits, only set when the confidence is 'exact'
    postal_code: string | null;
    confidence: PostalCodeConfidence;
    matched: { prefecture: string | null; city: string | null; town: string | null; rest: string };
    candidates: PostalCodeCandidate[];
};

// A lookup rather than a write, but a mutation because it runs on demand from a button
export const useResolvePostalCodeMutation = () => {
    return useMutation({
        mutationFn: async (params: {
            address: string;
            prefecture?: string | null;
        }): Promise<ApiResponse<PostalCodeResolution>> => {
            return ApiHelper.get(ApiConfig.ADMIN_POSTAL_CODE_RESOLVE, {
                params: { address: params.address, prefecture: params.prefecture || undefined },
            });
        },
    });
};

export const useApplyUserPapersOcrMutation = (userId: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (params: { ocr_result_id: number; fields: string[] }): Promise<ApiResponse<User>> => {
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_PAPERS_OCR_APPLY, { id: userId }),
                params,
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['user', userId] });
            await queryClient.invalidateQueries({ queryKey: ['user-papers', userId] });
        },
    });
};

export const useSyncUserToCrossStaffMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<ApiResponse<any>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_TO_CROSS_STAFF, { id }), null);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['users'] });
            await queryClient.invalidateQueries({ queryKey: ['user', id] });
        },
    });
};

export const useSyncUserFromCrossStaffMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<ApiResponse<any>> => {
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_FROM_CROSS_STAFF, { id }), null);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['users'] });
            await queryClient.invalidateQueries({ queryKey: ['user', id] });
        },
    });
};

export const useGetUserConfigsQuery = (id: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['user-config', id],
        queryFn: async (): Promise<GetUserConfigResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_CONFIGS, { id }));
        },
        enabled: hasPermission,
    });
};

export const useUpdateUserConfigsMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: ParsedUrlQuery): Promise<GetUserConfigResponse> => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_CONFIGS, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['user-config', id] });
        },
    });
};
