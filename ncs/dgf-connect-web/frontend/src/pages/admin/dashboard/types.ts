export type WindowDays = 30 | 60 | 90 | 180 | 365;

export type WindowSelection =
    | { kind: 'preset'; days: WindowDays }
    | { kind: 'custom'; startDate: string; endDate: string };

export interface WorkplaceOption {
    id: number;
    name: string;
    is_test: boolean;
}

export interface DashboardWorkplacesResponse {
    demand_workplaces: WorkplaceOption[];
    reliability_workplaces: WorkplaceOption[];
    demand_is_global: boolean;
    reliability_is_global: boolean;
}

export interface KpiMetric {
    value: number | null;
    delta: number | null;
    /**
     * Daily values aligned with the selected range (oldest-first). Empty when
     * the metric is unavailable (e.g. reliability redacted).
     */
    series: number[];
}

export interface CapacityRow {
    date: string;
    day_of_week: number;
    workplace_id: number;
    workplace_name: string;
    active_pool: number | null;
    assigned: number;
    free_bench: number | null;
    utilization: number | null;
    capacity_tag: 'healthy' | 'tight' | 'critical' | null;
    capacity_reason?: 'no_pool' | null;
}

export interface DailyCheckInsRow {
    date: string;
    total: number;
    /** Map of workplace_id (string) to distinct user count for that day. */
    by_workplace: Record<string, number>;
}

/**
 * Workplace entry for the daily check-ins block. The backend caps the number
 * of distinct workplaces it returns; remaining workplaces are aggregated into
 * a synthetic entry with `is_other: true` and `id: 0`.
 */
export interface DailyCheckInsWorkplace {
    id: number;
    name: string;
    is_other: boolean;
}

export interface DailyCheckInsBlock {
    workplaces: DailyCheckInsWorkplace[];
    rows: DailyCheckInsRow[];
}

export interface KpiResponse {
    active_pool: KpiMetric;
    show_up_rate: KpiMetric;
    avg_daily_check_ins: KpiMetric;
    no_show_risk: KpiMetric;
    daily_check_ins: DailyCheckInsBlock;
    reliability_available: boolean;
}

export interface CapacitySummary {
    critical_count: number | null;
    tight_count: number | null;
    worst: CapacityRow | null;
}

export interface CapacityResponse {
    rows: CapacityRow[];
    summary: CapacitySummary;
}

export type HeatmapMode = 'groups' | 'top_slots';

export interface HeatmapResponse {
    mode: HeatmapMode;
    slots: string[];
    cells: Record<string, Array<number | null>>;
}

export interface ReliabilityRow {
    workplace_id: number;
    workplace_name: string;
    workplace_shift_id?: number;
    slot_label?: string;
    assigned_count: number;
    check_in_count: number;
    show_up_rate: number | null;
    low_sample: boolean;
}

export interface DayOfWeekReliabilityRow {
    /** 0 = Sunday, 1 = Monday, … 6 = Saturday. */
    day_of_week: number;
    assigned_count: number;
    check_in_count: number;
    show_up_rate: number | null;
    low_sample: boolean;
}

export interface ShiftTimeGroupReliabilityRow {
    /** Backend-canonical group key: 'Early Morning' | 'Morning' | 'Midday' | 'Afternoon' | 'Evening' | 'Night'. */
    group: string;
    assigned_count: number;
    check_in_count: number;
    show_up_rate: number | null;
    low_sample: boolean;
}

export interface ReliabilityResponse {
    by_workplace: ReliabilityRow[];
    by_workplace_slot: ReliabilityRow[];
    by_day_of_week: DayOfWeekReliabilityRow[];
    by_shift_time_group: ShiftTimeGroupReliabilityRow[];
}
