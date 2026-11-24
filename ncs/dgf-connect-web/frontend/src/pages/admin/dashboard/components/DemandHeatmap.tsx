import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Box, Skeleton, Stack, Text } from '@mantine/core';
import ReliabilityRedactedHint from '@/pages/admin/dashboard/components/ReliabilityRedactedHint';
import { HeatmapResponse } from '@/pages/admin/dashboard/types';
import classes from '@/pages/admin/dashboard/components/DemandHeatmap.module.css';

interface Props {
    data: HeatmapResponse | null;
    loading?: boolean;
    reliabilityAvailable?: boolean;
}

const DAY_KEYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as const;

/**
 * Six-bucket quantile classifier. Returns 'h1'..'h6' for the supplied value
 * relative to all visible non-null cell values, or null for missing data.
 */
export const bucketForValue = (
    value: number | null,
    sortedAscNonNull: number[],
): 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6' | null => {
    if (value === null || value === undefined) return null;
    if (sortedAscNonNull.length === 0) return 'h1';

    const n = sortedAscNonNull.length;
    let lowerCount = 0;
    while (lowerCount < n && sortedAscNonNull[lowerCount] <= value) {
        lowerCount += 1;
    }
    const rank = (lowerCount - 1) / Math.max(1, n - 1);
    const bucketIndex = Math.min(5, Math.max(0, Math.floor(rank * 6)));
    return (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as const)[bucketIndex];
};

const DemandHeatmap: React.FC<Props> = ({ data, loading = false, reliabilityAvailable = true }) => {
    const { t } = useTranslation();

    const sortedValues = useMemo(() => {
        if (!data) return [];
        const all: number[] = [];
        data.slots.forEach((slot) => {
            const row = data.cells[slot] ?? [];
            row.forEach((v) => {
                if (v !== null && v !== undefined) all.push(v);
            });
        });
        return all.sort((a, b) => a - b);
    }, [data]);

    if (!reliabilityAvailable) {
        return <ReliabilityRedactedHint />;
    }

    if (loading || !data) {
        return (
            <Stack gap="xs">
                {Array.from({ length: 5 }).map((_, idx) => (
                    <Skeleton key={idx} h={32} />
                ))}
            </Stack>
        );
    }

    if (data.slots.length === 0) {
        return (
            <Box py="xl" ta="center">
                <Text c="dimmed">{t('No demand data for the selected workplaces.')}</Text>
            </Box>
        );
    }

    return (
        <Box className={classes.heatmapGrid} style={{ gridTemplateColumns: '160px repeat(7, 1fr)' }}>
            <div />
            {DAY_KEYS.map((day) => (
                <div key={day} className={classes.axisLabel}>
                    {t(day)}
                </div>
            ))}

            {data.slots.map((slot) => {
                const row = data.cells[slot] ?? [];
                const label = data.mode === 'groups' ? t(slot) : slot;
                return (
                    <React.Fragment key={slot}>
                        <div className={classes.rowLabel}>{label}</div>
                        {Array.from({ length: 7 }).map((_, dayIndex) => {
                            const value = row[dayIndex] ?? null;
                            const bucket = bucketForValue(value, sortedValues);
                            const cellClassName = `${classes.cell} ${bucket ? classes[bucket] : classes.cellNull}`;
                            return (
                                <div
                                    key={`${slot}-${dayIndex}`}
                                    className={cellClassName}
                                    title={
                                        value === null
                                            ? t('No data')
                                            : t('{{count}} check-ins on avg', { count: value })
                                    }
                                >
                                    {value === null ? '' : value}
                                </div>
                            );
                        })}
                    </React.Fragment>
                );
            })}
        </Box>
    );
};

export default DemandHeatmap;
