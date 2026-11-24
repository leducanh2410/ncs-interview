import { describe, expect, it } from 'vitest';
import { OcrCompareUser, buildCompareRows, compareValues } from '@/pages/admin/user/partials/paper_ocr_compare';

const makeUser = (overrides: Partial<OcrCompareUser> = {}): OcrCompareUser => ({
    last_name: 'NGUYEN',
    first_name: 'VAN A',
    last_name_kana: 'グエン',
    first_name_kana: 'バン ア',
    info: {
        birthday: '1998-04-12',
        gender: 'male',
        nationality: 'vietnam',
        address: '東京都新宿区西新宿2-8-1',
        postal_code: '1600023',
        prefecture: '東京都',
        residence_number_1: 'AB12345678CD',
        residence_expiry_date_1: '2027-01-31',
        residence_status_1: 'study_abroad',
        passport_number: 'C1234567',
        passport_expiry_date: '2030-06-01',
        student_card_expiry_date: '2026-03-31',
    },
    ...overrides,
});

describe('compareValues', () => {
    it('returns no_data when both sides are empty', () => {
        expect(compareValues(null, null, 'text')).toBe('no_data');
        expect(compareValues('', '  ', 'text')).toBe('no_data');
    });

    it('returns not_read when only the extracted value is missing', () => {
        expect(compareValues(null, 'AB12345678CD', 'text')).toBe('not_read');
    });

    it('returns mismatch when the current value is missing but a value was extracted', () => {
        expect(compareValues('AB12345678CD', null, 'text')).toBe('mismatch');
    });

    it('ignores case and extra whitespace when comparing', () => {
        expect(compareValues('Nguyen   Van A', 'NGUYEN VAN A', 'text')).toBe('match');
    });

    it('compares dates ignoring the time part of DB timestamps', () => {
        expect(compareValues('2027-01-31', '2027-01-31T00:00:00.000000Z', 'date')).toBe('match');
        expect(compareValues('2027-01-31', '2028-01-31', 'date')).toBe('mismatch');
    });

    it('compares postal codes by their digits only', () => {
        expect(compareValues('3500235', '350-0235', 'postal_code')).toBe('match');
        expect(compareValues('3500235', '〒３５０−０２３５', 'postal_code')).toBe('match');
        expect(compareValues('3500235', ' 3500235 ', 'postal_code')).toBe('match');
        expect(compareValues('3500235', '350-0236', 'postal_code')).toBe('mismatch');
        expect(compareValues('3500235', 'unknown', 'postal_code')).toBe('mismatch');
    });
});

