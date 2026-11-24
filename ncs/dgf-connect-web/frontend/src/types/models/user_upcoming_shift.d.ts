export interface UserUpcomingShift {
    id: number;
    shift_id: number;
    date: string;
    status: string;
    is_fixed: boolean;
    workplace_name: string;
    start_time: string;
    end_time: string;
}
