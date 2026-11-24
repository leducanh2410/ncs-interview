import React from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Box, Divider, Flex, Progress, Stack, Text, Tooltip } from '@mantine/core';
import { TFunction } from 'i18next';
import { WorkLoadStatusEnum } from '@/enums/work_load_status.enum';
import DateHelper, { DATE_STORE_FORMAT } from '@/helpers/date.helper';
import { ShiftWeeklyWorkLoad, WorkLoadDay } from '@/pages/manage/shift/api';

export const formatHours = (hours: number, t: TFunction) => `${Number(hours.toFixed(2))}${t('h')}`;

export const formatShortDate = (date: string, t: TFunction) => {
    const parsed = DateHelper.parse(date, DATE_STORE_FORMAT);
    return parsed ? `${parsed.format(t('FORMAT_DATE_SHORT'))} (${parsed.format('dd')})` : date;
};

export const getWorkLoadColor = (status: string) =>
    Object.values(WorkLoadStatusEnum).find((workLoadStatus) => workLoadStatus.value === status)?.color ?? 'gray';

type LimitSource = Pick<ShiftWeeklyWorkLoad, 'is_custom_limit' | 'limit_start_date' | 'limit_expiry_date'>;

export const getLimitSourceLabel = (limit: LimitSource, t: TFunction) => {
    if (!limit.is_custom_limit) {
        return t('Default config');
    }
    if (!limit.limit_start_date && !limit.limit_expiry_date) {
        return t('Employee config (no period)');
    }
    return t('Employee config ({{start}} ~ {{end}})', {
        start: limit.limit_start_date ? DateHelper.parseAndFormat(limit.limit_start_date) : '',
        end: limit.limit_expiry_date ? DateHelper.parseAndFormat(limit.limit_expiry_date) : '',
    });
};

const HOURS_EPSILON = 0.005;

export const getNotCheckedOutHours = (hours: number, actualHours: number) =>
    Math.max(0, hours - actualHours) > HOURS_EPSILON ? hours - actualHours : 0;

export const describeDayHours = (day: WorkLoadDay, t: TFunction) => {
    const notCheckedOutHours = getNotCheckedOutHours(day.hours, day.actual_hours);
    const values = {
        scheduled: formatHours(day.scheduled_hours, t),
        actual: formatHours(day.actual_hours, t),
        pending: formatHours(notCheckedOutHours, t),
        counted: formatHours(day.hours, t),
    };

    if (day.actual_hours <= HOURS_EPSILON) {
        return t('Shift {{scheduled}} · not checked out → counted {{counted}}', values);
    }
    if (notCheckedOutHours === 0) {
        return t('Shift {{scheduled}} · actual {{actual}} → counted {{counted}}', values);
    }
    return t('Shift {{scheduled}} · actual {{actual}} + not checked out {{pending}} → counted {{counted}}', values);
};

export const WorkLoadDayList = ({ days }: { days: WorkLoadDay[] }) => {
    const { t } = useTranslation();

    return (
        <Stack gap={2}>
            {days.map((day) => (
                <Flex key={day.date} justify="space-between" gap="md">
                    <Text size="xs">{formatShortDate(day.date, t)}</Text>
                    <Text size="xs" c={day.hours > 0 ? undefined : 'dimmed'}>
                        {day.hours > 0 ? describeDayHours(day, t) : '-'}
                    </Text>
                </Flex>
            ))}
        </Stack>
    );
};

export const WorkedHoursSummary = ({ days }: { days: WorkLoadDay[] }) => {
    const { t } = useTranslation();
    const sum = (key: keyof Omit<WorkLoadDay, 'date'>) => days.reduce((total, day) => total + day[key], 0);
    const countedHours = sum('hours');
    const actualHours = sum('actual_hours');

    return (
        <Text size="xs">
            {t('Worked {{actual}} (actual) + remaining by shift {{pending}}', {
                actual: formatHours(actualHours, t),
                pending: formatHours(getNotCheckedOutHours(countedHours, actualHours), t),
            })}
        </Text>
    );
};

const TOOLTIP_STATUS_COLORS: Record<string, string> = {
    [WorkLoadStatusEnum.NORMAL.value]: 'teal.3',
    [WorkLoadStatusEnum.NEAR.value]: 'yellow.3',
    [WorkLoadStatusEnum.OVER.value]: 'red.3',
};

