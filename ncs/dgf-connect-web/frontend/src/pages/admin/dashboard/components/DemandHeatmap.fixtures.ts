import { HeatmapResponse } from '@/pages/admin/dashboard/types';

export const populatedHeatmapFixture: HeatmapResponse = {
    mode: 'groups',
    slots: ['Early Morning', 'Morning', 'Midday', 'Afternoon', 'Evening', 'Night'],
    cells: {
        'Early Morning': [4, 5, 5, 6, 7, 3, null],
        'Morning': [12, 14, 13, 15, 16, 9, 4],
        'Midday': [8, 10, 11, 12, 13, 6, 2],
        'Afternoon': [10, 11, 12, 14, 14, 7, 3],
        'Evening': [6, 7, 7, 8, 9, 4, null],
        'Night': [2, 3, 2, 3, 4, 1, null],
    },
};

export const populatedTopSlotsHeatmapFixture: HeatmapResponse = {
    mode: 'top_slots',
    slots: ['06:00–14:00', '08:00–17:00', '09:00–18:00', '10:00–19:00', '13:00–22:00', '14:00–23:00'],
    cells: {
        '06:00–14:00': [10, 12, 11, 14, 13, 6, 2],
        '08:00–17:00': [9, 11, 12, 12, 14, 5, 1],
        '09:00–18:00': [8, 10, 10, 11, 12, 4, 1],
        '10:00–19:00': [6, 7, 8, 9, 10, 3, null],
        '13:00–22:00': [4, 5, 5, 6, 7, 2, null],
        '14:00–23:00': [3, 4, 4, 5, 6, 2, null],
    },
};

export const emptyHeatmapFixture: HeatmapResponse = {
    mode: 'groups',
    slots: [],
    cells: {},
};
