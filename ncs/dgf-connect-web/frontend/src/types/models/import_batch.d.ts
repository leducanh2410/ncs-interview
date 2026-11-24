import { User } from '@/types/models/user';

export type ImportBatchStatus = 'pending' | 'completed' | 'failed';

export type ImportBatchType = 'shift_order';

export interface ImportBatch {
    id: number;
    type: ImportBatchType;
    status: ImportBatchStatus;
    imported_by: number;
    file_name: string;
    file_path: string;
    error_message: string | null;
    created_count: number;
    updated_count: number;
    created_at: string;
    updated_at: string;
    importer?: User | null;
}

export type ShiftOrderDayStatus = 'new' | 'match' | 'diff';

export interface ShiftOrderPositionTotal {
    workplace_position_id: number;
    name: string | null;
    fixed_total: number;
    assigned_count?: number;
}

export interface ShiftOrderExistingShift {
    shift_id: number;
    fixed_total: number;
    temp_total: number;
    assigned_count: number;
    unassigned_position_count: number;
    positions: ShiftOrderPositionTotal[];
}

export interface ShiftOrderPreviewDay {
    date: string;
    workplace_shift_id: number;
    fixed_total: number;
    spot_total: number;
    positions: ShiftOrderPositionTotal[];
    existing: ShiftOrderExistingShift | null;
    differences: string[];
    status: ShiftOrderDayStatus;
}

export interface ShiftOrderPreviewShift {
    workplace_shift_id: number;
    shift_label: string;
    start_time: string;
    end_time: string;
    days: ShiftOrderPreviewDay[];
}

export interface ShiftOrderPreviewWorkplace {
    workplace_id: number;
    workplace_name: string;
    shifts: ShiftOrderPreviewShift[];
}

export interface ShiftOrderPreview {
    summary: {
        new_count: number;
        match_count: number;
        diff_count: number;
        total_count: number;
    };
    workplaces: ShiftOrderPreviewWorkplace[];
}

export interface ShiftOrderImportPreviewResponse {
    batch: ImportBatch;
    month: string;
    detected_month: string | null;
    preview: ShiftOrderPreview;
}

export interface ShiftOrderImportError {
    code: string;
    workplace_name?: string;
    row?: number;
    cell?: string;
    value?: string;
    date?: string;
    shift_label?: string;
    shift_total?: number;
    positions_total?: number;
}

export interface ShiftOrderConfirmDecision {
    workplace_shift_id: number;
    date: string;
    source: 'file' | 'system';
}

export interface ShiftOrderConfirmResult {
    batch: ImportBatch;
    created_count: number;
    updated_count: number;
    unchanged_count: number;
    skipped_count: number;
}

export interface ImportBatchWorkplaceSummary {
    workplace_id: number;
    workplace_name: string;
    applied_count: number;
    skipped_count: number;
}

export interface ImportBatchDetail {
    batch: ImportBatch;
    month: string | null;
    sheet_name: string | null;
    skipped_count: number;
    workplaces: ImportBatchWorkplaceSummary[];
}
