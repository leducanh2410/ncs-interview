import { describe, expect, it } from 'vitest';
import { BankAccount } from '@/types/models/bank_account';
import { buildBankAccountCompareRows, maskAccountNumber } from './bank_account_compare';

const baseAccount = (overrides: Partial<BankAccount> = {}): BankAccount =>
    ({
        id: 1,
        user_id: 2,
        payout_method: 'bank_transfer',
        status: 'wait_for_approval',
        bank_name: '三菱UFJ銀行',
        bank_code: '0005',
        branch_name: '新宿支店',
        branch_code: '021',
        account_type: 'ordinary',
        account_number_masked: '****567',
        account_number: null,
        account_holder_kana: 'グエン バン アン',
        account_holder_latin: null,
        is_yucho: false,
        yucho_symbol: null,
        yucho_number: null,
        ocr_result_id: 9,
        ocr_run_count: 1,
        ocr_max_runs: 3,
        can_reupload: true,
        ocr_fields: {
            bank_name: '三菱UFJ銀行',
            bank_code: '0005',
            branch_name: '新宿支店',
            branch_code: '021',
            account_type: 'ordinary',
            account_number: '1234567',
            holder_name_kana: 'グエン バン アン',
            holder_name_latin: null,
            is_yucho: false,
            yucho_symbol: null,
            yucho_number: null,
        },
        ocr_warnings: [],
        ocr_error: null,
        confirmed_fields: {
            bank_name: '三菱UFJ銀行',
            bank_code: '0005',
            branch_name: '新宿支店',
            branch_code: '021',
            account_number: '7654321',
            account_holder_kana: 'タナカ タロウ',
            account_holder_latin: null,
            is_yucho: false,
            yucho_symbol: null,
            yucho_number: null,
        },
        entered_manually: false,
        name_check: null,
        input_mismatches: [
            { field: 'account_number', ocr_value: '1234567', input_value: '7654321' },
            { field: 'account_holder_kana', ocr_value: 'グエン バン アン', input_value: 'タナカ タロウ' },
        ],
        approver_id: null,
        submitted_at: null,
        approved_at: null,
        rejected_note: null,
        superseded_at: null,
        images: [],
        images_restricted: false,
        created_at: null,
        updated_at: null,
        ...overrides,
    }) as BankAccount;

describe('buildBankAccountCompareRows', () => {
    it('pairs OCR and entered values and flags the mismatched fields', () => {
        const rows = buildBankAccountCompareRows(baseAccount(), '7654321');

        const byField = Object.fromEntries(rows.map((row) => [row.field, row]));
        expect(byField.account_number).toEqual({
            field: 'account_number',
            ocr: '1234567',
            input: '7654321',
            mismatch: true,
        });
        expect(byField.account_holder_kana.mismatch).toBe(true);
        expect(byField.bank_name).toEqual({
            field: 'bank_name',
            ocr: '三菱UFJ銀行',
            input: '三菱UFJ銀行',
            mismatch: false,
        });
        expect(rows.map((row) => row.field)).not.toContain('yucho_symbol');
    });

    it('falls back to the masked number when the full number is hidden', () => {
        const rows = buildBankAccountCompareRows(baseAccount(), null);

        expect(rows.find((row) => row.field === 'account_number')?.input).toBe('****567');
    });

    it('adds the yucho fields when either side says it is a yucho account', () => {
        const rows = buildBankAccountCompareRows(
            baseAccount({ ocr_fields: { ...baseAccount().ocr_fields!, is_yucho: true, yucho_symbol: '10230' } }),
            null,
        );

        expect(rows.map((row) => row.field)).toEqual(expect.arrayContaining(['yucho_symbol', 'yucho_number']));
        expect(rows.find((row) => row.field === 'yucho_symbol')?.ocr).toBe('10230');
    });
});

describe('maskAccountNumber', () => {
    it('keeps the last three digits', () => {
        expect(maskAccountNumber('1234567')).toBe('****567');
        expect(maskAccountNumber(null)).toBeNull();
    });
});
