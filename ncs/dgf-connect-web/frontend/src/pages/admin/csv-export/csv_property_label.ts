type Translator = (key: string) => string;

export const getCsvPropertyLabel = (key: string, t: Translator): string => {
    switch (key) {
        case 'first_name':
            return t('First name');
        case 'last_name':
            return t('Last name');
        case 'first_name_kana':
            return t('First name (kana)');
        case 'last_name_kana':
            return t('Last name (kana)');
        case 'short_name':
            return t('Display name');
        case 'birthday':
            return t('Birthday');
        case 'gender':
            return t('Gender');
        case 'nationality':
            return t('Nationality');
        case 'phone':
            return t('Phone');
        case 'emergency_contact_phone':
            return t('Emergency contact phone');
        case 'email':
            return t('Email');
        case 'postal_code':
            return t('Postal code');
        case 'prefecture':
            return t('Prefecture');
        case 'address':
            return t('Address');
        case 'registered_location':
            return t('Registered location');
        case 'staff_no':
            return t('Staff number');
        case 'japanese_level':
            return t('Japanese level');
        case 'residence_number':
            return t('Residence number');
        case 'residence_expiry_date':
            return t('Residence expiry date');
        case 'residence_status':
            return t('Residence status');
        case 'passport_number':
            return t('Passport number');
        case 'passport_expiry_date':
            return t('Passport expiry date');
        case 'student_card_expiry_date':
            return t('Student card expiry date');
        case 'is_household_head':
            return t('Household Head');
        case 'facebook_link':
            return t('Facebook link');
        case 'line_link':
            return t('LINE link');
        default:
            return key;
    }
};
