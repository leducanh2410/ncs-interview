import React from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Box, Skeleton, Stack, Table, Text, Tooltip } from '@mantine/core';
import { CapacityRow } from '@/pages/admin/dashboard/types';

const SMALL_POOL_THRESHOLD = 5;

interface Props {
    rows: CapacityRow[];
    loading?: boolean;
    reliabilityAvailable?: boolean;
}

const DASH = '—';

const dayLabelKeys = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as const;

const isWithinNext7Days = (dateStr: string): boolean => {
    const today = new Date();
    const target = new Date(dateStr);
    const diffDays = Math.floor((target.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
    return diffDays <= 6;
};

const tagColor = (tag: CapacityRow['capacity_tag']): string => {
    switch (tag) {
        case 'critical':
            return 'red';
        case 'tight':
            return 'orange';
        case 'healthy':
            return 'teal';
        default:
            return 'gray';
    }
};

const formatPercent = (value: number | null): string => {
    if (value === null || value === undefined) return DASH;
    return `${(value * 100).toFixed(0)}%`;
};

const formatInt = (value: number | null): string => {
    if (value === null || value === undefined) return DASH;
    return String(value);
};

const isSmallPool = (row: CapacityRow): boolean =>
    row.capacity_reason !== 'no_pool' &&
    row.active_pool !== null &&
    row.active_pool > 0 &&
    row.active_pool <= SMALL_POOL_THRESHOLD;

const tagTooltipLabel = (t: (key: string, params?: Record<string, unknown>) => string, row: CapacityRow): string => {
    if (row.capacity_reason === 'no_pool') {
        return t(
            'No active workers in the 90-day pool. Even one assignment makes this critical because there is no buffer.',
        );
    }
    if (isSmallPool(row)) {
        return t(
            'Active pool is very small ({{pool}}) so the utilization ratio is noisy. Treat this signal as advisory.',
            { pool: row.active_pool },
        );
    }
    if (row.capacity_tag === 'critical') {
        return t('Utilization is at or above 90% of the 90-day active pool.');
    }
    if (row.capacity_tag === 'tight') {
        return t('Utilization is at or above 75% of the 90-day active pool.');
    }
    return t('Utilization is below 75% of the 90-day active pool.');
};

const CapacityTable: React.FC<Props> = ({ rows, loading = false, reliabilityAvailable }) => {
    const { t } = useTranslation();

    if (loading) {
        return (
            <Stack gap="xs">
                {Array.from({ length: 6 }).map((_, idx) => (
                    <Skeleton key={idx} h={32} />
                ))}
            </Stack>
        );
    }

    if (rows.length === 0) {
        return (
            <Box py="xl" ta="center">
                <Text c="dimmed">{t('No capacity rows for the selected workplaces.')}</Text>
            </Box>
        );
    }

    return (
        <Stack gap="xs">
            {reliabilityAvailable === false && (
                <Text size="xs" c="dimmed">
                    {t('Active pool and utilization hidden — needs the work_history.list permission.')}
                </Text>
            )}
            <Table.ScrollContainer minWidth={720} maxHeight={520} type="native">
                <Table withTableBorder striped highlightOnHover stickyHeader>
                    <Table.Thead>
                        <Table.Tr>
                            <Table.Th>{t('Date')}</Table.Th>
                            <Table.Th>{t('Day')}</Table.Th>
                            <Table.Th>{t('Workplace')}</Table.Th>
                            <Table.Th ta="right">{t('Assigned')}</Table.Th>
                            <Table.Th ta="right">{t('Active pool')}</Table.Th>
                            <Table.Th ta="right">{t('Free bench')}</Table.Th>
                            <Table.Th ta="right">{t('Utilization')}</Table.Th>
                            <Table.Th>{t('Status')}</Table.Th>
                        </Table.Tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {rows.map((row, idx) => {
                            const highlight = isWithinNext7Days(row.date);
                            const tag = row.capacity_tag;
                            return (
                                <Table.Tr
                                    key={`${row.workplace_id}-${row.date}-${idx}`}
                                    style={{
                                        backgroundColor: highlight ? 'var(--mantine-color-blue-0)' : undefined,
                                    }}
                                >
                                    <Table.Td>{row.date}</Table.Td>
                                    <Table.Td>{t(dayLabelKeys[row.day_of_week] ?? 'Sun')}</Table.Td>
                                    <Table.Td>{row.workplace_name}</Table.Td>
                                    <Table.Td ta="right">{row.assigned}</Table.Td>
                                    <Table.Td ta="right">{formatInt(row.active_pool)}</Table.Td>
                                    <Table.Td ta="right">
                                        {row.free_bench === null ? (
                                            <Text size="xs" c="dimmed">
                                                {t('Hidden')}
                                            </Text>
                                        ) : (
                                            row.free_bench
                                        )}
                                    </Table.Td>
                                    <Table.Td ta="right">{formatPercent(row.utilization)}</Table.Td>
                                    <Table.Td>
                                        {tag ? (
                                            <Tooltip label={tagTooltipLabel(t, row)} multiline w={260} withArrow>
                                                <Badge
                                                    color={tagColor(tag)}
                                                    variant="light"
                                                    radius="sm"
                                                    style={{ cursor: 'help' }}
                                                >
                                                    {t(tag)}
                                                    {row.capacity_reason === 'no_pool'
                                                        ? ` · ${t('no pool')}`
                                                        : isSmallPool(row)
                                                          ? ` · ${t('small pool')}`
                                                          : ''}
                                                </Badge>
                                            </Tooltip>
                                        ) : (
                                            <Text size="xs" c="dimmed">
                                                {DASH}
                                            </Text>
                                        )}
                                    </Table.Td>
                                </Table.Tr>
                            );
                        })}
                    </Table.Tbody>
                </Table>
            </Table.ScrollContainer>
        </Stack>
    );
};

export default CapacityTable;
