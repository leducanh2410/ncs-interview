import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { Shift, ShiftWithCount } from '@/types/models/shift';
import { ShiftUserWithUserInfo } from '@/types/models/shift_user';
import { User } from '@/types/models/user';
import { WorkplacePosition } from '@/types/models/workplace_position';
import { WorkplaceShift } from '@/types/models/workplace_shift';
import { TimelineItem } from '@/types/shift';
import { ShiftStatusEnum } from '@/enums/shift_status.enum';
import { ShiftUserStatusEnum } from '@/enums/shift_user_status.enum';
import DateHelper from '@/helpers/date.helper';
import ShiftHelper from '@/helpers/shift.helper';

const workplaceShift = (id: number, start: string, end: string): WorkplaceShift =>
    ({ id, start_time: start, end_time: end }) as WorkplaceShift;

const timelineItem = (ws: WorkplaceShift, shift: ShiftWithCount | null = null): TimelineItem => ({
    workplaceShift: ws,
    shift,
});

const shiftWithCount = (ws: WorkplaceShift, over: Partial<ShiftWithCount> = {}): ShiftWithCount =>
    ({ id: ws.id * 100, workplace_shift: ws, date: '2026-07-10', ...over }) as ShiftWithCount;

const user = (id: number, name = `user-${id}`): User => ({ id, name }) as User;

const position = (id: number): WorkplacePosition => ({ id, name: `position-${id}` }) as WorkplacePosition;

const shiftUser = (over: Partial<ShiftUserWithUserInfo>): ShiftUserWithUserInfo =>
    ({
        id: 1,
        is_fixed: false,
        status: ShiftUserStatusEnum.APPROVED.value,
        workplace_position_id: 1,
        user: user(1),
        ...over,
    }) as ShiftUserWithUserInfo;

beforeAll(() => {
    DateHelper.initDayjs();
});

describe('ShiftHelper.getHourRangeFromShift', () => {
    it('rounds the range outward by default', () => {
        expect(ShiftHelper.getHourRangeFromShift(workplaceShift(1, '09:30', '17:45'))).toEqual([9, 18]);
    });

    it('keeps fractional hours when isFloat is set', () => {
        expect(ShiftHelper.getHourRangeFromShift(workplaceShift(1, '09:30', '17:45'), true)).toEqual([9.5, 17.75]);
    });

    it('rolls an overnight shift past 24h', () => {
        expect(ShiftHelper.getHourRangeFromShift(workplaceShift(1, '22:00', '06:00'), true)).toEqual([22, 30]);
    });

    it('treats an end time equal to the start time as a full day', () => {
        expect(ShiftHelper.getHourRangeFromShift(workplaceShift(1, '08:00', '08:00'), true)).toEqual([8, 32]);
    });
});

describe('ShiftHelper.isOverlapping', () => {
    it('reports overlap when the shifts share a start time', () => {
        expect(
            ShiftHelper.isOverlapping(workplaceShift(1, '09:00', '12:00'), workplaceShift(2, '09:00', '18:00')),
        ).toBe(true);
    });

    it('reports overlap when the shifts share an end time', () => {
        expect(
            ShiftHelper.isOverlapping(workplaceShift(1, '09:00', '18:00'), workplaceShift(2, '13:00', '18:00')),
        ).toBe(true);
    });

    it('reports overlap when the later shift starts inside the earlier one', () => {
        expect(
            ShiftHelper.isOverlapping(workplaceShift(1, '09:00', '14:00'), workplaceShift(2, '13:00', '18:00')),
        ).toBe(true);
    });

    it('reports overlap when the earlier shift is passed as the second argument', () => {
        expect(
            ShiftHelper.isOverlapping(workplaceShift(1, '13:00', '18:00'), workplaceShift(2, '09:00', '14:00')),
        ).toBe(true);
    });

    it('does not report overlap for back-to-back shifts', () => {
        expect(
            ShiftHelper.isOverlapping(workplaceShift(1, '09:00', '13:00'), workplaceShift(2, '13:00', '18:00')),
        ).toBe(false);
    });

    it('does not report overlap for disjoint shifts', () => {
        expect(
            ShiftHelper.isOverlapping(workplaceShift(1, '09:00', '11:00'), workplaceShift(2, '13:00', '18:00')),
        ).toBe(false);
    });
});

