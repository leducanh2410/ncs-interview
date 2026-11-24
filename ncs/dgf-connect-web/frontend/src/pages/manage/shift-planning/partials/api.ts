import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';

// ---------------------------------------------------------------------------
// Shift Planner API layer. Mirrors the GET /draft/finalize/lock endpoints in
// app/Http/Controllers/Manage/ShiftPlanningController.php.
// ---------------------------------------------------------------------------

export type ApiWorkplaceShift = {
    id: number;
    start_time: string;
    end_time: string;
    start_break_time: string | null;
    end_break_time: string | null;
    break_duration: number | null;
    date_adjustment: number;
    is_active: boolean;
    salary_per_hour: number | null;
};

export type ApiPosition = { id: number; name: string; is_active: boolean; sort_order: number | null };

export type ApiFixedScheduleDetail = { workplace_shift_id: number; day: number; workplace_position_id: number | null };

export type ApiMember = {
    id: number;
    staff_no: string | null;
    name: string;
    paper_status: string | null;
    is_disabled: boolean;
    student_card_expiry_alert: boolean;
    residence_card_expiry_alert: boolean;
    passport_expiry_alert: boolean;
    absence_count: number;
    fixed_schedules: ApiFixedScheduleDetail[];
    // Approved shifts worked on this workplace over the trailing activity window.
    recent_shift_count: number;
};

export type ApiConcreteShift = {
    id: number;
    date: string;
    workplace_shift_id: number;
    fixed_total: number;
    temp_total: number;
    max_registered_temp: number | null;
    break_minutes: number | null;
};

export type ApiLiveAssignment = {
    id: number;
    shift_id: number;
    user_id: number;
    workplace_position_id: number | null;
    is_fixed: boolean | number;
    status: string;
    confirmed_at: string | null;
};

export type ApiDraft = {
    id: number;
    date: string;
    workplace_shift_id: number;
    user_id: number;
    shift_user_id: number | null;
    workplace_position_id: number | null;
    is_fixed: boolean | number;
    action: 'add' | 'update' | 'remove';
};

export type LockState = {
    user_id: number;
    name: string;
    acquired_at: string;
    last_activity_at: string;
    ttl: number;
    is_me: boolean;
};

export type ShiftPlanningResponse = {
    workplace: { id: number; name: string; min_rest_hours: number | null };
    window: { from: string; to: string; min_date: string; recent_shift_days: number };
    workplace_shifts: ApiWorkplaceShift[];
    positions: ApiPosition[];
    members: ApiMember[];
    shifts: ApiConcreteShift[];
    live_assignments: ApiLiveAssignment[];
    drafts: ApiDraft[];
    lock: LockState | null;
};

export type DraftRowPayload = {
    date: string;
    workplace_shift_id: number;
    user_id: number;
    shift_user_id?: number | null;
    workplace_position_id: number | null;
    is_fixed: boolean;
    action: 'add' | 'update' | 'remove';
    target_fixed_total?: number | null;
    target_temp_total?: number | null;
};

export type FinalizeErrorEntry = {
    user_id?: number;
    workplace_shift_id?: number;
    date: string;
    code: string;
    extra: Record<string, unknown> | null;
};

export type FinalizeDateResultApi = {
    date: string;
    committed: boolean;
    notified_count: number;
    errors: FinalizeErrorEntry[];
};

const QUERY_KEY = 'manage/shift-planning';

export const useShiftPlanningQuery = (
    workplaceId: string | null,
    from: string | null,
    memberActivityDays: string,
    enabled = true,
) => {
    return useQuery({
        queryKey: [QUERY_KEY, workplaceId, from, memberActivityDays],
        queryFn: async (): Promise<ShiftPlanningResponse> => {
            const res = await ApiHelper.get<ShiftPlanningResponse>(ApiConfig.MANAGE_SHIFT_PLANNING, {
                params: {
                    workplace_id: workplaceId as string,
                    member_activity_days: memberActivityDays,
                    ...(from ? { from } : {}),
                },
            });
            return res.data as ShiftPlanningResponse;
        },
        enabled: enabled && !!workplaceId,
        // Don't silently clobber unsaved local edits; refetches are triggered explicitly.
        refetchOnWindowFocus: false,
    });
};

export const useSaveDraftMutation = () => {
    return useMutation({
        mutationFn: async (payload: {
            workplace_id: number;
            from: string;
            to: string;
            drafts: DraftRowPayload[];
        }): Promise<ApiResponse<{ saved: number }>> => {
            // On validation failure the endpoint responds 422 with `data.errors:
            // FinalizeErrorEntry[]`, surfaced to callers via the mutation's onError as
            // `err.data.errors` (same shape Finalize returns).
            return ApiHelper.put(ApiConfig.MANAGE_SHIFT_PLANNING_DRAFT, payload);
        },
    });
};

export const useFinalizeMutation = () => {
    return useMutation({
        mutationFn: async (payload: {
            workplace_id: number;
            cells: { user_id: number; date: string }[];
        }): Promise<ApiResponse<{ results: FinalizeDateResultApi[] }>> => {
            return ApiHelper.post(ApiConfig.MANAGE_SHIFT_PLANNING_FINALIZE, payload);
        },
    });
};

export const useLockMutation = () => {
    return useMutation({
        mutationFn: async (payload: {
            workplace_id: number;
            force?: boolean;
        }): Promise<ApiResponse<{ lock: LockState | null }>> => {
            return ApiHelper.post(ApiConfig.MANAGE_SHIFT_PLANNING_LOCK, payload);
        },
    });
};

export const useReleaseLockMutation = () => {
    return useMutation({
        mutationFn: async (workplaceId: number): Promise<ApiResponse<{ lock: null }>> => {
            return ApiHelper.delete(ApiConfig.MANAGE_SHIFT_PLANNING_LOCK, {
                params: { workplace_id: String(workplaceId) },
            });
        },
    });
};

export const useCreateShiftMutation = () => {
    return useMutation({
        mutationFn: async (payload: {
            date: string;
            workplace_shift_id: number;
            fixed_total: number;
            temp_total: number;
            max_registered_temp: number | null;
            break_minutes: number | null;
        }): Promise<ApiResponse<{ id: number }>> => {
            return ApiHelper.post(ApiConfig.MANAGE_SHIFT_LIST, payload);
        },
    });
};

export const useUpdateShiftConfigMutation = () => {
    return useMutation({
        mutationFn: async (payload: {
            shiftId: number;
            fixed_total: number;
            temp_total: number;
            max_registered_temp: number | null;
            break_minutes: number | null;
        }): Promise<ApiResponse<{ id: number }>> => {
            const { shiftId, ...body } = payload;
            return ApiHelper.patch(ApiConfig.MANAGE_SHIFT_UPDATE_CONFIG.replace(':id', String(shiftId)), body);
        },
    });
};

export const useInvalidateShiftPlanning = () => {
    const queryClient = useQueryClient();
    return () => queryClient.invalidateQueries({ queryKey: [QUERY_KEY] });
};
