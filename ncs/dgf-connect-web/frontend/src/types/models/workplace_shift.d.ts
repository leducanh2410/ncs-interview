import { User } from '@/types/models/user';
import { Workplace } from '@/types/models/workplace';

export interface WorkplaceShift {
    id: number;
    start_time: string;
    end_time: string;
    start_break_time: string;
    end_break_time: string;
    break_duration: string;
    check_in_round_type: number;
    check_in_round_minutes: number;
    check_out_round_type: number;
    check_out_round_minutes: number;
    date_adjustment: number;
    salary_per_hour: number | null;
    workplace_id: number;
    workplace: Workplace;
}

export interface WorkplaceShiftLeader {
    user_id: number;
    bonus_per_hour: number;
    user: User;
}

export interface WorkplaceShiftWithRelation extends WorkplaceShift {
    leaders: WorkplaceShiftLeader[];
}
