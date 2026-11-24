import { User } from '@/types/models/user';
import { Workplace } from '@/types/models/workplace';

export type WorkHistoryEditRequestStatus = 'pending' | 'approved' | 'rejected';

export type WorkHistoryEditRequestField = 'check_in_time' | 'check_out_time' | 'break_minutes';

export interface WorkHistoryEditRequestItem {
    id: number;
    work_history_edit_request_id: number;
    field: WorkHistoryEditRequestField;
    old_value: string | null;
    new_value: string | null;
    reason: string;
}

export interface WorkHistoryEditRequestConflict {
    field: WorkHistoryEditRequestField;
    old_value: string | null;
    current_value: string | null;
    new_value: string | null;
}

export interface WorkHistoryEditRequest {
    id: number;
    check_in_id: number;
    workplace_id: number;
    requested_by_id: number | null;
    status: WorkHistoryEditRequestStatus;
    processed_by_id: number | null;
    processed_at: string | null;
    reject_reason: string | null;
    created_at: string;
    updated_at: string;
    workplace?: Workplace;
    requested_by?: User | null;
    processed_by?: User | null;
    items?: WorkHistoryEditRequestItem[];
    check_in?: { id: number; user: User; shift: { id: number; date: string; workplace: Workplace } };
}