describe('buildCompareRows', () => {
    it('builds residence card rows against the first residence slot and the user name', () => {
        const rows = buildCompareRows(
            'residence_card',
            {
                name: 'NGUYEN VAN A',
                last_name_kana: 'グエン',
                first_name_kana: 'バン ア',
                birthday: '1998-04-12',
                gender: 'male',
                nationality: 'vietnam',
                address: '東京都新宿区西新宿2-8-1',
                postal_code: '1600023',
                prefecture: '東京都',
                residence_number: 'AB12345678CD',
                residence_expiry_date: '2027-01-31',
                residence_status: 'study_abroad',
                residence_status_raw: '留学',
            },
            makeUser(),
        );

        expect(rows).toHaveLength(12);
        expect(rows.every((row) => row.status === 'match')).toBe(true);
    });

    it('flags mismatching and unread fields', () => {
        const rows = buildCompareRows(
            'residence_card',
            {
                name: 'TRAN VAN B',
                last_name_kana: 'チャン',
                first_name_kana: null,
                birthday: null,
                gender: 'male',
                nationality: 'vietnam',
                address: null,
                residence_number: 'ZZ00000000ZZ',
                residence_expiry_date: '2027-01-31',
                residence_status: 'study_abroad',
            },
            makeUser(),
        );

        const byField = Object.fromEntries(rows.map((row) => [row.field, row.status]));
        expect(byField.name).toBe('mismatch');
        expect(byField.last_name_kana).toBe('mismatch');
        expect(byField.first_name_kana).toBe('not_read');
        expect(byField.birthday).toBe('not_read');
        expect(byField.residence_number).toBe('mismatch');
        expect(byField.residence_expiry_date).toBe('match');
        expect(byField.postal_code).toBe('not_read');
    });

    it('compares the derived postal code against the profile', () => {
        const rows = buildCompareRows('residence_card', { postal_code: '3500235' }, makeUser());

        expect(rows.find((row) => row.field === 'postal_code')?.status).toBe('mismatch');
    });

    it('treats a hyphenated stored postal code as the same code', () => {
        const user = makeUser({ info: { ...makeUser().info, postal_code: '160-0023' } });
        const rows = buildCompareRows('residence_card', { postal_code: '1600023' }, user);

        expect(rows.find((row) => row.field === 'postal_code')?.status).toBe('match');
    });

    it('compares residence fields against the latest valid card, not the first slot', () => {
        const user = makeUser({
            info: {
                // First slot holds an older card; the latest valid card lives in slot 2.
                residence_number_1: 'OLD11111111OLD',
                residence_expiry_date_1: '2024-01-31',
                residence_status_1: 'family_stay',
                residence_number_2: 'NEW22222222NEW',
                residence_expiry_date_2: '2028-12-31',
                residence_status_2: 'study_abroad',
            },
        });

        const rows = buildCompareRows(
            'residence_card',
            {
                name: 'NGUYEN VAN A',
                residence_number: 'NEW22222222NEW',
                residence_expiry_date: '2028-12-31',
                residence_status: 'study_abroad',
            },
            user,
        );

        const byField = Object.fromEntries(rows.map((row) => [row.field, row.status]));
        expect(byField.residence_number).toBe('match');
        expect(byField.residence_expiry_date).toBe('match');
        expect(byField.residence_status).toBe('match');
    });

    it('compares residence fields against the slot already holding the extracted number', () => {
        const user = makeUser({
            info: {
                residence_number_1: 'AA11111111AA',
                residence_expiry_date_1: '2027-01-31',
                residence_status_1: 'study_abroad',
                residence_number_2: 'BB22222222BB',
                residence_expiry_date_2: '2028-12-31',
                residence_status_2: 'family_stay',
            },
        });

        const rows = buildCompareRows(
            'residence_card',
            {
                residence_number: 'aa11111111aa',
                residence_expiry_date: '2027-01-31',
                residence_status: 'family_stay',
            },
            user,
        );

        const byField = Object.fromEntries(rows.map((row) => [row.field, row]));
        expect(byField.residence_number.status).toBe('match');
        expect(byField.residence_number.current).toBe('AA11111111AA');
        expect(byField.residence_expiry_date.status).toBe('match');
        expect(byField.residence_status.status).toBe('mismatch');
        expect(byField.residence_status.current).toBe('study_abroad');
    });

    it('compares against the latest copy when the extracted number sits in several slots', () => {
        const user = makeUser({
            info: {
                residence_number_1: 'AA11111111AA',
                residence_expiry_date_1: '2025-01-31',
                residence_status_1: 'study_abroad',
                residence_number_2: 'AA11111111AA',
                residence_expiry_date_2: '2028-01-31',
                residence_status_2: 'family_stay',
                residence_number_3: 'BB22222222BB',
                residence_expiry_date_3: '2026-01-31',
                residence_status_3: 'study_abroad',
            },
        });

        const rows = buildCompareRows('residence_card', { residence_number: 'AA11111111AA' }, user);

        const byField = Object.fromEntries(rows.map((row) => [row.field, row.current]));
        expect(byField.residence_expiry_date).toBe('2028-01-31');
        expect(byField.residence_status).toBe('family_stay');
    });

    it('handles a user without info', () => {
        const rows = buildCompareRows(
            'passport',
            { passport_number: 'C1234567', passport_expiry_date: null },
            makeUser({ info: null }),
        );

        const byField = Object.fromEntries(rows.map((row) => [row.field, row.status]));
        expect(byField.passport_number).toBe('mismatch');
        expect(byField.passport_expiry_date).toBe('no_data');
    });

    it('builds student card rows', () => {
        const rows = buildCompareRows('student_card', { student_card_expiry_date: '2026-03-31' }, makeUser());

        expect(rows).toHaveLength(1);
        expect(rows[0].status).toBe('match');
    });
});
