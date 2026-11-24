import { PdfTemplateLockInfo } from '@/types/models/pdf_template';

export type ExcelMatchedBy = 'exact' | 'numeric';

export type ExcelUnmatchedReason = 'not_found' | 'ambiguous' | 'missing_staff_no';

export interface ExcelResolvedUser {
    id: number;
    name: string;
    staff_no: string | null;
    is_disabled: boolean;
}

export interface ExcelExportSummary {
    total_rows: number;
    matched_count: number;
    unmatched_count: number;
    duplicate_count: number;
    disabled_count: number;
    name_mismatch_count: number;
    numeric_match_count: number;
}

export interface ExcelMatchedRow {
    row_number: number;
    staff_no: string;
    name: string | null;
    user: ExcelResolvedUser;
    matched_by: ExcelMatchedBy;
    is_disabled: boolean;
    name_mismatch: boolean;
}

export interface ExcelUnmatchedRow {
    row_number: number;
    staff_no: string;
    name: string | null;
    reason: ExcelUnmatchedReason;
    candidates: ExcelResolvedUser[];
}

export interface ExcelDuplicateRow {
    row_number: number;
    staff_no: string;
    name: string | null;
    first_row_number: number;
    user: ExcelResolvedUser;
}

export interface ExcelNameMismatchRow {
    row_number: number;
    staff_no: string;
    name: string | null;
    user: ExcelResolvedUser;
}

export interface ExcelExportLimits {
    max_rows: number;
    max_file_size_kb: number;
}

export interface ExcelExportPreview {
    template: { id: number; name: string };
    file_name: string;
    lock_info: PdfTemplateLockInfo | null;
    can_export: boolean;
    summary: ExcelExportSummary;
    matched: ExcelMatchedRow[];
    unmatched: ExcelUnmatchedRow[];
    duplicates: ExcelDuplicateRow[];
}

export interface ExcelExportBatchMeta {
    source_file_name?: string;
    source_file_path?: string;
    excel?: {
        summary: ExcelExportSummary;
        unmatched: ExcelUnmatchedRow[];
        duplicates: ExcelDuplicateRow[];
        name_mismatches: ExcelNameMismatchRow[];
        truncated: boolean;
    };
}
