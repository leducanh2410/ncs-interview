import { Shift } from '@/types/models/shift';
import { ShiftUser } from '@/types/models/shift_user';
import { User } from '@/types/models/user';

export interface ShiftReport {
    id: number;
    shift_user_id: number;
    user_id: number;
    date: string;
    early_minutes: number | null;
    late_minutes: number | null;
    is_absent: number;
}

export interface ShiftReportWithRelation extends ShiftReport {
    user: User;
    shift_user: ShiftUser & {
        shift: Shift;
    };
}

export interface MonthlyReport extends User {
    user_id: number;
    late_count: number;
    late_minutes: number;
    early_count: number;
    early_minutes: number;
    absent_count: number;
}
