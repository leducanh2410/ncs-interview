import { KpiResponse } from '@/pages/admin/dashboard/types';

const sampleSeries = (length: number, base: number, variance: number): number[] =>
    Array.from({ length }, (_, i) => Math.max(0, Math.round(base + Math.sin(i / 4) * variance)));

const samplePercentSeries = (length: number, base: number, variance: number): number[] =>
    Array.from({ length }, (_, i) =>
        Math.max(0, Math.min(100, Number((base + Math.sin(i / 4) * variance).toFixed(1)))),
    );

const FIXTURE_DAYS = 30;
const startDate = new Date('2026-04-22');
const fixtureDates = Array.from({ length: FIXTURE_DAYS }, (_, i) => {
    const d = new Date(startDate);
    d.setDate(d.getDate() + i);
    return d.toISOString().slice(0, 10);
});

const fixtureWorkplaces = [
    { id: 1, name: 'Yokohama A', is_other: false },
    { id: 2, name: 'Kawasaki Pack', is_other: false },
];

export const populatedKpiFixture: KpiResponse = {
    active_pool: {
        value: 6810,
        delta: -120,
        series: sampleSeries(FIXTURE_DAYS, 240, 25),
    },
    show_up_rate: {
        value: 96.8,
        delta: 1.2,
        series: samplePercentSeries(FIXTURE_DAYS, 95, 3),
    },
    avg_daily_check_ins: {
        value: 1342,
        delta: 84,
        series: sampleSeries(FIXTURE_DAYS, 1340, 80),
    },
    no_show_risk: {
        value: 4.2,
        delta: -1.5,
        series: samplePercentSeries(FIXTURE_DAYS, 4, 1.5),
    },
    daily_check_ins: {
        workplaces: fixtureWorkplaces,
        rows: fixtureDates.map((date, i) => {
            const a = Math.round(540 + Math.sin(i / 5) * 60);
            const b = Math.round(800 + Math.cos(i / 5) * 80);
            return {
                date,
                total: a + b,
                by_workplace: { '1': a, '2': b },
            };
        }),
    },
    reliability_available: true,
};

export const emptyKpiFixture: KpiResponse = {
    active_pool: { value: null, delta: null, series: [] },
    show_up_rate: { value: null, delta: null, series: [] },
    avg_daily_check_ins: { value: null, delta: null, series: [] },
    no_show_risk: { value: null, delta: null, series: [] },
    daily_check_ins: { workplaces: [], rows: [] },
    reliability_available: true,
};

export const redactedKpiFixture: KpiResponse = {
    active_pool: { value: null, delta: null, series: [] },
    show_up_rate: { value: null, delta: null, series: [] },
    avg_daily_check_ins: { value: null, delta: null, series: [] },
    no_show_risk: { value: null, delta: null, series: [] },
    daily_check_ins: { workplaces: [], rows: [] },
    reliability_available: false,
};
