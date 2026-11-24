import { TFunction } from 'i18next';
import { WorkHistoryEditRequestConflict } from '@/types/models/work_history_edit_request';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';

export const getApproveConflicts = (error: unknown): WorkHistoryEditRequestConflict[] | null => {
    if (!(error instanceof ApiError) || error.message !== ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED.value) {
        return null;
    }
    const conflicts = error.data?.conflicts;

    return Array.isArray(conflicts) && conflicts.length > 0 ? conflicts : null;
};

export const isRequestNoLongerApprovable = (error: unknown): boolean =>
    error instanceof ApiError &&
    (error.message === ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED.value ||
        error.message === ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_NOT_PENDING.value);

export const getApproveErrorMessage = (t: TFunction, error: unknown, fallback: string): string => {
    if (!(error instanceof ApiError)) {
        return fallback;
    }

    switch (error.message) {
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED.value:
            return t('error|The check-in data has changed since this request was submitted');
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_INVALID_TIME_ORDER.value:
            return t(
                'error|Applying this request would put the check-out before the check-in. Please reject it and submit a new one',
            );
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_NOT_PENDING.value:
            return t('error|This request was already decided by someone else');
        default:
            return fallback;
    }
};

export const getBulkResolveErrorMessage = (t: TFunction, error: unknown, fallback: string): string => {
    const reason = getApproveErrorMessage(t, error, fallback);
    const employee = error instanceof ApiError ? error.data?.employee_name : undefined;
    const date = error instanceof ApiError ? error.data?.date : undefined;

    return employee && date
        ? t('error|Request for {{employee}} on {{date}}: {{reason}}', { employee, date, reason })
        : reason;
};
