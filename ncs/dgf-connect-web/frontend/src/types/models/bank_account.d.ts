export interface BankAccountWorkplace {
    id: number;
    name: string;
}

export interface BankAccountUser {
    id: number;
    name: string;
    name_kana?: string;
    staff_no: string | null;
    profile_picture: string | null;
    workplaces: BankAccountWorkplace[];
}

export interface BankAccountOcrFields {
    bank_name: string | null;
    bank_code: string | null;
    branch_name: string | null;
    branch_code: string | null;
    account_type: string | null;
    account_number: string | null;
    holder_name_kana: string | null;
    holder_name_latin: string | null;
    is_yucho: boolean | null;
    yucho_symbol: string | null;
    yucho_number: string | null;
}

export interface BankAccountConfirmedFields {
    bank_name: string | null;
    bank_code: string | null;
    branch_name: string | null;
    branch_code: string | null;
    account_number: string | null;
    account_holder_kana: string | null;
    account_holder_latin: string | null;
    is_yucho: boolean;
    yucho_symbol: string | null;
    yucho_number: string | null;
}

export interface BankAccountNameCheckSide {
    registered: string;
    holder: string;
    verdict: string;
}

export interface BankAccountNameCheckAi {
    available: boolean;
    verdict?: string;
    confidence?: number | null;
    reason?: string | null;
}

export interface BankAccountNameCheck {
    verdict: string;
    kana: BankAccountNameCheckSide | null;
    latin: BankAccountNameCheckSide | null;
    warnings: string[];
    ai: BankAccountNameCheckAi | null;
}

export interface BankAccountInputMismatch {
    field: string;
    ocr_value: string | null;
    input_value: string | null;
}

export interface BankAccountImage {
    id: number;
    file_path: string;
    folder: string;
    url: string;
}

export interface BankAccountListRow {
    id: number;
    user: BankAccountUser;
    payout_method: string;
    status: string;
    bank_name: string | null;
    branch_name: string | null;
    account_number_masked: string | null;
    account_holder_kana: string | null;
    name_check_verdict: string | null;
    mismatch_count: number;
    entered_manually: boolean;
    submitted_at: string | null;
    approved_at: string | null;
    rejected_note: string | null;
}

export interface BankAccount {
    id: number;
    user_id: number;
    payout_method: string;
    status: string;
    bank_name: string | null;
    bank_code: string | null;
    branch_name: string | null;
    branch_code: string | null;
    account_type: string | null;
    account_number_masked: string | null;
    account_number: string | null;
    account_holder_kana: string | null;
    account_holder_latin: string | null;
    is_yucho: boolean;
    yucho_symbol: string | null;
    yucho_number: string | null;
    ocr_result_id: number | null;
    ocr_run_count: number;
    ocr_max_runs: number;
    can_reupload: boolean;
    ocr_fields: BankAccountOcrFields | null;
    ocr_warnings: string[];
    ocr_error: string | null;
    confirmed_fields: BankAccountConfirmedFields | null;
    entered_manually: boolean;
    name_check: BankAccountNameCheck | null;
    input_mismatches: BankAccountInputMismatch[];
    approver_id: number | null;
    submitted_at: string | null;
    approved_at: string | null;
    rejected_note: string | null;
    superseded_at: string | null;
    images: BankAccountImage[];
    images_restricted: boolean;
    created_at: string | null;
    updated_at: string | null;
}

export interface BankAccountDetail extends BankAccount {
    user: BankAccountUser;
    approver: { id: number; name: string } | null;
    history: BankAccount[];
}
