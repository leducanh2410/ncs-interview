import { WorkplacePosition } from '@/types/models/workplace_position';
import { WorkplaceShift } from '@/types/models/workplace_shift';

type FixedScheduleChangeRequestShift = Pick<WorkplaceShift, 'id' | 'start_time' | 'end_time'>;

export interface FixedScheduleChangeRequestDay {
    id: number;
    day: number;
    current_workplace_shift: FixedScheduleChangeRequestShift | null;
    current_workplace_position: Pick<WorkplacePosition, 'id' | 'name'> | null;
    requested_workplace_shift: FixedScheduleChangeRequestShift | null;
}

export type FixedScheduleChangeRequestStatus = 'pending' | 'approved' | 'rejected';

export interface FixedScheduleChangeRequest {
    id: number;
    fixed_schedule_id: number;
    user: { id: number; name: string; staff_no: string | null };
    workplace: { id: number; name: string };
    status: FixedScheduleChangeRequestStatus;
    processed_by: { id: number; name: string } | null;
    processed_at: string | null;
    created_at: string;
    days: FixedScheduleChangeRequestDay[];
}