const WorkLoadTooltipContent = ({ workLoad }: { workLoad: ShiftWeeklyWorkLoad }) => {
    const { t } = useTranslation();

    return (
        <Stack gap={4} miw={320}>
            <Text size="xs" fw={700}>
                {t('7 days: {{start}} ~ {{end}}', {
                    start: formatShortDate(workLoad.window_start, t),
                    end: formatShortDate(workLoad.window_end, t),
                })}
            </Text>
            <Text size="xs">
                {t('Total including this shift ({{shift}}): {{total}} / {{limit}}', {
                    shift: formatHours(workLoad.shift_hours, t),
                    total: formatHours(workLoad.projected_hours, t),
                    limit: formatHours(workLoad.max_hour_per_week, t),
                })}
            </Text>
            <Text size="xs" fw={700} c={workLoad.is_blocked ? 'red.3' : TOOLTIP_STATUS_COLORS[workLoad.status]}>
                {workLoad.is_blocked
                    ? t('Over by {{hours}}: can only be moved to the rejected list', {
                          hours: formatHours(-workLoad.remaining_hours, t),
                      })
                    : workLoad.remaining_hours < 0
                      ? t('Over by {{hours}}', { hours: formatHours(-workLoad.remaining_hours, t) })
                      : t('{{hours}} left', { hours: formatHours(workLoad.remaining_hours, t) })}
            </Text>
            <Divider my={2} />
            <WorkLoadDayList days={workLoad.days} />
            <Divider my={2} />
            <Text size="xs">
                {t('Weekly limit: {{hours}} ({{source}})', {
                    hours: formatHours(workLoad.max_hour_per_week, t),
                    source: getLimitSourceLabel(workLoad, t),
                })}
            </Text>
        </Stack>
    );
};

export const WeeklyWorkLoadBar = ({ workLoad }: { workLoad: ShiftWeeklyWorkLoad | undefined }) => {
    const { t } = useTranslation();

    if (!workLoad) {
        return <Text size="xs">-</Text>;
    }

    const color = getDisplayColor(workLoad);
    const percent = Math.min(100, (workLoad.projected_hours / Math.max(workLoad.max_hour_per_week, 1)) * 100);

    return (
        <Tooltip label={<WorkLoadTooltipContent workLoad={workLoad} />} withArrow multiline position="left">
            <Box w="100%">
                <Flex justify="space-between" gap={4}>
                    <Text
                        size="xs"
                        fw={600}
                        c={color === getWorkLoadColor(WorkLoadStatusEnum.NORMAL.value) ? undefined : color}
                    >
                        {formatHours(workLoad.projected_hours, t)} / {formatHours(workLoad.max_hour_per_week, t)}
                        {workLoad.is_custom_limit ? '*' : ''}
                    </Text>
                    <Text size="xs" c={workLoad.remaining_hours >= 0 ? 'dimmed' : 'red'}>
                        {workLoad.remaining_hours >= 0
                            ? t('{{hours}} left', { hours: formatHours(workLoad.remaining_hours, t) })
                            : t('{{hours}} over', { hours: formatHours(-workLoad.remaining_hours, t) })}
                    </Text>
                </Flex>
                <Progress value={percent} color={color} size="sm" mt={2} />
            </Box>
        </Tooltip>
    );
};

export const WeeklyWorkLoadProjection = ({ workLoad }: { workLoad: ShiftWeeklyWorkLoad | undefined }) => {
    const { t } = useTranslation();

    if (!workLoad) {
        return null;
    }

    const color = getDisplayColor(workLoad);

    return (
        <Tooltip label={<WorkLoadTooltipContent workLoad={workLoad} />} withArrow multiline>
            <Badge size="sm" variant="light" color={color} style={{ flexShrink: 0 }}>
                {workLoad.is_shift_counted
                    ? `${formatHours(workLoad.projected_hours, t)} / ${formatHours(workLoad.max_hour_per_week, t)}`
                    : t('{{current}} → {{projected}} / {{limit}}', {
                          current: formatHours(workLoad.weekly_hours, t),
                          projected: formatHours(workLoad.projected_hours, t),
                          limit: formatHours(workLoad.max_hour_per_week, t),
                      })}
                {workLoad.is_custom_limit ? '*' : ''}
            </Badge>
        </Tooltip>
    );
};

export function isAssignmentBlocked(workLoad: ShiftWeeklyWorkLoad | undefined): boolean {
    return Boolean(workLoad?.is_blocked);
}

export function isOverLimitAfterApproval(workLoad: ShiftWeeklyWorkLoad | undefined): boolean {
    return Boolean(workLoad?.is_shift_counted) && workLoad?.status === WorkLoadStatusEnum.OVER.value;
}

export function isPositionTarget(groupId: string): boolean {
    return groupId !== 'pending' && groupId !== 'rejected';
}

const getDisplayColor = (workLoad: ShiftWeeklyWorkLoad) =>
    workLoad.is_blocked ? getWorkLoadColor(WorkLoadStatusEnum.OVER.value) : getWorkLoadColor(workLoad.status);