describe('ShiftHelper.splitToRows', () => {
    it('returns no rows for empty input', () => {
        expect(ShiftHelper.splitToRows([])).toEqual([]);
    });

    it('packs non-overlapping shifts into a single row', () => {
        const a = timelineItem(workplaceShift(1, '09:00', '12:00'));
        const b = timelineItem(workplaceShift(2, '12:00', '15:00'));

        expect(ShiftHelper.splitToRows([a, b])).toEqual([[a, b]]);
    });

    it('pushes an overlapping shift onto a new row', () => {
        const a = timelineItem(workplaceShift(1, '09:00', '14:00'));
        const b = timelineItem(workplaceShift(2, '13:00', '18:00'));

        expect(ShiftHelper.splitToRows([a, b])).toEqual([[a], [b]]);
    });

    it('reuses the first row that has room instead of always appending', () => {
        const a = timelineItem(workplaceShift(1, '09:00', '12:00'));
        const b = timelineItem(workplaceShift(2, '10:00', '14:00'));
        const c = timelineItem(workplaceShift(3, '12:00', '15:00'));

        // c overlaps b but not a, so it joins a's row.
        expect(ShiftHelper.splitToRows([a, b, c])).toEqual([[a, c], [b]]);
    });
});

describe('ShiftHelper.calculateOffsetAndWidth', () => {
    const hours = ['09:00_9', '10:00_10', '11:00_11', '12:00_12', '13:00_13'];

    it('places a shift proportionally when it clears both hour gridlines', () => {
        const item = timelineItem(workplaceShift(1, '10:30', '11:30'));

        // 1.5h past 09:00 and ending 1.5h before the 13:00 gridline: no clamping needed.
        expect(ShiftHelper.calculateOffsetAndWidth(item, hours, 100)).toEqual({ offset: 150, width: 100 });
    });

    it('insets a shift that starts exactly on an hour gridline', () => {
        const item = timelineItem(workplaceShift(1, '10:00', '10:30'));

        expect(ShiftHelper.calculateOffsetAndWidth(item, hours, 100)).toEqual({ offset: 104, width: 50 });
    });

    it('insets a shift that starts just past an hour gridline', () => {
        const item = timelineItem(workplaceShift(1, '10:01', '10:30'));

        // Raw offset 102 is within 4px of the 10:00 gridline, so it is pushed out to 104.
        expect(ShiftHelper.calculateOffsetAndWidth(item, hours, 100)).toEqual({ offset: 104, width: 48 });
    });

    it('shrinks the width so a shift never touches the gridline after its end', () => {
        const item = timelineItem(workplaceShift(1, '10:00', '12:00'));

        // Ends exactly on the 12:00 gridline (x=300): width shrinks to keep a 4px gap.
        expect(ShiftHelper.calculateOffsetAndWidth(item, hours, 100)).toEqual({ offset: 104, width: 192 });
    });

    it('extends an overnight shift across midnight', () => {
        const item = timelineItem(workplaceShift(1, '12:00', '02:00'));

        expect(ShiftHelper.calculateOffsetAndWidth(item, hours, 100)).toEqual({ offset: 304, width: 1392 });
    });
});

