import { User } from '@/types/models/user';
import { Workplace } from '@/types/models/workplace';
import { WorkplaceShift, WorkplaceShiftWithRelation } from '@/types/models/workplace_shift';

export interface Shift {
    id: number;
    workplace_id: number;
    workplace_shift_id: number;
    workplace_shift: WorkplaceShift;
    date: string;
    temp_total: number;
    fixed_total: number;
    creator_id: number;
    created_at: string;
    updated_at: string;
    workplace: Workplace;
    break_minutes: number;
    is_overtime_by_duration: boolean;
}

export interface ShiftWithCount extends Shift {
    temp_count: number;
    temp_pending_count: number;
    fixed_count: number;
    fixed_pending_count: number;
    temp_check_in: number;
    fixed_check_in: number;
    temp_confirmed: number;
    fixed_confirmed: number;
    max_registered_temp: number;
}

export interface ShiftDetail extends ShiftWithCount {
    creator: User;
    salary_per_hour: number;
    work_hours: number;
    workplace_shift: WorkplaceShiftWithRelation;
}

export interface ShiftPositionTotal {
    id: number;
    shift_id: number;
    workplace_position_id: number;
    fixed_total: number;
    temp_total: number;
}

export interface ShiftCsvError {
    staff_no: string;
    user_name: string | null;
    error_code: string;
}
