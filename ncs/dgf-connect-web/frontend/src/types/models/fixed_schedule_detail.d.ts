import { WorkplacePosition } from '@/types/models/workplace_position';
import { WorkplaceShift } from '@/types/models/workplace_shift';

export interface FixedScheduleDetail {
    id: number;
    fixed_schedule_id: number;
    workplace_shift_id: number;
    workplace_position_id: number | null;
    day: number;
}

export interface FixedScheduleDetailWithShift extends FixedScheduleDetail {
    workplace_shift: WorkplaceShift;
    workplace_position?: Pick<WorkplacePosition, 'id' | 'name'> | null;
}
