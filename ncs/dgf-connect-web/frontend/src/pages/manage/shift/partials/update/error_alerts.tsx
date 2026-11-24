import React from 'react';
import { useTranslation } from 'react-i18next';
import { Box } from '@mantine/core';
import { ShiftCsvError } from '@/types/models/shift';
import DAlert from '@/components/DAlert';

type ErrorAlertsProps = {
    formNotMemberError?: React.ReactNode;
    formDisabledError?: React.ReactNode;
    formTotalErrors?: { key: string; error: React.ReactNode }[];
    csvImportErrorData: ShiftCsvError[];
};

const getShiftImportCsvErrorMessage = (errorCode: string, t: (key: string) => string) => {
    switch (errorCode) {
        case 'SHIFT_CSV_INVALID_WORKPLACE_POSITION':
            return t('validation|Invalid workplace position');
        case 'SHIFT_CSV_INVALID_STAFF_NO':
            return t('validation|Invalid staff number');
        case 'SCHEDULE_SHIFT_OVERLAP':
            return t('validation|Shift overlap');
        case 'SCHEDULE_MAX_SHIFTS_EXCEEDED':
            return t('validation|Max shifts exceeded');
        case 'SCHEDULE_MAX_WORK_HOURS_EXCEEDED':
            return t('validation|Max work hours exceeded');
        case 'SCHEDULE_MIN_REST_HOURS_VIOLATED':
            return t('validation|Not enough rest time between shifts');
        case 'SCHEDULE_EXPIRED_PASSPORT':
            return t('validation|Expired passport');
        case 'SCHEDULE_EXPIRED_STUDENT_CARD':
            return t('validation|Expired student card');
        case 'SCHEDULE_EXPIRED_RESIDENCE_CARD':
            return t('validation|Expired residence card');
        case 'SCHEDULE_USER_DISABLED':
            return t('validation|User disabled');
        case 'SCHEDULE_USER_PAPER_REJECTED':
            return t('validation|User paper rejected');
        case 'SCHEDULE_USER_MISSING_STAFF_NO':
            return t('validation|User missing staff no');
        default:
            return t('error|Unknown error');
    }
};

const ErrorAlerts = ({
    formNotMemberError,
    formDisabledError,
    formTotalErrors,
    csvImportErrorData,
}: ErrorAlertsProps) => {
    const { t } = useTranslation();

    return (
        <>
            {formNotMemberError && (
                <DAlert type="danger" mb="md">
                    {formNotMemberError}
                </DAlert>
            )}
            {formDisabledError && (
                <DAlert type="danger" mb="md">
                    {formDisabledError}
                </DAlert>
            )}
            {formTotalErrors && formTotalErrors.length > 0 && (
                <DAlert type="danger" mb="md">
                    {formTotalErrors.map((item) => (
                        <Box key={item.key}>{item.error}</Box>
                    ))}
                </DAlert>
            )}

            {csvImportErrorData.length > 0 && (
                <DAlert type="danger" mb="md">
                    {csvImportErrorData.map((errorItem) => (
                        <Box key={`${errorItem.staff_no}-${errorItem.error_code}`}>
                            <strong>
                                {errorItem.user_name
                                    ? `${errorItem.user_name} (${errorItem.staff_no})`
                                    : errorItem.staff_no}
                                :
                            </strong>
                            <span style={{ marginLeft: '4px' }}>
                                {getShiftImportCsvErrorMessage(errorItem.error_code, t)}
                            </span>
                        </Box>
                    ))}
                </DAlert>
            )}
        </>
    );
};

export default ErrorAlerts;
