import { TFunction } from 'i18next';
import { describe, expect, it } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import WeeklyHourLimitHelper from '@/helpers/weekly_hour_limit.helper';

DateHelper.initDayjs();

const t = ((key: string, opts?: Record<string, unknown>) =>
    Object.entries(opts ?? {}).reduce(
        (text, [name, value]) => text.replace(`{{${name}}}`, String(value)),
        key,
    )) as TFunction;

const violationError = (data: unknown) =>
    new ApiError({ status: 422, message: 'SCHEDULE_MAX_WORK_HOURS_EXCEEDED', data: data as Record<string, any> });

describe('WeeklyHourLimitHelper', () => {
    it('lists every employee pushed over the limit by a lengthened shift', () => {
        const message = WeeklyHourLimitHelper.getLengthenedShiftMessage(
            violationError([
                { user_id: 1, staff_no: '5001', name: 'Tanaka', over_hours: 1.5, date: '2026-09-09' },
                { user_id: 2, staff_no: null, name: 'Sato', over_hours: 2 },
            ]),
            t,
        );

        expect(message).toContain('Tanaka (5001)');
        expect(message).toContain('Over by 1.5h');
        expect(message).toContain('Sato: Over by 2h');
    });

    it('reports the over amount when an absent shift cannot be counted again', () => {
        expect(WeeklyHourLimitHelper.getAbsentUndoMessage(violationError({ over_hours: 3.25 }), t)).toBe(
            'This shift cannot be counted again because it would put the employee over their weekly work hour limit by 3.25h.',
        );
    });

    it('ignores other errors', () => {
        const otherError = new ApiError({ status: 400, message: 'SHIFT_USER_INVALID_STATUS', data: {} });

        expect(WeeklyHourLimitHelper.getLengthenedShiftMessage(otherError, t)).toBeNull();
        expect(WeeklyHourLimitHelper.getAbsentUndoMessage(otherError, t)).toBeNull();
        expect(WeeklyHourLimitHelper.getLengthenedShiftMessage(violationError({ over_hours: 1 }), t)).toBeNull();
        expect(WeeklyHourLimitHelper.getAbsentUndoMessage(violationError([]), t)).toBeNull();
    });
});
