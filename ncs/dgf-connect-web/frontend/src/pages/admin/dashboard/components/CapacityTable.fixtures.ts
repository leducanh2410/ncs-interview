import { CapacityRow } from '@/pages/admin/dashboard/types';

const baseDate = new Date();
const isoDate = (offsetDays: number): string => {
    const d = new Date(baseDate);
    d.setDate(d.getDate() + offsetDays);
    return d.toISOString().slice(0, 10);
};

export const populatedCapacityFixture: CapacityRow[] = [
    {
        date: isoDate(2),
        day_of_week: (baseDate.getDay() + 2) % 7,
        workplace_id: 2,
        workplace_name: 'Kawasaki Pack',
        active_pool: 22,
        assigned: 24,
        free_bench: 0,
        utilization: 1.09,
        capacity_tag: 'critical',
    },
    {
        date: isoDate(3),
        day_of_week: (baseDate.getDay() + 3) % 7,
        workplace_id: 1,
        workplace_name: 'Yokohama A',
        active_pool: 40,
        assigned: 38,
        free_bench: 2,
        utilization: 0.95,
        capacity_tag: 'critical',
    },
    {
        date: isoDate(4),
        day_of_week: (baseDate.getDay() + 4) % 7,
        workplace_id: 3,
        workplace_name: 'Tokyo Hub',
        active_pool: 60,
        assigned: 48,
        free_bench: 12,
        utilization: 0.8,
        capacity_tag: 'tight',
    },
    {
        date: isoDate(6),
        day_of_week: (baseDate.getDay() + 6) % 7,
        workplace_id: 1,
        workplace_name: 'Yokohama A',
        active_pool: 40,
        assigned: 24,
        free_bench: 16,
        utilization: 0.6,
        capacity_tag: 'healthy',
    },
    {
        date: isoDate(10),
        day_of_week: (baseDate.getDay() + 10) % 7,
        workplace_id: 2,
        workplace_name: 'Kawasaki Pack',
        active_pool: 22,
        assigned: 18,
        free_bench: 4,
        utilization: 0.82,
        capacity_tag: 'tight',
    },
    {
        date: isoDate(12),
        day_of_week: (baseDate.getDay() + 12) % 7,
        workplace_id: 4,
        workplace_name: 'Shibuya Sort',
        active_pool: 0,
        assigned: 6,
        free_bench: 0,
        utilization: null,
        capacity_tag: 'critical',
        capacity_reason: 'no_pool',
    },
];

export const emptyCapacityFixture: CapacityRow[] = [];

export const redactedCapacityFixture: CapacityRow[] = populatedCapacityFixture.map((row) => ({
    ...row,
    active_pool: null,
    free_bench: null,
    utilization: null,
    capacity_tag: null,
    capacity_reason: null,
}));
