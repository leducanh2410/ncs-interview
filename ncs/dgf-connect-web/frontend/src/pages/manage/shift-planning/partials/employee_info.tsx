import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuTriangleAlert } from 'react-icons/lu';
import { Badge, Box, Group, Stack, Text } from '@mantine/core';
import {
    PlanEmployee,
    employeeWarnings,
    fixedScheduleLines,
    worktimeGroupLabel,
} from '@/pages/manage/shift-planning/partials/data';

// One labeled field: a fixed-width dimmed label + a value that fills the rest.
const InfoRow: React.FC<{ label: string; value: React.ReactNode; color?: string }> = ({ label, value, color }) => (
    <Group gap="xs" wrap="nowrap" align="flex-start">
        <Text size="xs" c="dimmed" w={92} style={{ flexShrink: 0 }}>
            {label}
        </Text>
        <Text size="xs" c={color} style={{ flex: 1, minWidth: 0 }}>
            {value}
        </Text>
    </Group>
);

type Props = {
    employee: PlanEmployee;
    // Trailing window (days) the recent-shift count is measured over.
    recentDays: number;
};

// Read-only employee summary shown when the (canvas-drawn) employee cell is
// clicked. Rendered as real DOM so the name / staff no can be selected and
// copied — something the canvas cell itself can't offer.
const EmployeeInfo: React.FC<Props> = ({ employee, recentDays }) => {
    const { t } = useTranslation();
    const scheduleLines = fixedScheduleLines(employee);
    const warnings = employeeWarnings(employee, t);

    return (
        <Stack gap="xs" w={260} style={{ userSelect: 'text' }}>
            <Group gap={6} wrap="nowrap" align="center" style={{ minWidth: 0 }}>
                {employee.hasFixedSchedule && (
                    <Box
                        w={7}
                        h={7}
                        bg="teal.5"
                        style={{ borderRadius: '50%', flexShrink: 0 }}
                        title={t('Fixed schedule')}
                    />
                )}
                <Text fw={600} size="sm" style={{ flex: 1, minWidth: 0 }}>
                    {employee.name}
                </Text>
            </Group>

            <Stack gap={4}>
                <InfoRow label={t('Staff No.')} value={employee.staffNo || '—'} />
                <InfoRow label={t('Worktime')} value={worktimeGroupLabel(employee.worktimeGroup, t)} />
                {scheduleLines.length > 0 && <InfoRow label={t('Fixed schedule')} value={scheduleLines.join(' · ')} />}
                <InfoRow
                    label={t('Recent shifts')}
                    value={
                        <Group gap={6} wrap="nowrap" align="center">
                            <Badge color="blue" variant="light" radius="sm">
                                {t('{{count}} shifts', { count: employee.recentShiftCount })}
                            </Badge>
                            <Text size="xs" c="dimmed">
                                {t('in the last {{count}} days', { count: recentDays })}
                            </Text>
                        </Group>
                    }
                />
            </Stack>

            {warnings.length > 0 && (
                <Stack gap={4}>
                    {warnings.map((w) => (
                        <Group key={w.message} gap={6} wrap="nowrap" align="center">
                            <Box c={w.color === 'red' ? 'red' : 'yellow.7'} style={{ display: 'flex', flexShrink: 0 }}>
                                <LuTriangleAlert size={14} />
                            </Box>
                            <Text size="xs" c={w.color === 'red' ? 'red' : 'yellow.8'}>
                                {w.message}
                            </Text>
                        </Group>
                    ))}
                </Stack>
            )}
        </Stack>
    );
};

export default EmployeeInfo;
