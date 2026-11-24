import { UserInfo } from '@/types/models/user_info';
import { getResidenceCardForNumber } from '@/helpers/residence.helper';
import { PaperOcrGroup } from '@/pages/admin/user/api';

export type OcrCompareStatus = 'match' | 'mismatch' | 'not_read' | 'no_data';

export type OcrCompareRow = {
    field: string;
    extracted: string | null;
    current: string | null;
    status: OcrCompareStatus;
};

export type OcrCompareUser = {
    last_name: string | null;
    first_name: string | null;
    last_name_kana: string | null;
    first_name_kana: string | null;
    info: Partial<UserInfo> | null;
};

const DATE_FIELDS = new Set(['birthday', 'residence_expiry_date', 'passport_expiry_date', 'student_card_expiry_date']);

// Stored as free text ("350-0235", "〒３５００２３５"), while the OCR side is always seven plain digits
const POSTAL_CODE_FIELDS = new Set(['postal_code']);

export type OcrCompareKind = 'text' | 'date' | 'postal_code';

const compareKindOf = (field: string): OcrCompareKind =>
    DATE_FIELDS.has(field) ? 'date' : POSTAL_CODE_FIELDS.has(field) ? 'postal_code' : 'text';

const GROUP_FIELD_SOURCES: Record<
    PaperOcrGroup,
    Record<string, (user: OcrCompareUser, fields: Record<string, string | null>) => string | null>
> = {
    residence_card: {
        name: (user) => [user.last_name, user.first_name].filter(Boolean).join(' ') || null,
        last_name_kana: (user) => user.last_name_kana,
        first_name_kana: (user) => user.first_name_kana,
        birthday: (user) => user.info?.birthday ?? null,
        gender: (user) => user.info?.gender ?? null,
        nationality: (user) => user.info?.nationality ?? null,
        address: (user) => user.info?.address ?? null,
        postal_code: (user) => user.info?.postal_code ?? null,
        prefecture: (user) => user.info?.prefecture ?? null,
        residence_number: (user, fields) =>
            getResidenceCardForNumber(user.info, fields.residence_number)?.number ?? null,
        residence_expiry_date: (user, fields) =>
            getResidenceCardForNumber(user.info, fields.residence_number)?.date ?? null,
        residence_status: (user, fields) =>
            getResidenceCardForNumber(user.info, fields.residence_number)?.status ?? null,
    },
    passport: {
        passport_number: (user) => user.info?.passport_number ?? null,
        passport_expiry_date: (user) => user.info?.passport_expiry_date ?? null,
    },
    student_card: {
        student_card_expiry_date: (user) => user.info?.student_card_expiry_date ?? null,
    },
};

const toHalfWidthDigits = (value: string): string =>
    value.replace(/[０-９]/g, (digit) => String.fromCharCode(digit.charCodeAt(0) - 0xfee0));

const normalizeForCompare = (value: string | null, kind: OcrCompareKind): string | null => {
    if (value === null || value === undefined) return null;
    const trimmed = value.trim();
    if (trimmed === '') return null;
    // DB dates may arrive as plain dates or full timestamps; extracted dates are always YYYY-MM-DD
    if (kind === 'date' && /^\d{4}-\d{2}-\d{2}/.test(trimmed)) {
        return trimmed.slice(0, 10);
    }
    // Hyphens, 〒 and digit width are formatting, not a different postal code
    if (kind === 'postal_code') {
        const digits = toHalfWidthDigits(trimmed).replace(/\D/g, '');
        if (digits !== '') return digits;
    }
    return trimmed.replace(/\s+/g, ' ').toUpperCase();
};

export const compareValues = (
    extracted: string | null,
    current: string | null,
    kind: OcrCompareKind,
): OcrCompareStatus => {
    const extractedNorm = normalizeForCompare(extracted, kind);
    const currentNorm = normalizeForCompare(current, kind);

    if (extractedNorm === null && currentNorm === null) return 'no_data';
    if (extractedNorm === null) return 'not_read';
    if (extractedNorm === currentNorm) return 'match';
    return 'mismatch';
};

export const buildCompareRows = (
    group: PaperOcrGroup,
    fields: Record<string, string | null>,
    user: OcrCompareUser,
): OcrCompareRow[] => {
    const sources = GROUP_FIELD_SOURCES[group];
    if (!sources) return [];

    return Object.entries(sources).map(([field, getCurrent]) => {
        const extracted = fields[field] ?? null;
        const current = getCurrent(user, fields);

        return {
            field,
            extracted,
            current,
            status: compareValues(extracted, current, compareKindOf(field)),
        };
    });
};
