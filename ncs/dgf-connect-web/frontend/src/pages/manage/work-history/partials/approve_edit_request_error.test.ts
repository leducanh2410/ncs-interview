import { TFunction } from 'i18next';
import { describe, expect, it } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import {
    getApproveConflicts,
    getApproveErrorMessage,
    getBulkResolveErrorMessage,
    isRequestNoLongerApprovable,
} from '@/pages/manage/work-history/partials/approve_edit_request_error';

const t = ((key: string, params?: Record<string, string>) =>
    Object.entries(params ?? {}).reduce(
        (text, [name, value]) => text.replace(`{{${name}}}`, value),
        key,
    )) as unknown as TFunction;

const apiError = (message: string, data: Record<string, unknown> = {}) => new ApiError({ status: 409, message, data });

describe('approve_edit_request_error', () => {
    it('returns the conflicts of a data-changed error and nothing for other errors', () => {
        const conflicts = [{ field: 'check_out_time', old_value: 'a', current_value: 'b', new_value: 'c' }];

        expect(getApproveConflicts(apiError('WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED', { conflicts }))).toEqual(
            conflicts,
        );
        expect(getApproveConflicts(apiError('WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED'))).toBeNull();
        expect(getApproveConflicts(apiError('WORK_HISTORY_EDIT_REQUEST_NOT_PENDING', { conflicts }))).toBeNull();
        expect(getApproveConflicts(new Error('boom'))).toBeNull();
    });

    it('maps each approval error code to its own message', () => {
        expect(getApproveErrorMessage(t, apiError('WORK_HISTORY_EDIT_REQUEST_INVALID_TIME_ORDER'), 'fallback')).toBe(
            'error|Applying this request would put the check-out before the check-in. Please reject it and submit a new one',
        );
        expect(getApproveErrorMessage(t, apiError('WORK_HISTORY_EDIT_REQUEST_NOT_PENDING'), 'fallback')).toBe(
            'error|This request was already decided by someone else',
        );
        expect(getApproveErrorMessage(t, apiError('E_FORBIDDEN'), 'fallback')).toBe('fallback');
    });

    it('treats only data-changed and not-pending as no longer approvable', () => {
        expect(isRequestNoLongerApprovable(apiError('WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED'))).toBe(true);
        expect(isRequestNoLongerApprovable(apiError('WORK_HISTORY_EDIT_REQUEST_NOT_PENDING'))).toBe(true);
        expect(isRequestNoLongerApprovable(apiError('WORK_HISTORY_EDIT_REQUEST_INVALID_TIME_ORDER'))).toBe(false);
    });

    it('names the employee and date of the request that aborted a bulk action', () => {
        const error = apiError('WORK_HISTORY_EDIT_REQUEST_NOT_PENDING', { employee_name: 'Tanaka', date: '2026/5/10' });

        expect(getBulkResolveErrorMessage(t, error, 'fallback')).toBe(
            'error|Request for Tanaka on 2026/5/10: error|This request was already decided by someone else',
        );
        expect(getBulkResolveErrorMessage(t, apiError('E_FORBIDDEN'), 'fallback')).toBe('fallback');
    });
});
