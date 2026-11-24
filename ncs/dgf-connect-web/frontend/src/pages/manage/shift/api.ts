import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse } from '@/types/api';
import { Shift, ShiftCsvError, ShiftDetail, ShiftPositionTotal, ShiftWithCount } from '@/types/models/shift';
import { ShiftUserWithRelations } from '@/types/models/shift_user';
import { UserWithAbsenceCount, UserWithInfo } from '@/types/models/user';
import { WorkplacePosition } from '@/types/models/workplace_position';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export type GetShiftsResponse = ApiResponse<ShiftWithCount[]>;

export type FixedSchedulePosition = Pick<WorkplacePosition, 'id' | 'name'>;

export type FixedScheduleUser = UserWithInfo &
    UserWithAbsenceCount & {
        fixed_schedule_position?: FixedSchedulePosition | null;
    };

export type GetShiftDetailResponse = ApiResponse<{
    shift: ShiftDetail;
    fixed_schedule_users: FixedScheduleUser[];
    shift_users: ShiftUserWithRelations[];
    positions: WorkplacePosition[];
    position_totals: ShiftPositionTotal[];
    /**
     * On-the-spot bonuses advertised by this shift's recruitment notifications. A list
     * because a shift may have been advertised more than once at different amounts, and
     * the system cannot tell which campaign any individual employee answered.
     */
    hot_bonus_amounts: number[];
}>;

export type ImportShiftCsvResponse = ApiResponse<{
    error_data: ShiftCsvError[];
    valid_data: Record<number, number[]>;
    users_in_workplace: Record<number, UserWithAbsenceCount>;
}>;

export const useGetShiftsQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['manage/shifts', params],
        queryFn: async (): Promise<GetShiftsResponse> => {
            return ApiHelper.get(ApiConfig.MANAGE_SHIFT_LIST, { params });
        },
        enabled: hasPermission,
    });
};

export const useGetActiveShiftsQuery = () => {
    return useQuery({
        queryKey: ['manage/shifts/active'],
        queryFn: async (): Promise<ApiResponse<Shift[]>> => {
            return ApiHelper.get(ApiConfig.MANAGE_SHIFT_LIST_ACTIVE);
        },
    });
};

export const useCreateShiftMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, any>): Promise<ApiResponse<any>> => {
            return ApiHelper.post(ApiConfig.MANAGE_SHIFT_LIST, data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['manage/shifts'] });
        },
    });
};

export const useGetShiftDetailQuery = (id: string, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['manage/shifts', id],
        queryFn: async (): Promise<GetShiftDetailResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_DETAIL, { id }));
        },
        enabled: id !== '0' && hasPermission,
    });
};

export const useUpdateShiftMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, any>): Promise<ApiResponse<any>> => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_DETAIL, { id }), data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['manage/shifts'] });
            queryClient.invalidateQueries({ queryKey: ['manage/shifts', id] });
        },
    });
};

export type ShiftDeleteImpactUser = {
    id: number;
    name: string | null;
    status: string;
    is_fixed: boolean;
};

export type ShiftDeleteBlockReason = 'has_check_ins' | 'ended_with_approved';

export type ShiftDeleteImpact = {
    shift_users: {
        total: number;
        by_status: { pending: number; approved: number; rejected: number };
        fixed: number;
        temp: number;
        /** Capped by the API; compare its length against `total` for a "and N more" hint. */
        users: ShiftDeleteImpactUser[];
    };
    check_ins: { total: number; checked_out: number };
    shift_reports: number;
    shift_absent_notices: number;
    /** Shift Planner drafts that reference this shift or its registrations; discarded on delete. */
    shift_plan_drafts: number;
    /** Open chats linked to this shift; closed (read-only, members kept) on delete. */
    chat_conversations: number;
    recruitment_notifications: number;
    blocked: boolean;
    block_reason: ShiftDeleteBlockReason | null;
};

export type GetShiftDeleteImpactResponse = ApiResponse<ShiftDeleteImpact>;

export const useGetShiftDeleteImpactQuery = (id: string, enabled: boolean) => {
    return useQuery({
        queryKey: ['manage/shifts', id, 'delete-impact'],
        queryFn: async (): Promise<GetShiftDeleteImpactResponse> => {
            return ApiHelper.get(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_DELETE_IMPACT, { id }));
        },
        enabled: id !== '0' && enabled,
        // Always re-check right before the admin confirms: a check-in can land at any time.
        staleTime: 0,
        gcTime: 0,
    });
};

export const useDeleteShiftMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<ApiResponse<{ id: number }>> => {
            return ApiHelper.delete(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_DELETE, { id }));
        },
        onSuccess: () => {
            // Only the list needs refreshing. The detail and delete-impact queries of this shift
            // (['manage/shifts', id, ...]) are still mounted behind the modal; invalidating them
            // would refetch a shift that no longer exists (404 + retries) and, because mutateAsync
            // waits for onSuccess, delay the success notice and the navigation. The page drops
            // them from the cache once it has left (see [id].page.tsx).
            void queryClient.invalidateQueries({
                queryKey: ['manage/shifts'],
                predicate: (query) => query.queryKey[1] !== id,
            });
        },
    });
};

export const exportTimesheet = async (id: string, date: string) => {
    const blob = await ApiHelper.downloadBinary(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_EXPORT, { id }));
    const objectUrl = URL.createObjectURL(blob);
    const link: HTMLAnchorElement = document.createElement('a');
    link.href = objectUrl;
    link.download = `Shift_${id}_${date.replaceAll('-', '')}.xlsx`;
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
};

