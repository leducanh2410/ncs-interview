import { TFunction } from 'i18next';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';

/**
 * shift.check_in, shift.salary and shift.allow_overtime are granted per workplace while
 * usePermissions() only knows the flat permission list, so an admin holding shift.list at
 * one workplace still sees enabled controls for rows the API refuses with 403. Only 422
 * reaches FormHelper.setFormError(), so without this the modal would sit there showing
 * neither an error nor a success.
 */
const getShiftActionErrorMessage = (t: TFunction, error: unknown, fallback: string): string => {
    if (error instanceof ApiValidationError || !(error instanceof ApiError)) {
        return fallback;
    }

    if (
        error.message === ErrorCodeEnum.CHECK_IN_WORKPLACE_OUT_OF_SCOPE.value ||
        error.message === ErrorCodeEnum.E_FORBIDDEN.value
    ) {
        return t('error|You do not have permission to manage this workplace');
    }

    if (error.message === ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_PENDING_CHECK_OUT_CHANGE.value) {
        return t(
            'error|This check-in has a pending edit request that changes its check-out time. Approve or reject it first',
        );
    }

    return fallback;
};

export default getShiftActionErrorMessage;
