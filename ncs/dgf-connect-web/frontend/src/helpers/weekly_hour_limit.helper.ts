import { TFunction } from 'i18next';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';

export type WeeklyHourViolation = {
    user_id: number;
    staff_no: string | null;
    name: string;
    over_hours: number;
    date?: string;
};

const formatOverHours = (hours: number, t: TFunction) => `${Number(hours.toFixed(2))}${t('h')}`;

const describeViolation = (violation: WeeklyHourViolation, t: TFunction) => {
    const person = violation.staff_no ? `${violation.name} (${violation.staff_no})` : violation.name;
    const over = t('Over by {{hours}}', { hours: formatOverHours(violation.over_hours, t) });

    return violation.date ? `${person} ${DateHelper.parseAndFormat(violation.date)}: ${over}` : `${person}: ${over}`;
};

const WeeklyHourLimitHelper = {
    isViolationError: (error: unknown): error is ApiError =>
        error instanceof ApiError && error.message === ErrorCodeEnum.SCHEDULE_MAX_WORK_HOURS_EXCEEDED.value,

    getLengthenedShiftMessage: (error: unknown, t: TFunction): string | null => {
        if (!WeeklyHourLimitHelper.isViolationError(error) || !Array.isArray(error.data)) {
            return null;
        }

        return t('This change would put these employees over their weekly work hour limit: {{employees}}', {
            employees: (error.data as WeeklyHourViolation[])
                .map((violation) => describeViolation(violation, t))
                .join(', '),
        });
    },

    getAbsentUndoMessage: (error: unknown, t: TFunction): string | null => {
        if (!WeeklyHourLimitHelper.isViolationError(error) || typeof error.data?.over_hours !== 'number') {
            return null;
        }

        return t(
            'This shift cannot be counted again because it would put the employee over their weekly work hour limit by {{hours}}.',
            { hours: formatOverHours(error.data.over_hours, t) },
        );
    },
};

export default WeeklyHourLimitHelper;
