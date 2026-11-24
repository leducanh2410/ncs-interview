import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { BarChart } from '@mantine/charts';
import { Box, Skeleton, Stack, Text } from '@mantine/core';
import { getResponsiveChartAttributes } from '@/pages/admin/dashboard/components/chartAttributes';
import { ShiftTimeGroupReliabilityRow } from '@/pages/admin/dashboard/types';

interface Props {
    rows: ShiftTimeGroupReliabilityRow[] | null;
    loading?: boolean;
}

const ShiftTimeGroupChart: React.FC<Props> = ({ rows, loading = false }) => {
    const { t } = useTranslation();

    const data = useMemo(() => {
        if (!rows) return [];
        return rows.map((row) => ({
            group: t(row.group),
            rate: row.show_up_rate ?? 0,
            assigned: row.assigned_count,
            checkIns: row.check_in_count,
            lowSample: row.low_sample,
        }));
    }, [rows, t]);

    if (loading || !rows) {
        return <Skeleton h={180} />;
    }

    const hasAnyData = rows.some((r) => r.assigned_count > 0);
    if (!hasAnyData) {
        return (
            <Box py="md" ta="center">
                <Text size="sm" c="dimmed">
                    {t('Not enough data yet to compare show-up rate by shift time.')}
                </Text>
            </Box>
        );
    }

    return (
        <Stack gap={4}>
            <Text size="xs" c="dimmed" fw={600} tt="uppercase">
                {t('Show-up rate by shift time')}
            </Text>
            <BarChart
                h={180}
                data={data}
                dataKey="group"
                series={[{ name: 'rate', color: 'teal.6', label: t('Show-up rate') }]}
                tickLine="x"
                gridAxis="y"
                yAxisProps={{ domain: [0, 100], width: 40 }}
                valueFormatter={(value) => `${value}%`}
                barProps={{ radius: 4 }}
                attributes={getResponsiveChartAttributes(180)}
            />
        </Stack>
    );
};

export default ShiftTimeGroupChart;
