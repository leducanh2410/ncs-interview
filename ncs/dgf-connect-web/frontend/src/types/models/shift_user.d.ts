import { CheckIn } from '@/types/models/check_in';
import { Shift } from '@/types/models/shift';
import { User, UserWithAbsenceCount, UserWithInfo } from '@/types/models/user';
import { WorkplacePosition } from '@/types/models/workplace_position';

export interface ShiftAbsentNotice {
    id: number;
    shift_user_id: number;
    status: string;
}

export interface ShiftUser {
    id: number;
    shift_id: number;
    user_id: number;
    workplace_position_id: number;
    approver_id: number | null;
    approved_at: string | null;
    rejected_note: string | null;
    status: string;
    salary_per_hour: number | null;
    is_fixed: boolean;
    is_overtime_allowed: boolean;
    is_absent: boolean;
    /** Set when the employee had already read a recruitment notification for this shift. */
    is_urgent_registration: boolean;
    confirmed_at: string | null;
    absent_notice?: ShiftAbsentNotice | null;
}

export interface ShiftUserWithUserInfo extends ShiftUser {
    user: User;
}

export interface ShiftUserWithRelations extends ShiftUser {
    user: UserWithInfo & UserWithAbsenceCount;
    workplace_position: WorkplacePosition | null;
    check_in: CheckIn | null;
}

export interface ShiftUserSchedule extends ShiftUser {
    workplace_position: WorkplacePosition;
    shift: Shift;
    check_in: CheckIn | null;
}
