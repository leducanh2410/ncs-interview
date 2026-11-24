import dayjs from 'dayjs';
import { Shift, ShiftWithCount } from '@/types/models/shift';
import { ShiftUserWithUserInfo } from '@/types/models/shift_user';
import { User } from '@/types/models/user';
import { WorkplacePosition } from '@/types/models/workplace_position';
import { WorkplaceShift } from '@/types/models/workplace_shift';
import { TimelineData, TimelineItem } from '@/types/shift';
import { ShiftStatusEnum } from '@/enums/shift_status.enum';
import { ShiftUserStatusEnum } from '@/enums/shift_user_status.enum';
import CommonHelper from '@/helpers/common.helper';
import DateHelper from '@/helpers/date.helper';

export const SHIFT_HEIGHT = 72;

const ShiftHelper = {
    getHourRangeFromShift: (workplaceShift: WorkplaceShift, isFloat = false) => {
        const startTime = dayjs(workplaceShift.start_time, 'HH:mm');
        const endTime = dayjs(workplaceShift.end_time, 'HH:mm');
        const startHour = startTime.hour() + startTime.minute() / 60;
        let endHour = endTime.hour() + endTime.minute() / 60;
        if (endHour <= startHour) {
            endHour += 24;
        }
        if (isFloat) {
            return [startHour, endHour];
        }
        return [Math.floor(startHour), Math.ceil(endHour)];
    },
    isOverlapping: (shift1: WorkplaceShift, shift2: WorkplaceShift) => {
        const [startHour1, endHour1] = ShiftHelper.getHourRangeFromShift(shift1, true);
        const [startHour2, endHour2] = ShiftHelper.getHourRangeFromShift(shift2, true);
        if (startHour1 === startHour2 || endHour1 === endHour2) {
            return true;
        }
        if (startHour1 < startHour2) {
            return endHour1 > startHour2;
        }
        return endHour2 > startHour1;
    },
    splitToRows: (data: TimelineItem[]) => {
        if (!data.length) {
            return [];
        }
        const rows: TimelineItem[][] = [[data[0]]];
        for (let i = 1; i < data.length; i++) {
            const item = data[i];
            let isAdded = false;
            for (const row of rows) {
                const canAdd = row.every((rowItem) => {
                    return !ShiftHelper.isOverlapping(rowItem.workplaceShift, item.workplaceShift);
                });
                if (canAdd) {
                    row.push(item);
                    isAdded = true;
                    break;
                }
            }
            if (!isAdded) {
                rows.push([item]);
            }
        }

        return rows;
    },
    calculateOffsetAndWidth: (item: TimelineItem, hours: string[], widthPerHour: number) => {
        const timelineStart = dayjs(hours[0].substring(0, 5), 'HH:mm');
        const startHour = dayjs(item.workplaceShift.start_time, 'HH:mm');
        let endHour = dayjs(item.workplaceShift.end_time, 'HH:mm');
        if (endHour.isBefore(startHour)) {
            endHour = endHour.add(1, 'day');
        }
        const diff = startHour.diff(timelineStart, 'hour', true);
        let closesMilestone = Math.floor(diff) * widthPerHour;
        let offsetLeft = Math.round(startHour.diff(timelineStart, 'hour', true) * widthPerHour);
        if (offsetLeft - closesMilestone < 4) {
            offsetLeft = closesMilestone + 4;
        }
        const duration = endHour.diff(startHour, 'hour', true);
        let width = Math.round(duration * widthPerHour);
        closesMilestone = Math.ceil(endHour.diff(timelineStart, 'hour', true)) * widthPerHour;
        if (closesMilestone - (offsetLeft + width) < 4) {
            width = closesMilestone - offsetLeft - 4;
        }

        return { offset: offsetLeft, width };
    },
    getTimeLineData: (
        data: Record<
            number,
            {
                workplace_shifts: WorkplaceShift[];
                shifts: ShiftWithCount[];
            }
        >,
    ): TimelineData => {
        const timeLineData: Record<number, TimelineItem[]> = {};
        let minHour = -1;
        let maxHour = 0;
        for (const workplaceId in data) {
            const workplaceShifts = data[workplaceId].workplace_shifts;
            const shifts = data[workplaceId].shifts;
            let keyByTime: Record<string, TimelineItem> = {};

            for (const workplaceShift of workplaceShifts) {
                const [startHour, endHour] = ShiftHelper.getHourRangeFromShift(workplaceShift);
                const key = `${workplaceShift.start_time}-${workplaceShift.end_time}_${workplaceShift.id}`;
                keyByTime[key] = {
                    workplaceShift,
                    shift: null,
                };
                if (minHour === -1 || minHour > startHour) {
                    minHour = startHour;
                }
                if (maxHour < endHour) {
                    maxHour = endHour;
                }
            }
            for (const shift of shifts) {
                const [startHour, endHour] = ShiftHelper.getHourRangeFromShift(shift.workplace_shift);
                const key = `${shift.workplace_shift.start_time}-${shift.workplace_shift.end_time}_${shift.workplace_shift.id}`;
                if (keyByTime[key]) {
                    keyByTime[key].shift = shift;
                } else {
                    keyByTime[key] = {
                        workplaceShift: shift.workplace_shift,
                        shift,
                    };
                }
                if (minHour === -1 || minHour > startHour) {
                    minHour = startHour;
                }
                if (maxHour < endHour) {
                    maxHour = endHour;
                }
            }

            keyByTime = CommonHelper.sortObjectByKey(keyByTime);
            timeLineData[workplaceId] = Object.values(keyByTime);
        }

        const hours: string[] = [];
        const start = dayjs().hour(0).minute(0);
        for (let i = minHour; i <= maxHour; i++) {
            hours.push(start.add(i, 'hour').format('HH:mm') + `_${i}`);
        }

        return { hours, data: timeLineData };
    },
    categorizeUsers: (
        shiftUsers: ShiftUserWithUserInfo[],
        fixedScheduleUsers: User[],
        positions: WorkplacePosition[],
        isFixed: boolean,
    ) => {
        let approvedCount = 0;
        const users: Record<string, User> = {};
        const userGroups: Record<string, string> = {};
        const groupUsers: Record<string, string[]> = { pending: [], rejected: [] };
        for (const position of positions) {
            groupUsers[position.id.toString()] = [];
        }
        for (const shiftUser of shiftUsers) {
            if ((isFixed && !shiftUser.is_fixed) || (!isFixed && shiftUser.is_fixed)) {
                continue;
            }
            const userId = shiftUser.user.id.toString();
            users[userId] = shiftUser.user;
            if (shiftUser.status === ShiftUserStatusEnum.PENDING.value) {
                groupUsers.pending.push(userId);
                userGroups[userId] = 'pending';
            } else if (shiftUser.status === ShiftUserStatusEnum.REJECTED.value) {
                groupUsers.rejected.push(userId);
                userGroups[userId] = 'rejected';
            } else if (shiftUser.workplace_position_id) {
                const key = shiftUser.workplace_position_id.toString();
                if (!groupUsers[key]) {
                    groupUsers[key] = [];
                }
                groupUsers[key].push(userId);
                userGroups[userId] = key;
                approvedCount++;
            }
        }

        if (isFixed) {
            for (const user of fixedScheduleUsers) {
                const userId = user.id.toString();
                if (!users[userId]) {
                    users[userId] = user;
                    groupUsers.pending.push(userId);
                    userGroups[userId] = 'pending';
                }
            }
        }

        return { users, userGroups, groupUsers, count: approvedCount };
    },
    getShiftTime: (shift: Shift) => {
        const startTime = DateHelper.parse(`${shift.date} ${shift.workplace_shift.start_time}`, 'YYYY-MM-DD HH:mm');
        let endTime = DateHelper.parse(`${shift.date} ${shift.workplace_shift.end_time}`, 'YYYY-MM-DD HH:mm');
        if (!startTime || !endTime) {
            return [null, null];
        }
        if (startTime.isAfter(endTime)) {
            endTime = endTime.add(1, 'day');
        }
        return [startTime, endTime];
    },
    getStatus: (shift: Shift): string => {
        const now = DateHelper.now();
        const [startTime, endTime] = ShiftHelper.getShiftTime(shift);
        if (!startTime || !endTime) {
            return ShiftStatusEnum.ENDED.value;
        }
        if (now.isBefore(startTime)) {
            return ShiftStatusEnum.COMING_SOON.value;
        } else if (now.isAfter(endTime)) {
            return ShiftStatusEnum.ENDED.value;
        }
        return ShiftStatusEnum.IN_PROGRESS.value;
    },
    isShiftEnded: (shift: Shift): boolean => {
        const status = ShiftHelper.getStatus(shift);
        return status === ShiftStatusEnum.ENDED.value;
    },
};

export default ShiftHelper;
