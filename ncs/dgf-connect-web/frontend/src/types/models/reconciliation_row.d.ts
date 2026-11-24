export type ReconciliationMatchStatus = 'exact' | 'similar' | 'ambiguous' | 'unmatched' | 'duplicate_external';

export type ReconciliationCheckInStatus = 'none' | 'single' | 'multiple';
export type ReconciliationCompareStatus = 'not_comparable' | 'matched' | 'differs';
export type ReconciliationDecision = 'amazon' | 'dgf' | null;
export type ReconciliationMatchMethod = 'exact_kana' | 'exact_short' | 'staff_no' | 'manual' | null;

export interface ReconciliationUserSummary {
    id: number;
    staff_no: string | null;
    name: string;
    name_kana: string;
    profile_picture: string | null;
    check_ins?: ReconciliationCheckInOption[];
}

export interface ReconciliationCheckInOption {
    id: number;
    workplace_id: number;
    workplace_name: string;
    shift_time: string;
    check_in_time: string | null;
    check_out_time: string | null;
}

export type ReconciliationTimeBasis = 'paid' | 'punch';

export type ReconciliationSummaryField =
    | 'work_minutes'
    | 'work_minutes_normal'
    | 'work_minutes_overtime'
    | 'work_minutes_night'
    | 'work_minutes_night_overtime';

export type ReconciliationSummary = Record<ReconciliationSummaryField, number | null>;

export interface ReconciliationRow {
    id: number;
    external_name: string;
    external_staff_no?: string | null;
    work_date: string;
    external_check_in: string;
    external_check_out: string;
    external_break_minutes: number;
    work_time_range?: string | null;
    time_basis?: ReconciliationTimeBasis;
    external_summary?: ReconciliationSummary | null;
    match_status: ReconciliationMatchStatus;
    match_method: ReconciliationMatchMethod;
    skipped: boolean;
    user: ReconciliationUserSummary | null;
    candidates?: ReconciliationUserSummary[];
    check_in_status: ReconciliationCheckInStatus;
    // Server-side answer to "is this the confirmed row that blocks the export
    // and may be skipped?". Only the API can tell: it depends on whether the
    // row's decisions would have changed a DGF value, which is what Export
    // checks before it looks at the check-in at all.
    can_skip?: boolean;
    check_ins_options?: ReconciliationCheckInOption[];
    check_in_id: number | null;
    workplace: { id: number; name: string } | null;
    compare_status: ReconciliationCompareStatus;
    has_pending_edit_request: boolean;
    dgf_check_in: string | null;
    dgf_check_out: string | null;
    dgf_break_minutes: number | null;
    dgf_summary?: ReconciliationSummary | null;
    summary_differs?: ReconciliationSummaryField[];
    rounding_warnings?: Partial<Record<'check_in_time' | 'check_out_time', string>>;
    decisions: {
        check_in_time: ReconciliationDecision;
        check_out_time: ReconciliationDecision;
        break_minutes: ReconciliationDecision;
    };
}
