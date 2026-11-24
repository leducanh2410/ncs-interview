import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuClipboardCheck, LuTrendingDown, LuTrendingUp, LuUsers } from 'react-icons/lu';
import { Group, Paper, SimpleGrid, Skeleton, Stack, Text } from '@mantine/core';
import KpiSparkline from '@/pages/admin/dashboard/components/KpiSparkline';
import ReliabilityRedactedHint from '@/pages/admin/dashboard/components/ReliabilityRedactedHint';
import { KpiMetric, KpiResponse } from '@/pages/admin/dashboard/types';

interface Props {
    data: KpiResponse | null;
    loading?: boolean;
    reliabilityAvailable?: boolean;
}

const DASH = '—';

const formatPercent = (value: number | null): string => {
    if (value === null || value === undefined) return DASH;
    return `${value.toFixed(1)}%`;
};

const formatInt = (value: number | null): string => {
    if (value === null || value === undefined) return DASH;
    return String(value);
};

const tooltipInt = (value: number) => String(Math.round(value));

const tooltipPercent = (value: number) => `${value.toFixed(1)}%`;

interface DeltaIndicatorProps {
    delta: number | null;
    /** When true, a negative delta is shown as an improvement (e.g. no-show risk). */
    invertSemantics?: boolean;
    formatter?: (delta: number) => string;
}

const DeltaIndicator: React.FC<DeltaIndicatorProps> = ({ delta, invertSemantics = false, formatter }) => {
    const { t } = useTranslation();
    if (delta === null || delta === undefined) {
        return (
            <Text size="xs" c="dimmed">
                {t('No comparison data')}
            </Text>
        );
    }

    const positive = delta > 0;
    const isImprovement = invertSemantics ? !positive && delta !== 0 : positive;
    const color = delta === 0 ? 'gray' : isImprovement ? 'teal' : 'red';
    const Icon = positive ? LuTrendingUp : LuTrendingDown;
    const text = formatter ? formatter(delta) : `${delta > 0 ? '+' : ''}${delta.toFixed(1)}`;

    return (
        <Group gap={4} align="center">
            <Icon size={14} color={`var(--mantine-color-${color}-6)`} />
            <Text size="xs" c={color} fw={500}>
                {text}
            </Text>
        </Group>
    );
};

interface CardShellProps {
    title: string;
    children: React.ReactNode;
    loading?: boolean;
}

const CardShell: React.FC<CardShellProps> = ({ title, children, loading }) => (
    <Paper p="md">
        <Stack gap={6}>
            <Text size="xs" c="dimmed" fw={600} tt="uppercase">
                {title}
            </Text>
            {loading ? (
                <>
                    <Skeleton h={28} w={120} />
                    <Skeleton h={32} />
                    <Skeleton h={12} w={80} />
                </>
            ) : (
                children
            )}
        </Stack>
    </Paper>
);

const ActivePoolCard: React.FC<{ metric: KpiMetric; dates?: string[]; loading?: boolean; available: boolean }> = ({
    metric,
    dates,
    loading,
    available,
}) => {
    const { t } = useTranslation();
    return (
        <CardShell title={t('Active worker pool')} loading={loading}>
            <Group gap="xs" align="center">
                <LuUsers size={20} />
                <Text size="xl" fw={700}>
                    {available ? formatInt(metric.value) : DASH}
                </Text>
            </Group>
            {available && (
                <KpiSparkline series={metric.series} dates={dates} color="blue.6" valueFormatter={tooltipInt} />
            )}
            {available ? (
                <DeltaIndicator
                    delta={metric.delta}
                    formatter={(d) => `${d > 0 ? '+' : ''}${d.toFixed(0)} ${t('vs prior window')}`}
                />
            ) : (
                <Text size="xs" c="dimmed">
                    {t('Reliability data hidden')}
                </Text>
            )}
        </CardShell>
    );
};

