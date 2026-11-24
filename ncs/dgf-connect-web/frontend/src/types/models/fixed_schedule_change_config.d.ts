export interface FixedScheduleChangeConfig {
    id: number | null;
    workplace_id: number;
    workplace: { id: number; name: string };
    is_enabled: boolean;
    deadline: string | null;
    is_window_open: boolean;
    is_editable: boolean;
    updated_at: string | null;
}
