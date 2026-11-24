import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { AreaChart } from '@mantine/charts';
import { Box, Skeleton, Text } from '@mantine/core';
import dayjs from 'dayjs';
import ReliabilityRedactedHint from '@/pages/admin/dashboard/components/ReliabilityRedactedHint';
import { getResponsiveChartAttributes } from '@/pages/admin/dashboard/components/chartAttributes';
import { DailyCheckInsBlock } from '@/pages/admin/dashboard/types';

interface Props {
    data: DailyCheckInsBlock | null;
    loading?: boolean;
    reliabilityAvailable?: boolean;
}

const DailyCheckInsChart: React.FC<Props> = ({ data, loading = false, reliabilityAvailable = true }) => {
    const { t } = useTranslation();

    const totalKey = useMemo(() => t('Total'), [t]);

    const chartData = useMemo(() => {
        if (!data) return [];
        return data.rows.map((row) => ({
            date: row.date,
            [totalKey]: row.total,
        }));
    }, [data, totalKey]);

    if (!reliabilityAvailable) {
        return <ReliabilityRedactedHint />;
    }

    if (loading || !data) {
        return <Skeleton h={260} />;
    }

    if (data.rows.length === 0) {
        return (
            <Box py="xl" ta="center">
                <Text c="dimmed">{t('No check-in data for the selected workplaces.')}</Text>
            </Box>
        );
    }

    return (
        <AreaChart
            h={260}
            data={chartData}
            dataKey="date"
            series={[{ name: totalKey, color: 'blue.6' }]}
            curveType="monotone"
            withDots={false}
            withGradient
            fillOpacity={0.25}
            strokeWidth={2}
            tickLine="x"
            gridAxis="xy"
            xAxisProps={{
                tickFormatter: (value: string) => dayjs(value).format(t('FORMAT_DATE_SHORT')),
                minTickGap: 24,
            }}
            yAxisProps={{ width: 40 }}
            valueFormatter={(value) => String(value)}
            attributes={getResponsiveChartAttributes(260)}
        />
    );
};

export default DailyCheckInsChart;
