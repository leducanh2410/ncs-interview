import { ShiftWithCount } from '@/types/models/shift';
import { WorkplaceShift } from '@/types/models/workplace_shift';

export type TimelineItem = {
    workplaceShift: WorkplaceShift;
    shift: ShiftWithCount | null;
};

export type TimelineData = {
    hours: string[];
    data: Record<number, TimelineItem[]>;
};

export type CalendarCard = {
    id: number;
    shiftId: number;
    workplaceName: string;
    startTime: string;
    endTime: string;
    position: string;
    workHours: string | null;
    hasCheckIn: boolean;
    status: string;
    isAbsent?: boolean;
};
