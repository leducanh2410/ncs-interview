export interface ReconciliationUser {
    id: number;
    staff_no: string | null;
    name: string;
    profile_picture: string | null;
    last_name: string | null;
    first_name: string | null;
    last_name_kana: string | null;
    first_name_kana: string | null;
}

export interface EmployeeScan {
    item_id: number;
    file_path: string;
    uploaded_at: string | null;
}

export interface AdminScan {
    id: number;
    file_path: string;
    submitted_at: string | null;
    submitted_by: { id: number; name: string | null } | null;
    confirmed_without_ocr: boolean;
}

export interface AdminScanHistoryEntry extends AdminScan {
    verdict: string;
    mismatch_count: number;
}

export interface PaperFieldComparison {
    field: string;
    employee: string | null;
    admin: string | null;
    /** Display-only fields come back with compared=false and no verdict. */
    compared: boolean;
    verdict: 'match' | 'mismatch' | 'unknown' | null;
    /** Only on residence_number: the superseded slot the scanned card matches. */
    matches_previous_slot?: number | null;
}

export interface ReconciliationRow {
    user: ReconciliationUser;
    employee_scan: EmployeeScan | null;
    admin_scan: AdminScan | null;
    verdict: string;
    mismatch_count: number;
    /**
     * The profile holds no residence card. Independent of `verdict`: it can be true while
     * the verdict is `mismatched`, so the UI must show both rather than infer one.
     */
    employee_residence_missing: boolean;
}

export interface ReconciliationDetail extends ReconciliationRow {
    current_slot: number | null;
    fields: PaperFieldComparison[];
    employee_warnings: string[];
    admin_warnings: string[];
    history: AdminScanHistoryEntry[];
}

export interface ReconciliationFilterOptions {
    workplaces: { id: number; name: string }[];
    /** Empty until a workplace and a date are both chosen. */
    workplace_shifts: { id: number; start_time: string; end_time: string; label: string }[];
}
