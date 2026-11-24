/** One document's answer for a field the submission's documents disagree on. */
export interface DocumentMismatchValue {
    /** OCR group the value was read from, e.g. residence_card_scan */
    group: string;
    value: string;
}

export interface DocumentMismatch {
    field: string;
    values: DocumentMismatchValue[];
}

export interface UserPaper {
    id: number;
    user_id: number;
    status: string;
    approver_id: number | null;
    approved_at: string | null;
    rejected_note: string | null;
    /** Identity fields the uploaded documents disagree on; empty when they all line up */
    document_mismatches: DocumentMismatch[] | null;
    /** Step keys the residence status requires but the file does not contain */
    missing_required_steps: string[];
    /** True when the employee is on 留学 and the employee detail has no student card expiry date */
    student_card_expiry_missing: boolean;
    reopenable_steps?: string[];
    emptied_steps?: string[];
    /**
     * The account signs in to the admin, so it is not the one filing these documents.
     * Drops the standing warnings on the tab; the approve modal still warns on mismatches.
     */
    is_web_admin?: boolean;
}

export interface UserPaperItem {
    id: number;
    user_paper_id: number;
    type: string;
    file_name: string;
    file_path: string;
    deleted_from_folder: 'current' | 'old' | 'rejected' | null;
    approved_at: string | null;
    rejected_at: string | null;
    deleted_at: string | null;
    created_at: string;
    folder: 'current' | 'old' | 'rejected' | 'deleted' | null;
}

export interface UserPaperWithItems extends UserPaper {
    items: UserPaperItem[];
}