export const useUpdateShiftConfigMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: Record<string, any>): Promise<ApiResponse<any>> => {
            return ApiHelper.patch(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_UPDATE_CONFIG, { id }), data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['manage/shifts'] });
            queryClient.invalidateQueries({ queryKey: ['manage/shifts', id] });
        },
    });
};

export const useImportCsvShiftMutation = (id: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: FormData): Promise<ImportShiftCsvResponse> => {
            return ApiHelper.postForm(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_IMPORT_CSV, { id }), data);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts'] });
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts', id] });
        },
    });
};

export const useCheckInMutation = (shiftId: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: { shiftUserId: string; date: string; time: string }) => {
            const { shiftUserId, date, time } = data;
            return ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_USER_CHECK_IN, { shiftUserId }), {
                date,
                time,
            });
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts'] });
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts', shiftId] });
            await queryClient.invalidateQueries({ queryKey: ['work-history'] });
            await queryClient.invalidateQueries({ queryKey: ['report'] });
        },
    });
};

export const useCheckOutMutation = (shiftId: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: { shiftUserId: string; date: string; time: string }) => {
            const { shiftUserId, date, time } = data;
            return ApiHelper.post(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_USER_CHECK_OUT, { shiftUserId }),
                {
                    date,
                    time,
                },
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts'] });
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts', shiftId] });
            await queryClient.invalidateQueries({ queryKey: ['work-history'] });
            await queryClient.invalidateQueries({ queryKey: ['report'] });
        },
    });
};

export const useUpdateSalaryMutation = (shiftId: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: { shiftUserId: number; salary: number | null }) => {
            const { shiftUserId } = data;
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_USER_SALARY, { shiftUserId }), {
                salary_per_hour: data.salary,
            });
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts'] });
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts', shiftId] });
            await queryClient.invalidateQueries({ queryKey: ['work-history'] });
            await queryClient.invalidateQueries({ queryKey: ['report'] });
        },
    });
};

export type OvertimeEligibilityCandidate = {
    shift_user_id: number;
    user_id: number;
    is_overtime_allowed: boolean;
    weekly_hours: number;
    max_hour_per_week: number;
    remaining_hours: number;
    is_eligible: boolean;
};

export type OvertimeEligibilityResponse = ApiResponse<{
    overtime_minutes: number;
    candidates: OvertimeEligibilityCandidate[];
}>;

export const useOvertimeEligibilityMutation = (shiftId: string) => {
    return useMutation({
        mutationFn: async (overtimeMinutes: number): Promise<OvertimeEligibilityResponse> => {
            return ApiHelper.get(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_OVERTIME_ELIGIBILITY, { id: shiftId }),
                { params: { overtime_minutes: overtimeMinutes.toString() } },
            );
        },
    });
};

export type WorkLoadDay = {
    date: string;
    hours: number;
    actual_hours: number;
    scheduled_hours: number;
};

export type ShiftWeeklyWorkLoad = {
    user_id: number;
    weekly_hours: number;
    shift_hours: number;
    is_shift_counted: boolean;
    projected_hours: number;
    max_hour_per_week: number;
    remaining_hours: number;
    status: string;
    is_blocked: boolean;
    blocked_over_hours: number | null;
    is_custom_limit: boolean;
    limit_start_date: string | null;
    limit_expiry_date: string | null;
    window_start: string;
    window_end: string;
    days: WorkLoadDay[];
};

export const fetchShiftWeeklyWorkLoads = async (
    shiftId: string,
    userIds: string[],
    abortSignal?: AbortSignal,
): Promise<Record<string, ShiftWeeklyWorkLoad>> => {
    const response: ApiResponse<ShiftWeeklyWorkLoad[]> = await ApiHelper.post(
        CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_WEEKLY_WORK_LOAD, { id: shiftId }),
        { user_ids: userIds },
        { abortSignal },
    );
    return Object.fromEntries((response.data ?? []).map((workLoad) => [workLoad.user_id.toString(), workLoad]));
};

export const useShiftWeeklyWorkLoadsQuery = (shiftId: string, userIds: string[]) => {
    const sortedUserIds = [...userIds].sort();
    return useQuery({
        queryKey: ['manage/shifts', shiftId, 'weekly-work-load', sortedUserIds],
        queryFn: ({ signal }) => fetchShiftWeeklyWorkLoads(shiftId, sortedUserIds, signal),
        enabled: Boolean(shiftId) && sortedUserIds.length > 0,
        placeholderData: keepPreviousData,
    });
};

export const useBulkUpdateOvertimeMutation = (shiftId: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (shiftUserIds: number[]) => {
            return ApiHelper.put(CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_OVERTIME_BULK, { id: shiftId }), {
                shift_user_ids: shiftUserIds,
            });
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts'] });
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts', shiftId] });
        },
    });
};

export const useUpdateAllowOvertimeMutation = (shiftId: string) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (data: { shiftUserId: number; isAllowed: number }) => {
            const { shiftUserId } = data;
            return ApiHelper.put(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_SHIFT_USER_ALLOW_OVERTIME, { shiftUserId }),
                {
                    is_overtime_allowed: data.isAllowed,
                },
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts'] });
            await queryClient.invalidateQueries({ queryKey: ['manage/shifts', shiftId] });
        },
    });
};