const ShowUpRateCard: React.FC<{ metric: KpiMetric; dates?: string[]; loading?: boolean; available: boolean }> = ({
    metric,
    dates,
    loading,
    available,
}) => {
    const { t } = useTranslation();
    return (
        <CardShell title={t('Average show-up rate')} loading={loading}>
            <Text size="xl" fw={700}>
                {available ? formatPercent(metric.value) : DASH}
            </Text>
            {available && (
                <KpiSparkline series={metric.series} dates={dates} color="teal.6" valueFormatter={tooltipPercent} />
            )}
            {available ? (
                <DeltaIndicator delta={metric.delta} formatter={(d) => `${d > 0 ? '+' : ''}${d.toFixed(1)}pp`} />
            ) : (
                <Text size="xs" c="dimmed">
                    {t('Reliability data hidden')}
                </Text>
            )}
        </CardShell>
    );
};

const AvgDailyCheckInsCard: React.FC<{
    metric: KpiMetric;
    dates?: string[];
    loading?: boolean;
    available: boolean;
}> = ({ metric, dates, loading, available }) => {
    const { t } = useTranslation();
    return (
        <CardShell title={t('Average daily check-ins')} loading={loading}>
            <Group gap="xs" align="center">
                <LuClipboardCheck size={20} />
                <Text size="xl" fw={700}>
                    {available ? formatInt(metric.value) : DASH}
                </Text>
            </Group>
            {available && (
                <KpiSparkline series={metric.series} dates={dates} color="cyan.6" valueFormatter={tooltipInt} />
            )}
            {available ? (
                <DeltaIndicator
                    delta={metric.delta}
                    formatter={(d) => `${d > 0 ? '+' : ''}${d.toFixed(0)} ${t('vs prior window')}`}
                />
            ) : (
                <Text size="xs" c="dimmed">
                    {t('Reliability data hidden')}
                </Text>
            )}
        </CardShell>
    );
};

const NoShowRiskCard: React.FC<{ metric: KpiMetric; dates?: string[]; loading?: boolean; available: boolean }> = ({
    metric,
    dates,
    loading,
    available,
}) => {
    const { t } = useTranslation();
    return (
        <CardShell title={t('No-show risk (30d)')} loading={loading}>
            <Text size="xl" fw={700}>
                {available ? formatPercent(metric.value) : DASH}
            </Text>
            {available && (
                <KpiSparkline series={metric.series} dates={dates} color="red.6" valueFormatter={tooltipPercent} />
            )}
            {available ? (
                <DeltaIndicator
                    delta={metric.delta}
                    invertSemantics
                    formatter={(d) => `${d > 0 ? '+' : ''}${d.toFixed(1)}pp`}
                />
            ) : (
                <Text size="xs" c="dimmed">
                    {t('Reliability data hidden')}
                </Text>
            )}
        </CardShell>
    );
};

const KpiCardRow: React.FC<Props> = ({ data, loading = false, reliabilityAvailable }) => {
    const available = reliabilityAvailable ?? data?.reliability_available ?? true;

    const emptyMetric: KpiMetric = { value: null, delta: null, series: [] };
    const activePool = data?.active_pool ?? emptyMetric;
    const showUp = data?.show_up_rate ?? emptyMetric;
    const avgDailyCheckIns = data?.avg_daily_check_ins ?? emptyMetric;
    const noShow = data?.no_show_risk ?? emptyMetric;
    const dates = data?.daily_check_ins.rows.map((row) => row.date);

    return (
        <Stack gap="sm">
            <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }} spacing="md">
                <ActivePoolCard metric={activePool} dates={dates} loading={loading} available={available} />
                <ShowUpRateCard metric={showUp} dates={dates} loading={loading} available={available} />
                <AvgDailyCheckInsCard metric={avgDailyCheckIns} dates={dates} loading={loading} available={available} />
                <NoShowRiskCard metric={noShow} dates={dates} loading={loading} available={available} />
            </SimpleGrid>
            {!loading && !available && <ReliabilityRedactedHint />}
        </Stack>
    );
};

export default KpiCardRow;
