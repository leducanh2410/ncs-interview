import React from 'react';
import { useTranslation } from 'react-i18next';
import { Box, Divider, Progress, Stack, Text, Tooltip } from '@mantine/core';
import { WorkLoadStatusEnum } from '@/enums/work_load_status.enum';
import {
    WorkLoadDayList,
    WorkedHoursSummary,
    formatHours,
    formatShortDate,
    getWorkLoadColor,
} from '@/pages/manage/shift/partials/weekly_work_load';
import { WorkLoadDay } from '@/pages/manage/shift/api';
import { WeeklyHoursSummary } from '@/pages/manage/shift/weekly-hours/api';

type Props = {
    summary: WeeklyHoursSummary;
    days: WorkLoadDay[];
};

const WeeklyHoursCell = ({ summary, days }: Props) => {
    const { t } = useTranslation();
    const color = getWorkLoadColor(summary.status);
    const percent = Math.min(100, (summary.total_hours / Math.max(summary.max_hour_per_week, 1)) * 100);
    const remainingHours = summary.max_hour_per_week - summary.total_hours;
    const range = `${formatShortDate(summary.start, t)} ~ ${formatShortDate(summary.end, t)}`;

    return (
        <Tooltip
            withArrow
            multiline
            label={
                <Stack gap={4} miw={340}>
                    <Text size="xs" fw={700}>
                        {range}
                    </Text>
                    <WorkLoadDayList days={days} />
                    <Divider my={2} />
                    <WorkedHoursSummary days={days} />
                    <Text size="xs">
                        {t('Weekly limit: {{hours}} ({{source}})', {
                            hours: formatHours(summary.max_hour_per_week, t),
                            source: summary.is_custom_limit ? t('Employee config') : t('Default config'),
                        })}
                    </Text>
                    {summary.is_limit_changed && (
                        <Text size="xs" c="yellow.3">
                            {t('The limit changes within these days; the lower limit is used')}
                        </Text>
                    )}
                    <Text size="xs" fw={700}>
                        {remainingHours >= 0
                            ? t('Remaining: {{hours}}', { hours: formatHours(remainingHours, t) })
                            : t('Over by {{hours}}', { hours: formatHours(-remainingHours, t) })}
                    </Text>
                </Stack>
            }
        >
            <Box miw={100}>
                <Text
                    size="sm"
                    fw={summary.status === WorkLoadStatusEnum.NORMAL.value ? 400 : 700}
                    c={summary.status === WorkLoadStatusEnum.NORMAL.value ? undefined : color}
                >
                    {formatHours(summary.total_hours, t)} / {formatHours(summary.max_hour_per_week, t)}
                    {summary.is_custom_limit ? '*' : ''}
                </Text>
                <Progress value={percent} color={color} size="xs" mt={2} />
            </Box>
        </Tooltip>
    );
};

export default WeeklyHoursCell;
