export type AdminAttendanceEventType = 'check_in' | 'check_out';

export interface AdminAttendanceEvent {
    id: number;
    session_id: number;
    type: AdminAttendanceEventType;
    event_time: string;
    coordinate: string;
    created_at: string;
}
