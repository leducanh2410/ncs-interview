import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuRefreshCw } from 'react-icons/lu';
import { Box, Button, Flex, Grid, Paper, SegmentedControl, Stack, Text, Title } from '@mantine/core';
import NotificationHelper from '@/helpers/notification.helper';
import CapacityTable from '@/pages/admin/dashboard/components/CapacityTable';
import DailyCheckInsChart from '@/pages/admin/dashboard/components/DailyCheckInsChart';
import DemandHeatmap from '@/pages/admin/dashboard/components/DemandHeatmap';
import ForecastEntryCard from '@/pages/admin/dashboard/components/ForecastEntryCard';
import KpiCardRow from '@/pages/admin/dashboard/components/KpiCardRow';
import ShiftTimeGroupChart from '@/pages/admin/dashboard/components/ShiftTimeGroupChart';
import ShowUpRateWidget from '@/pages/admin/dashboard/components/ShowUpRateWidget';
import WidgetError from '@/pages/admin/dashboard/components/WidgetError';
import WindowControl from '@/pages/admin/dashboard/components/WindowControl';
import WorkplaceMultiSelect from '@/pages/admin/dashboard/components/WorkplaceMultiSelect';
import {
    useCapacityTable,
    useClearDashboardCache,
    useDashboardKpis,
    useDashboardWorkplaces,
    useDemandHeatmap,
    useShowUpRate,
} from '@/pages/admin/dashboard/hooks';
import { HeatmapMode, WindowSelection } from '@/pages/admin/dashboard/types';

