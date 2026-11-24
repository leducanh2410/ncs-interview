import { UserCustomConfig } from '@/types/models/config';
import { UserInfo } from '@/types/models/user_info';

export interface User {
    id: number;
    first_name: string;
    last_name: string;
    first_name_kana: string;
    last_name_kana: string;
    name: string;
    name_kana: string;
    email: string;
    staff_no: string | null;
    is_disabled: boolean;
    is_test: boolean;
    profile_picture: string;
    paper_status: string;
    settings: Record<string, string> | null;
    student_card_expiry_alert: boolean;
    residence_card_expiry_alert: boolean;
    passport_expiry_alert: boolean;
    face_registered: boolean;
    outstanding_document_steps?: string[];
    /** Present in list responses: the user's documents were read as different people */
    has_document_mismatch?: boolean;
    /** Present in list and detail responses: an approved file that lacks documents its status requires */
    has_missing_required_documents?: boolean;
    /** Present in list and detail responses: an approved 留学 file whose student card has no expiry date */
    has_missing_student_card_expiry?: boolean;
    custom_configs?: UserCustomConfig[];
}

export interface UserWithInfo extends User {
    info: UserInfo | null;
}

export interface UserWithAbsenceCount extends User {
    absence_count: number;
    // Shifts (at a similar time range) the user worked at this workplace in the last 90 days.
    // Only populated by the shift "add user" search; null/undefined when not computed.
    similar_shift_count?: number | null;
}
