export interface ForecastShiftOption {
    id: number;
    workplace_id: number;
    start_time: string;
    end_time: string;
    date_adjustment: number;
    label: string;
}

export interface ForecastWorkplaceOption {
    id: number;
    name: string;
    is_test: boolean;
    shifts: ForecastShiftOption[];
}

export interface ForecastOptionsResponse {
    workplaces: ForecastWorkplaceOption[];
}

export interface ForecastRequest {
    workplace_id: number;
    workplace_shift_id: number;
    date: string;
    requested_count: number;
    radius_km: number;
}

export type ForecastDecision = 'accept' | 'accept_with_backup' | 'negotiate' | 'insufficient_data';
export type ForecastRiskLevel = 'low' | 'medium' | 'high';
export type ForecastConfidence = 'low' | 'medium' | 'high';

export interface ForecastRecommendation {
    decision: ForecastDecision;
    risk_level: ForecastRiskLevel;
    confidence: ForecastConfidence;
    safe_commitment: number | null;
    expected_attendance: number | null;
    expected_shortage: number | null;
    suggested_approval_target: number | null;
    reason_codes: string[];
}

export interface ForecastDemandStats {
    shift_count: number;
    avg_requested: number | null;
    avg_approved: number | null;
    avg_check_in: number | null;
    avg_fill_rate: number | null;
    avg_show_up_rate: number | null;
}

export interface ForecastDemandBlock {
    all_weekdays: ForecastDemandStats;
    same_weekday: ForecastDemandStats;
}

export interface ForecastReliability {
    source: string | null;
    sample_size: number;
    approved_count: number;
    check_in_count: number;
    show_up_rate: number | null;
    no_show_rate: number | null;
    absent_notice_rate: number | null;
    confidence: ForecastConfidence;
}

export interface ForecastCheckpoint {
    key: string;
    label: string;
    sample_size: number;
    avg_approved_count: number | null;
    average_registered_rate: number | null;
    average_fill_rate: number | null;
    shortage_rate: number | null;
}

export interface ForecastFillSpeed {
    checkpoints: ForecastCheckpoint[];
    average_hours_before_start_to_target: number | null;
    reach_target_rate: number | null;
    shortage_h6_rate: number | null;
    shortage_h2_rate: number | null;
    emergency_add_rate: number | null;
    avg_emergency_add_count: number | null;
}

export interface ForecastPool {
    radius_km: number;
    nearby_workplace_count: number;
    eligible_workplace_members: number;
    active_90_day_pool: number | null;
    active_365_day_pool: number | null;
    fixed_schedule_matches: number;
    already_assigned_same_adjusted_date: number;
    available_pool_estimate: number;
    available_extended_pool: number;
}

export interface ForecastCandidate {
    user_id: number;
    name: string;
    staff_no: string | null;
    score: number | null;
    opportunity_days: number;
    worked_days: number;
    target_days: number;
    nearby_days: number;
    last_worked_date: string | null;
    fixed_schedule: boolean;
    confidence: ForecastConfidence;
}

export interface ForecastInputSummary {
    workplace_id: number;
    workplace_shift_id: number;
    date: string;
    day_of_week: number;
    requested_count: number;
    shift_start: string;
    shift_end: string;
    lead_time_hours: number;
}

export interface ForecastResponse {
    input: ForecastInputSummary;
    recommendation: ForecastRecommendation;
    demand_context: {
        recent_1_year: ForecastDemandBlock;
        last_3_months: ForecastDemandBlock;
        same_month_last_year: ForecastDemandBlock;
    };
    reliability: ForecastReliability;
    fill_speed: ForecastFillSpeed;
    pool: ForecastPool;
    candidates: ForecastCandidate[];
    warnings: string[];
}
