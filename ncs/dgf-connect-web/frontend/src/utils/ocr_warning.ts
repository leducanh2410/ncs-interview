import i18n from '@/locales/i18n';

/**
 * OCR warnings travel as internal codes (`residence_number_format`, …). Both the paper
 * OCR screen and the reconciliation popup show them to a reviewer judging how far to
 * trust a read, so the wording lives here once rather than in whichever screen was
 * written first.
 */
export const getOcrWarningLabel = (warning: string): string => {
    switch (warning) {
        case 'residence_status_unmapped':
            return i18n.t('Residence status text could not be mapped to a known status');
        case 'residence_number_format':
            return i18n.t('Residence card number format looks invalid');
        case 'gender_invalid_value':
            return i18n.t('Gender could not be read reliably');
        case 'nationality_invalid_value':
            return i18n.t('Nationality could not be read reliably');
        case 'prefecture_invalid_value':
            return i18n.t('Prefecture could not be read reliably');
        case 'postal_code_unresolved':
            return i18n.t('The postal code could not be determined from the address');
        case 'birthday_unparseable_date':
        case 'residence_expiry_date_unparseable_date':
        case 'passport_expiry_date_unparseable_date':
        case 'student_card_expiry_date_unparseable_date':
            return i18n.t('A date on the document could not be read reliably');
        default:
            // An unknown code still says which field to look at, which beats saying nothing.
            return warning;
    }
};
