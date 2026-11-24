export type AdminLeaveType = 'paid_leave' | 'day_off' | 'long_leave' | 'expense_claim';

export type AdminLeaveRequestStatus = 'pending' | 'approved' | 'rejected';

export interface AdminLeaveRequestConflict {
    id: number;
    start_date: string;
    end_date: string;
    status: AdminLeaveRequestStatus;
    type: AdminLeaveType;
}

export interface AdminLeaveRequestAttachment {
    id: number;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    is_image: boolean;
    url: string;
}

export interface AdminLeaveRequest {
    id: number;
    requester_id: number;
    requester_name: string;
    requester_staff_no: string | null;
    requester_profile_picture: string | null;
    type: AdminLeaveType;
    start_date: string | null; // YYYY-MM-DD
    end_date: string | null; // YYYY-MM-DD
    leave_days: number | null; // null when the request carries no date range
    reason: string;
    status: AdminLeaveRequestStatus;
    attachments_count: number;
    has_overlap: boolean;
    created_at: string; // ISO 8601, submission timestamp
    decided_by_id: number | null;
    decided_by_name: string | null;
    decided_at: string | null; // ISO 8601
    reject_reason: string | null;
}

export interface AdminLeaveRequestDetail extends AdminLeaveRequest {
    attachments: AdminLeaveRequestAttachment[];
    conflict: AdminLeaveRequestConflict | null;
}
