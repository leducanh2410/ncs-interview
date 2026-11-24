import { Workplace } from '@/types/models/workplace';

export interface WorkTimeReport {
    id: number;
    month: string;
    user_id: number;
    work_days: number;
    work_minutes: number;
    work_minutes_normal: number;
    work_minutes_night: number;
    work_minutes_overtime: number;
    work_minutes_night_overtime: number;
    work_hours: string;
    work_hours_normal: string;
    work_hours_night: string;
    work_hours_overtime: string;
    work_hours_night_overtime: string;
    work_hours_up_1: string;
}

export interface WorkTimeReportDetail {
    id: number;
    workplace_id: number;
    work_days: number;
    work_minutes: number;
    work_minutes_normal: number;
    work_minutes_night: number;
    work_minutes_overtime: number;
    work_minutes_night_overtime: number;
    work_hours: string;
    work_hours_normal: string;
    work_hours_night: string;
    work_hours_overtime: string;
    work_hours_night_overtime: string;
    work_hours_up_1: string;
}

export interface WorkTimeReportWithRelation extends WorkTimeReport {
    user: User;
    details: (WorkTimeReportDetail & { workplace: Workplace })[];
}
