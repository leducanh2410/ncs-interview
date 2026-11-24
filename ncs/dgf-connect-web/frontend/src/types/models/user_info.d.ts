export interface UserInfo {
    user_id: number;
    registered_location: string | null;
    birthday: string | null;
    gender: string | null;
    nationality: string | null;
    phone: string | null;
    emergency_contact_phone: string | null;
    postal_code: string | null;
    prefecture: string | null;
    address: string | null;
    japanese_level: string | null;
    residence_number_1: string | null;
    residence_expiry_date_1: string | null;
    residence_status_1: string | null;
    residence_number_2: string | null;
    residence_expiry_date_2: string | null;
    residence_status_2: string | null;
    residence_number_3: string | null;
    residence_expiry_date_3: string | null;
    residence_status_3: string | null;
    passport_number: string | null;
    passport_expiry_date: string | null;
    student_card_expiry_date: string | null;
    is_household_head: boolean | null;
    facebook_link: string | null;
    line_link: string | null;
}