describe('ShiftHelper.getTimeLineData', () => {
    it('spans hours from the earliest start to the latest end across workplaces', () => {
        const result = ShiftHelper.getTimeLineData({
            1: { workplace_shifts: [workplaceShift(1, '09:00', '12:00')], shifts: [] },
            2: { workplace_shifts: [workplaceShift(2, '07:30', '15:00')], shifts: [] },
        });

        expect(result.hours).toEqual([
            '07:00_7',
            '08:00_8',
            '09:00_9',
            '10:00_10',
            '11:00_11',
            '12:00_12',
            '13:00_13',
            '14:00_14',
            '15:00_15',
        ]);
    });

    it('attaches a shift to the workplace shift it belongs to', () => {
        const ws = workplaceShift(1, '09:00', '12:00');
        const shift = shiftWithCount(ws);

        const result = ShiftHelper.getTimeLineData({
            1: { workplace_shifts: [ws], shifts: [shift] },
        });

        expect(result.data[1]).toHaveLength(1);
        expect(result.data[1][0].shift).toBe(shift);
        expect(result.data[1][0].workplaceShift).toBe(ws);
    });

    it('keeps a shift whose workplace shift is no longer listed', () => {
        const listed = workplaceShift(1, '09:00', '12:00');
        const unlisted = workplaceShift(2, '13:00', '18:00');
        const shift = shiftWithCount(unlisted);

        const result = ShiftHelper.getTimeLineData({
            1: { workplace_shifts: [listed], shifts: [shift] },
        });

        expect(result.data[1]).toHaveLength(2);
        expect(result.data[1].map((item) => item.workplaceShift.id)).toEqual([1, 2]);
        expect(result.data[1][1].shift).toBe(shift);
    });

    it('widens the hour range to cover a shift outside the workplace shift hours', () => {
        const listed = workplaceShift(1, '09:00', '12:00');
        const shift = shiftWithCount(workplaceShift(2, '06:00', '20:00'));

        const result = ShiftHelper.getTimeLineData({
            1: { workplace_shifts: [listed], shifts: [shift] },
        });

        expect(result.hours[0]).toBe('06:00_6');
        expect(result.hours[result.hours.length - 1]).toBe('20:00_20');
    });

    it('sorts timeline items by their start-end key', () => {
        const late = workplaceShift(1, '13:00', '18:00');
        const early = workplaceShift(2, '09:00', '12:00');

        const result = ShiftHelper.getTimeLineData({
            1: { workplace_shifts: [late, early], shifts: [] },
        });

        expect(result.data[1].map((item) => item.workplaceShift.id)).toEqual([2, 1]);
    });
});

describe('ShiftHelper.categorizeUsers', () => {
    const positions = [position(1), position(2)];

    it('groups approved users under their position and counts them', () => {
        const result = ShiftHelper.categorizeUsers(
            [
                shiftUser({ id: 1, user: user(10), workplace_position_id: 1 }),
                shiftUser({ id: 2, user: user(11), workplace_position_id: 2 }),
            ],
            [],
            positions,
            false,
        );

        expect(result.groupUsers).toEqual({ 'pending': [], 'rejected': [], '1': ['10'], '2': ['11'] });
        expect(result.userGroups).toEqual({ '10': '1', '11': '2' });
        expect(result.count).toBe(2);
        expect(Object.keys(result.users)).toEqual(['10', '11']);
    });

    it('groups pending and rejected users regardless of position', () => {
        const result = ShiftHelper.categorizeUsers(
            [
                shiftUser({ id: 1, user: user(10), status: ShiftUserStatusEnum.PENDING.value }),
                shiftUser({ id: 2, user: user(11), status: ShiftUserStatusEnum.REJECTED.value }),
            ],
            [],
            positions,
            false,
        );

        expect(result.groupUsers.pending).toEqual(['10']);
        expect(result.groupUsers.rejected).toEqual(['11']);
        expect(result.count).toBe(0);
    });

    it('skips fixed shift users when collecting temp users', () => {
        const result = ShiftHelper.categorizeUsers(
            [shiftUser({ user: user(10), is_fixed: true })],
            [],
            positions,
            false,
        );

        expect(result.users).toEqual({});
        expect(result.count).toBe(0);
    });

    it('skips temp shift users when collecting fixed users', () => {
        const result = ShiftHelper.categorizeUsers(
            [shiftUser({ user: user(10), is_fixed: false })],
            [],
            positions,
            true,
        );

        expect(result.users).toEqual({});
        expect(result.count).toBe(0);
    });

    it('creates a group for a position that is not in the position list', () => {
        const result = ShiftHelper.categorizeUsers(
            [shiftUser({ user: user(10), workplace_position_id: 99 })],
            [],
            positions,
            false,
        );

        expect(result.groupUsers['99']).toEqual(['10']);
    });

    it('adds fixed-schedule users as pending when they have no shift user record', () => {
        const result = ShiftHelper.categorizeUsers([], [user(10)], positions, true);

        expect(result.groupUsers.pending).toEqual(['10']);
        expect(result.userGroups['10']).toBe('pending');
        expect(result.count).toBe(0);
    });

    it('does not downgrade an approved fixed user to pending', () => {
        const result = ShiftHelper.categorizeUsers(
            [shiftUser({ user: user(10), is_fixed: true, workplace_position_id: 1 })],
            [user(10)],
            positions,
            true,
        );

        expect(result.groupUsers.pending).toEqual([]);
        expect(result.groupUsers['1']).toEqual(['10']);
        expect(result.count).toBe(1);
    });

    it('ignores fixed-schedule users when collecting temp users', () => {
        const result = ShiftHelper.categorizeUsers([], [user(10)], positions, false);

        expect(result.users).toEqual({});
        expect(result.groupUsers.pending).toEqual([]);
    });
});

