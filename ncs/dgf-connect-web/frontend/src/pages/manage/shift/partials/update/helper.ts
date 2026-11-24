import { UseFormReturnType } from '@mantine/form';
import i18n from '@/locales/i18n';
import { User } from '@/types/models/user';

export const setFormErrors = (
    key: string,
    value: string,
    allShiftUsers: Record<string, User>,
    form: UseFormReturnType<any>,
): { isFixed: boolean; positionId: string; ids: string[]; message: string; perUserMessage: string } | null => {
    const ids = value.split(',');
    const nameStr = ids
        .map((userMaxHour) => {
            if (key.includes('max_hour')) {
                const arr = userMaxHour.split(':');
                return `${allShiftUsers[arr[0]]?.name}:${arr[1]}h`;
            }
            return allShiftUsers[userMaxHour]?.name;
        })
        .filter((v) => v)
        .join(', ');

    if (!nameStr) {
        form.setFieldError(key, i18n.t(`validation|${value}`));
        return null;
    }

    const ERROR_MAP: Record<string, string> = {
        expired_residence: i18n.t('validation|Residence card has expired or is about to expire: {{names}}', {
            names: nameStr,
        }),
        expired_student: i18n.t('validation|Student card has expired or is about to expire: {{names}}', {
            names: nameStr,
        }),
        expired_passport: i18n.t('validation|Passport has expired or is about to expire: {{names}}', {
            names: nameStr,
        }),
        max_hour: i18n.t('validation|User has reached maximum hours: {{names}}', {
            names: nameStr,
        }),
        max_shift: i18n.t('validation|User has reached maximum shifts: {{names}}', {
            names: nameStr,
        }),
        overlap: i18n.t('validation|User has overlapping shifts: {{names}}', {
            names: nameStr,
        }),
        min_rest_hours: i18n.t('validation|User does not have enough rest time between shifts: {{names}}', {
            names: nameStr,
        }),
        is_disabled: i18n.t('validation|User disabled: {{names}}', {
            names: nameStr,
        }),
        rejected_paper: i18n.t('validation|User paper rejected: {{names}}', {
            names: nameStr,
        }),
        missing_staff_no: i18n.t('validation|User missing staff no: {{names}}', {
            names: nameStr,
        }),
    };

    const BASE_ERROR_MAP: Record<string, string> = {
        expired_residence: i18n.t('validation|Residence card has expired or is about to expire'),
        expired_student: i18n.t('validation|Student card has expired or is about to expire'),
        expired_passport: i18n.t('validation|Passport has expired or is about to expire'),
        max_hour: i18n.t('validation|User has reached maximum hours'),
        max_shift: i18n.t('validation|User has reached maximum shifts'),
        overlap: i18n.t('validation|User has overlapping shifts'),
        min_rest_hours: i18n.t('validation|User does not have enough rest time between shifts'),
        is_disabled: i18n.t('validation|User disabled'),
        rejected_paper: i18n.t('validation|User paper rejected'),
        missing_staff_no: i18n.t('validation|User missing staff no'),
    };

    if (key === 'not_member_ids') {
        form.setFieldError(
            key,
            i18n.t('validation|User is not a member of the workplace: {{names}}', {
                names: nameStr,
            }),
        );
        return null;
    }

    // Disabled users sent as pending have no position, so this is reported form-level rather
    // than against a position column like the approved is_disabled errors.
    if (key === 'disabled_ids') {
        form.setFieldError(
            key,
            i18n.t('validation|User disabled: {{names}}', {
                names: nameStr,
            }),
        );
        return null;
    }

    if (key === 'fixed_total' || key === 'temp_total') {
        form.setFieldError(key, i18n.t('validation|Number of approved users exceeds the required total'));
        return null;
    }

    const parts = key.split('_');
    const isFixed = parts[0] === 'fixed';
    const positionId = parts[parts.length - 1];

    for (const errorKey in ERROR_MAP) {
        if (key.includes(errorKey)) {
            const message = ERROR_MAP[errorKey];
            const perUserMessage = BASE_ERROR_MAP[errorKey];
            form.setFieldError(key, message);
            return {
                isFixed,
                positionId,
                ids,
                message,
                perUserMessage,
            };
        }
    }

    return null;
};
