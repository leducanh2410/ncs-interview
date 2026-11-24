import { FixedScheduleDetailWithShift } from '@/types/models/fixed_schedule_detail';

export interface FixedSchedule {
    id: number;
    workplace_id: number;
    user_id: number;
    started_at: string;
}

export interface FixedScheduleWithRelation extends FixedSchedule {
    workplace: Workplace;
    user: User;
    details: FixedScheduleDetailWithShift[];
}