describe('ShiftHelper.getShiftTime', () => {
    it('combines the shift date with the workplace shift times', () => {
        const [start, end] = ShiftHelper.getShiftTime({
            date: '2026-07-10',
            workplace_shift: workplaceShift(1, '09:00', '18:00'),
        } as Shift);

        expect(start?.format('YYYY-MM-DD HH:mm')).toBe('2026-07-10 09:00');
        expect(end?.format('YYYY-MM-DD HH:mm')).toBe('2026-07-10 18:00');
    });

    it('rolls the end time to the next day for an overnight shift', () => {
        const [start, end] = ShiftHelper.getShiftTime({
            date: '2026-07-10',
            workplace_shift: workplaceShift(1, '22:00', '06:00'),
        } as Shift);

        expect(start?.format('YYYY-MM-DD HH:mm')).toBe('2026-07-10 22:00');
        expect(end?.format('YYYY-MM-DD HH:mm')).toBe('2026-07-11 06:00');
    });

    it('returns nulls when the shift date cannot be parsed', () => {
        expect(
            ShiftHelper.getShiftTime({
                date: 'not-a-date',
                workplace_shift: workplaceShift(1, '09:00', '18:00'),
            } as Shift),
        ).toEqual([null, null]);
    });
});

describe('ShiftHelper.getStatus / isShiftEnded', () => {
    const shift = (start: string, end: string, date = '2026-07-10'): Shift =>
        ({ date, workplace_shift: workplaceShift(1, start, end) }) as Shift;

    const freezeAt = (iso: string) => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(iso));
    };

    afterEach(() => {
        vi.useRealTimers();
    });

    it('reports coming soon before the shift starts', () => {
        freezeAt('2026-07-10T00:00:00+09:00');

        expect(ShiftHelper.getStatus(shift('09:00', '18:00'))).toBe(ShiftStatusEnum.COMING_SOON.value);
        expect(ShiftHelper.isShiftEnded(shift('09:00', '18:00'))).toBe(false);
    });

    it('reports in progress between the start and end times', () => {
        freezeAt('2026-07-10T12:00:00+09:00');

        expect(ShiftHelper.getStatus(shift('09:00', '18:00'))).toBe(ShiftStatusEnum.IN_PROGRESS.value);
        expect(ShiftHelper.isShiftEnded(shift('09:00', '18:00'))).toBe(false);
    });

    it('reports ended after the shift finishes', () => {
        freezeAt('2026-07-10T19:00:00+09:00');

        expect(ShiftHelper.getStatus(shift('09:00', '18:00'))).toBe(ShiftStatusEnum.ENDED.value);
        expect(ShiftHelper.isShiftEnded(shift('09:00', '18:00'))).toBe(true);
    });

    it('treats an overnight shift as in progress after midnight', () => {
        freezeAt('2026-07-11T02:00:00+09:00');

        expect(ShiftHelper.getStatus(shift('22:00', '06:00'))).toBe(ShiftStatusEnum.IN_PROGRESS.value);
    });

    it('reports ended when the shift time cannot be parsed', () => {
        freezeAt('2026-07-10T12:00:00+09:00');

        expect(ShiftHelper.getStatus(shift('09:00', '18:00', 'not-a-date'))).toBe(ShiftStatusEnum.ENDED.value);
        expect(ShiftHelper.isShiftEnded(shift('09:00', '18:00', 'not-a-date'))).toBe(true);
    });
});
