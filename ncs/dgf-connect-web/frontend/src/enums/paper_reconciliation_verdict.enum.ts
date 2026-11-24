import { Enum } from '@/types/misc';

/**
 * The backend resolves exactly one of these per employee, in a fixed priority order.
 * Mirrored here so the badge mapping is one table rather than string literals scattered
 * through the page.
 *
 * `employee_data_missing` says the profile holds no residence card. It is not the only
 * place that fact appears: a row can be `mismatched` while the card is still missing, and
 * the response carries `employee_residence_missing` separately for exactly that case.
 */
export const PaperReconciliationVerdictEnum = {
    NOT_UPLOADED: { value: 'not_uploaded', text: 'No admin scan yet' },
    MISMATCHED: { value: 'mismatched', text: 'Does not match' },
    EMPLOYEE_DATA_MISSING: { value: 'employee_data_missing', text: 'No residence card on file' },
    MATCHED: { value: 'matched', text: 'Matches' },
    NOT_COMPARABLE: { value: 'not_comparable', text: 'Cannot compare' },
} satisfies Enum;

export type PaperReconciliationVerdict = keyof typeof PaperReconciliationVerdictEnum;

export const PaperFieldVerdictEnum = {
    MATCH: { value: 'match', text: 'Matches' },
    MISMATCH: { value: 'mismatch', text: 'Differs' },
    UNKNOWN: { value: 'unknown', text: 'Cannot compare' },
} satisfies Enum;
