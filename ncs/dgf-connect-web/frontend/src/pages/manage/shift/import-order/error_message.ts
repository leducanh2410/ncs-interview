import { TFunction } from 'i18next';
import { ShiftOrderImportError } from '@/types/models/import_batch';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';

export const toShiftOrderImportErrors = (error: unknown): ShiftOrderImportError[] => {
    if (!(error instanceof ApiError)) {
        return [{ code: 'network_error' }];
    }

    const errors = error.data?.errors;
    if (Array.isArray(errors) && errors.length > 0) {
        return errors as ShiftOrderImportError[];
    }

    if (error.status === 401) {
        return [{ code: 'session_expired' }];
    }

    if (error.status === 403) {
        return [{ code: 'no_permission' }];
    }

    if (error instanceof ApiValidationError) {
        return [{ code: 'file_rejected' }];
    }

    if (error.message === 'SHIFT_ORDER_FILE_INVALID_FORMAT') {
        return [{ code: 'file_unreadable' }];
    }

    if (error.status >= 500) {
        return [{ code: 'server_error' }];
    }

    return [{ code: 'unknown' }];
};

export const getShiftOrderImportErrorMessage = (error: ShiftOrderImportError, t: TFunction): string => {
    const cell = error.cell ?? '';
    const value = error.value ?? '';
    const workplace = error.workplace_name ?? '';

    switch (error.code) {
        case 'workplace_not_found':
            return t('Cell {{cell}}: workplace ID {{value}} does not exist in the system', { cell, value });
        case 'workplace_forbidden':
            return t('You do not have permission to import shift orders for the workplace {{workplace}}', {
                workplace,
            });
        case 'workplace_shift_not_found':
            return cell
                ? t('Cell {{cell}}: the workplace {{workplace}} has no shift {{value}}. Create that shift first', {
                      cell,
                      workplace,
                      value,
                  })
                : t('The workplace {{workplace}} has no shift {{value}}. Create that shift first', {
                      workplace,
                      value,
                  });
        case 'position_not_found':
            return cell
                ? t('Cell {{cell}}: the workplace {{workplace}} has no position named {{value}}', {
                      cell,
                      workplace,
                      value,
                  })
                : t('The workplace {{workplace}} has no position named {{value}}', { workplace, value });
        case 'duplicate_position':
            return t('Cell {{cell}}: the position {{value}} appears more than once in this shift', { cell, value });
        case 'total_mismatch':
            return t(
                'Cell {{cell}}: the shift total {{shiftTotal}} does not match the sum of its positions {{positionsTotal}}',
                {
                    cell,
                    shiftTotal: error.shift_total ?? 0,
                    positionsTotal: error.positions_total ?? 0,
                },
            );
        case 'past_date':
            return cell
                ? t('Cell {{cell}}: {{date}} is already in the past', { cell, date: error.date ?? '' })
                : t('{{date}} is already in the past', { date: error.date ?? '' });
        case 'day_not_in_month':
            return t('Cell {{cell}}: day {{value}} does not exist in the selected month', { cell, value });
        case 'negative_total':
            return t('Cell {{cell}}: the number of people must not be negative', { cell });
        case 'invalid_number':
            return t('Cell {{cell}}: {{value}} is not a number', { cell, value });
        case 'invalid_shift_time':
            return t('Cell {{cell}}: {{value}} is not a valid shift time', { cell, value });
        case 'positions_without_shift':
            return t('Cell {{cell}}: these positions are not followed by a shift row', { cell });
        case 'duplicate_shift':
            return t('The shift {{value}} appears more than once in the workplace {{workplace}}', {
                value,
                workplace,
            });
        case 'no_workplace_block':
            return t('No workplace block was found in the file');
        case 'month_not_detected':
            return t('The month could not be read from the file. Please choose it manually');
        case 'no_shift_order':
            return t('The file has no shift order to import');
        case 'session_expired':
            return t('Your session has expired. Please sign in again and upload the file once more');
        case 'no_permission':
            return t('You do not have permission to import shift orders');
        case 'file_rejected':
            return t('Only an .xlsx file of at most 10MB can be uploaded');
        case 'file_unreadable':
            return t('The file could not be read. Make sure it is a valid .xlsx workbook');
        case 'network_error':
            return t('Could not reach the server. Check your connection and try again');
        case 'server_error':
            return t('Something went wrong on the server. Please try again');
        case 'decision_required':
            return t('Choose a value for {{workplace}} - {{shift}} on {{date}}', {
                workplace,
                shift: error.shift_label ?? '',
                date: error.date ?? '',
            });
        default:
            return t('Invalid data');
    }
};