const IndexPage = () => {
    const { t } = useTranslation();

    const workplacesQuery = useDashboardWorkplaces();
    const workplacesData = workplacesQuery.data?.data ?? null;
    const workplaceOptions = workplacesData?.demand_workplaces ?? [];
    const reliabilityAvailable = Boolean(
        workplacesData && (workplacesData.reliability_is_global || workplacesData.reliability_workplaces.length > 0),
    );

    const [selectedWorkplaceIds, setSelectedWorkplaceIds] = useState<number[]>([]);
    const [windowSelection, setWindowSelection] = useState<WindowSelection>({ kind: 'preset', days: 90 });
    const [heatmapMode, setHeatmapMode] = useState<HeatmapMode>('groups');

    useEffect(() => {
        if (workplaceOptions.length > 0 && selectedWorkplaceIds.length === 0) {
            setSelectedWorkplaceIds(workplaceOptions.map((w) => w.id));
        }
    }, [workplaceOptions, selectedWorkplaceIds.length]);

    const widgetsEnabled = workplaceOptions.length > 0;

    const kpisQuery = useDashboardKpis(selectedWorkplaceIds, windowSelection, widgetsEnabled);
    const capacityQuery = useCapacityTable(selectedWorkplaceIds, widgetsEnabled);
    const heatmapQuery = useDemandHeatmap(selectedWorkplaceIds, windowSelection, heatmapMode, widgetsEnabled);
    const showUpQuery = useShowUpRate(selectedWorkplaceIds, windowSelection, widgetsEnabled);

    const clearCache = useClearDashboardCache(selectedWorkplaceIds);
    const handleClearCache = () => {
        clearCache.mutate(undefined, {
            onSuccess: () => NotificationHelper.showSuccess(t('Dashboard cache cleared')),
            onError: () => NotificationHelper.showError(t('Failed to clear dashboard cache')),
        });
    };

    const kpiData = kpisQuery.data?.data ?? null;
    const capacityRows = capacityQuery.data?.data?.rows ?? [];
    const heatmapData = heatmapQuery.data?.data ?? null;
    const showUpData = showUpQuery.data?.data ?? null;

    const widgetReliabilityAvailable = useMemo(() => {
        if (kpiData) return kpiData.reliability_available;
        return reliabilityAvailable;
    }, [kpiData, reliabilityAvailable]);

    const noAccessibleWorkplaces = !workplacesQuery.isLoading && workplaceOptions.length === 0;

    return (
        <>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Dashboard')}
                </Title>
            </Flex>

            {noAccessibleWorkplaces ? (
                <Paper p="xl" withBorder ta="center">
                    <Stack gap="xs" align="center">
                        <Title order={5}>{t('No accessible workplaces')}</Title>
                        <Text c="dimmed" size="sm">
                            {t('Ask your administrator to grant shift.list on at least one workplace.')}
                        </Text>
                    </Stack>
                </Paper>
            ) : (
                <Stack gap="md">
                    <Paper p="md">
                        <Flex gap="md" wrap="wrap" align="flex-end" justify="space-between">
                            <Box style={{ flex: '1 1 50%', maxWidth: '50%', minWidth: 280 }}>
                                <WorkplaceMultiSelect
                                    options={workplaceOptions}
                                    value={selectedWorkplaceIds}
                                    onChange={setSelectedWorkplaceIds}
                                />
                            </Box>
                            <Flex gap="sm" align="flex-end">
                                <WindowControl value={windowSelection} onChange={setWindowSelection} />
                                <Button
                                    variant="default"
                                    leftSection={<LuRefreshCw size={16} />}
                                    onClick={handleClearCache}
                                    loading={clearCache.isPending}
                                    disabled={!widgetsEnabled}
                                >
                                    {t('Clear cache')}
                                </Button>
                            </Flex>
                        </Flex>
                    </Paper>

                    {kpisQuery.isError ? (
                        <WidgetError onRetry={() => kpisQuery.refetch()} />
                    ) : (
                        <KpiCardRow
                            data={kpiData}
                            loading={kpisQuery.isLoading}
                            reliabilityAvailable={widgetReliabilityAvailable}
                        />
                    )}

                    <Paper p="md">
                        <Stack gap="sm">
                            <Title order={5}>{t('Daily check-ins')}</Title>
                            {kpisQuery.isError ? (
                                <WidgetError onRetry={() => kpisQuery.refetch()} />
                            ) : (
                                <DailyCheckInsChart
                                    data={kpiData?.daily_check_ins ?? null}
                                    loading={kpisQuery.isLoading}
                                    reliabilityAvailable={widgetReliabilityAvailable}
                                />
                            )}
                        </Stack>
                    </Paper>

                    <Paper p="md">
                        <Stack gap="sm">
                            <Title order={5}>{t('Capacity — next 14 days')}</Title>
                            {capacityQuery.isError ? (
                                <WidgetError onRetry={() => capacityQuery.refetch()} />
                            ) : (
                                <CapacityTable
                                    rows={capacityRows}
                                    loading={capacityQuery.isLoading}
                                    reliabilityAvailable={widgetReliabilityAvailable}
                                />
                            )}
                        </Stack>
                    </Paper>

                    <Grid>
                        <Grid.Col span={{ base: 12, lg: 7 }}>
                            <Stack gap="md">
                                <Paper p="md">
                                    <Stack gap="sm">
                                        <Flex justify="space-between" align="center" wrap="wrap" gap="sm">
                                            <Title order={5}>{t('Demand heatmap')}</Title>
                                            <SegmentedControl
                                                size="xs"
                                                value={heatmapMode}
                                                onChange={(v) => setHeatmapMode(v as HeatmapMode)}
                                                data={[
                                                    { value: 'groups', label: t('Groups') },
                                                    { value: 'top_slots', label: t('Top slots') },
                                                ]}
                                            />
                                        </Flex>
                                        {heatmapQuery.isError ? (
                                            <WidgetError onRetry={() => heatmapQuery.refetch()} />
                                        ) : (
                                            <DemandHeatmap
                                                data={heatmapData}
                                                loading={heatmapQuery.isLoading}
                                                reliabilityAvailable={widgetReliabilityAvailable}
                                            />
                                        )}
                                    </Stack>
                                </Paper>
                                <ForecastEntryCard />
                            </Stack>
                        </Grid.Col>
                        <Grid.Col span={{ base: 12, lg: 5 }}>
                            <Paper p="md" h="100%">
                                <Stack gap="md">
                                    <Title order={5}>{t('Show-up rate')}</Title>
                                    {showUpQuery.isError ? (
                                        <WidgetError onRetry={() => showUpQuery.refetch()} />
                                    ) : (
                                        <>
                                            {widgetReliabilityAvailable && (
                                                <ShiftTimeGroupChart
                                                    rows={showUpData?.by_shift_time_group ?? null}
                                                    loading={showUpQuery.isLoading}
                                                />
                                            )}
                                            <ShowUpRateWidget
                                                data={showUpData}
                                                loading={showUpQuery.isLoading}
                                                reliabilityAvailable={widgetReliabilityAvailable}
                                            />
                                        </>
                                    )}
                                </Stack>
                            </Paper>
                        </Grid.Col>
                    </Grid>
                </Stack>
            )}
        </>
    );
};

export default IndexPage;
