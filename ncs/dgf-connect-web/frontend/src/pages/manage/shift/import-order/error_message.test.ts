import { describe, expect, it } from 'vitest';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';
import {
    getShiftOrderImportErrorMessage,
    toShiftOrderImportErrors,
} from '@/pages/manage/shift/import-order/error_message';

const t = ((key: string, options?: Record<string, unknown>) =>
    options
        ? Object.entries(options).reduce(
              (result, [placeholder, value]) => result.replace(`{{${placeholder}}}`, String(value)),
              key,
          )
        : key) as never;

describe('toShiftOrderImportErrors', () => {
    it('keeps the detailed errors sent by the server', () => {
        const error = new ApiError({
            status: 422,
            message: 'SHIFT_ORDER_FILE_HAS_ERRORS',
            data: { errors: [{ code: 'workplace_not_found', cell: 'C3' }] },
        });

        expect(toShiftOrderImportErrors(error)).toEqual([{ code: 'workplace_not_found', cell: 'C3' }]);
    });

    it('reports an expired session instead of a generic message', () => {
        const error = new ApiError({ status: 401, message: 'E_UNAUTHORIZED', data: null as never });

        expect(toShiftOrderImportErrors(error)).toEqual([{ code: 'session_expired' }]);
    });

    it('reports a missing permission', () => {
        const error = new ApiError({ status: 403, message: 'E_FORBIDDEN', data: null as never });

        expect(toShiftOrderImportErrors(error)).toEqual([{ code: 'no_permission' }]);
    });

    it('reports a rejected upload when the request fails validation', () => {
        const error = new ApiValidationError({
            status: 422,
            message: 'E_UNPROCESSABLE_ENTITY',
            data: { file: ['The file field must be a file of type: xlsx.'] },
        });

        expect(toShiftOrderImportErrors(error)).toEqual([{ code: 'file_rejected' }]);
    });

    it('reports an unreadable workbook', () => {
        const error = new ApiError({
            status: 422,
            message: 'SHIFT_ORDER_FILE_INVALID_FORMAT',
            data: null as never,
        });

        expect(toShiftOrderImportErrors(error)).toEqual([{ code: 'file_unreadable' }]);
    });

    it('reports a server failure', () => {
        const error = new ApiError({ status: 500, message: 'E_INTERNAL_SERVER_ERROR', data: null as never });

        expect(toShiftOrderImportErrors(error)).toEqual([{ code: 'server_error' }]);
    });

    it('reports a network failure when the request never reached the server', () => {
        expect(toShiftOrderImportErrors(new TypeError('Failed to fetch'))).toEqual([{ code: 'network_error' }]);
    });

    it('falls back to unknown for an unexpected error response', () => {
        const error = new ApiError({ status: 409, message: 'SOMETHING_ELSE', data: null as never });

        expect(toShiftOrderImportErrors(error)).toEqual([{ code: 'unknown' }]);
    });
});

describe('getShiftOrderImportErrorMessage', () => {
    it('keeps the cell prefix for an error raised while reading the file', () => {
        expect(
            getShiftOrderImportErrorMessage(
                { code: 'position_not_found', cell: 'D5', workplace_name: 'Musashino', value: 'Position A' },
                t,
            ),
        ).toBe('Cell D5: the workplace Musashino has no position named Position A');
    });

    it('drops the cell prefix for an error raised while confirming', () => {
        expect(
            getShiftOrderImportErrorMessage(
                { code: 'position_not_found', workplace_name: 'Musashino', value: 'Position A' },
                t,
            ),
        ).toBe('The workplace Musashino has no position named Position A');

        expect(
            getShiftOrderImportErrorMessage(
                { code: 'workplace_shift_not_found', workplace_name: 'Musashino', value: '22:00〜30:00' },
                t,
            ),
        ).toBe('The workplace Musashino has no shift 22:00〜30:00. Create that shift first');

        expect(getShiftOrderImportErrorMessage({ code: 'past_date', date: '2026-10-01' }, t)).toBe(
            '2026-10-01 is already in the past',
        );
    });

    it('explains a position listed twice in the same shift', () => {
        expect(
            getShiftOrderImportErrorMessage({ code: 'duplicate_position', cell: 'D6', value: 'Position A' }, t),
        ).toBe('Cell D6: the position Position A appears more than once in this shift');
    });
});
