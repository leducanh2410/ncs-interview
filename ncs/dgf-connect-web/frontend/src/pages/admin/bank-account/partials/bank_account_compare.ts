import { BankAccount, BankAccountConfirmedFields, BankAccountOcrFields } from '@/types/models/bank_account';

export type BankAccountCompareRow = {
    field: keyof BankAccountConfirmedFields;
    ocr: string | null;
    input: string | null;
    mismatch: boolean;
};

const OCR_FIELD_FOR_INPUT: Record<keyof BankAccountConfirmedFields, keyof BankAccountOcrFields | null> = {
    bank_name: 'bank_name',
    bank_code: 'bank_code',
    branch_name: 'branch_name',
    branch_code: 'branch_code',
    account_number: 'account_number',
    account_holder_kana: 'holder_name_kana',
    account_holder_latin: 'holder_name_latin',
    is_yucho: 'is_yucho',
    yucho_symbol: 'yucho_symbol',
    yucho_number: 'yucho_number',
};

const BASE_FIELDS: (keyof BankAccountConfirmedFields)[] = [
    'bank_name',
    'bank_code',
    'branch_name',
    'branch_code',
    'account_number',
    'account_holder_kana',
    'account_holder_latin',
];

const YUCHO_FIELDS: (keyof BankAccountConfirmedFields)[] = ['yucho_symbol', 'yucho_number'];

const asText = (value: unknown): string | null => {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value === 'boolean') return value ? 'true' : 'false';
    return String(value);
};

export const buildBankAccountCompareRows = (
    account: BankAccount,
    unmaskedNumber: string | null,
): BankAccountCompareRow[] => {
    const confirmed = account.confirmed_fields;
    const ocr = account.ocr_fields;
    const mismatched = new Set(account.input_mismatches.map((item) => item.field));
    const fields = confirmed?.is_yucho || ocr?.is_yucho ? [...BASE_FIELDS, ...YUCHO_FIELDS] : BASE_FIELDS;

    return fields.map((field) => {
        const ocrField = OCR_FIELD_FOR_INPUT[field];
        let input = asText(confirmed?.[field]);
        if (field === 'account_number') {
            input = unmaskedNumber ?? account.account_number_masked;
        }
        return {
            field,
            ocr: ocrField ? asText(ocr?.[ocrField]) : null,
            input,
            mismatch: mismatched.has(field),
        };
    });
};

export const maskAccountNumber = (value: string | null): string | null => {
    if (!value) return null;
    return `${'*'.repeat(Math.max(value.length - 3, 0))}${value.slice(-3)}`;
};
