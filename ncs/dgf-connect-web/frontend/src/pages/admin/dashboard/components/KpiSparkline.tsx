import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { AreaChart } from '@mantine/charts';
import dayjs from 'dayjs';
import { getResponsiveChartAttributes } from '@/pages/admin/dashboard/components/chartAttributes';

interface Props {
    /** Daily values aligned with the selected range (oldest-first). */
    series: number[];
    /**
     * Date strings (YYYY-MM-DD) aligned 1:1 with `series`. When provided,
     * the tooltip header shows the formatted date instead of the index.
     */
    dates?: string[];
    color: string;
    /**
     * How to format the tooltip value (e.g. percent vs integer count).
     */
    valueFormatter?: (value: number) => string;
    /**
     * Height of the chart. Defaults to 36 to keep KPI cards compact.
     */
    h?: number;
}

/**
 * A miniature area chart used as a "sparkline" inside KPI cards. Mantine's
 * built-in `<Sparkline>` does not support tooltips, so we render an
 * `<AreaChart>` with all axes/gridlines/dots hidden but `withTooltip` on, so
 * users can hover to read the daily value.
 */
const KpiSparkline: React.FC<Props> = ({ series, dates, color, valueFormatter, h = 36 }) => {
    const { t } = useTranslation();

    const data = useMemo(
        () =>
            series.map((value, idx) => ({
                date: dates?.[idx] ?? String(idx),
                value,
            })),
        [series, dates],
    );

    if (!series || series.length < 2) return null;

    return (
        <AreaChart
            h={h}
            data={data}
            dataKey="date"
            series={[{ name: 'value', color }]}
            curveType="monotone"
            withDots={false}
            withGradient
            fillOpacity={0.25}
            strokeWidth={1.5}
            gridAxis="none"
            tickLine="none"
            withXAxis={false}
            withYAxis={false}
            withTooltip
            tooltipAnimationDuration={0}
            tooltipProps={{
                cursor: { strokeDasharray: '3 3' },
                labelFormatter: (label) => {
                    if (!dates || typeof label !== 'string') return label;
                    const parsed = dayjs(label);
                    return parsed.isValid() ? parsed.format(t('FORMAT_DATE_SHORT')) : label;
                },
            }}
            valueFormatter={valueFormatter}
            areaChartProps={{ margin: { top: 2, right: 2, bottom: 2, left: 2 } }}
            attributes={getResponsiveChartAttributes(h)}
        />
    );
};

export default KpiSparkline;
