import { Shift } from '@/types/models/shift';
import { User } from '@/types/models/user';
import { WorkHistoryEditRequest } from '@/types/models/work_history_edit_request';

export interface CheckIn {
    id: number;
    shift_user_id: number;
    shift_id: number;
    user_id: number;
    check_in_time: string;
    check_out_time: string;
    checked_in_by: number | null;
    checked_out_by: number | null;
    work_hours: string;
    work_minutes: number;
    break_minutes: number | null;
    late_minutes: number;
    early_minutes: number;
    late_reason: string | null;
    early_reason: string | null;
    is_absent?: boolean;
    shift: Shift;
    user: User;
    latest_edit_request?: WorkHistoryEditRequest | null;
}

export type VerificationStatus = 'pending' | 'verifying' | 'verified';

export interface CheckInVerification {
    id: number;
    check_in_id: number;
    is_check_in: boolean;
    image_path: string | null;
    bounding_box: string | null;
    status: VerificationStatus;
    is_spoofed: boolean;
    spoofed_confidence: number;
    created_at: string;
    updated_at: string;
    check_in: {
        id: number;
        shift_user_id: number;
        user: User;
        shift: Shift;
    };
}
