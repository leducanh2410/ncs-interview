import { ReconciliationRow } from '@/types/models/reconciliation_row';

export type ReconciliationSource = 'amazon_kadou' | 'yamato_staff_data' | 'factory_timesheet';
export type ReconciliationBatchStatus = 'draft' | 'confirmed';

export interface ReconciliationActor {
    id: number;
    name: string;
    staff_no?: string | null;
    profile_picture?: string | null;
}

export interface ReconciliationBatch {
    id: number;
    source: ReconciliationSource;
    original_filename: string;
    company_label: string | null;
    status: ReconciliationBatchStatus;
    punch_count: number;
    skipped_non_punch_count: number;
    matched_count: number;
    differs_count: number;
    unmatched_count: number;
    manual_map_count: number;
    uploaded_by: ReconciliationActor;
    created_at: string;
    confirmed_by: ReconciliationActor | null;
    confirmed_at: string | null;
    exported_by: ReconciliationActor | null;
    exported_at: string | null;
    rows?: ReconciliationRow[];
}
