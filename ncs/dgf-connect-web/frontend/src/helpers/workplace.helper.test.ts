import type { TFunction } from 'i18next';
import { describe, expect, it } from 'vitest';
import { WorkplaceSimple } from '@/types/models/workplace';
import { WorkplaceAccess } from '@/pages/global.state';
import WorkplaceHelper from './workplace.helper';

/** Mimics i18next interpolation so labels can be asserted verbatim. */
const t = ((key: string, params?: Record<string, unknown>) => {
    if (!params) return key;
    return Object.entries(params).reduce((acc, [name, value]) => acc.replaceAll(`{{${name}}}`, String(value)), key);
}) as unknown as TFunction;

const workplaces: WorkplaceSimple[] = [
    { id: 1, name: 'Yokohama A', is_test: false },
    { id: 2, name: 'Kawasaki Pack', is_test: false },
    { id: 3, name: 'Sandbox', is_test: true },
];

const access = (overrides: Partial<WorkplaceAccess> = {}): WorkplaceAccess => ({
    shift: null,
    shift_check_in: null,
    work_history: null,
    work_report: null,
    check_in: null,
    workplace: null,
    fixed_schedule: null,
    fixed_schedule_change_config: null,
    fixed_schedule_change_request: null,
    ...overrides,
});

describe('WorkplaceHelper.filterWorkplacesByAccess', () => {
    it('returns every workplace when the permission group grants global access', () => {
        const result = WorkplaceHelper.filterWorkplacesByAccess(workplaces, access({ shift: null }), 'shift');

        expect(result).toEqual(workplaces);
    });

    it('returns no workplace when the permission group grants no access', () => {
        const result = WorkplaceHelper.filterWorkplacesByAccess(workplaces, access({ shift: [] }), 'shift');

        expect(result).toEqual([]);
    });

    it('keeps only the workplaces listed for the permission group', () => {
        const result = WorkplaceHelper.filterWorkplacesByAccess(workplaces, access({ shift: [1, 3] }), 'shift');

        expect(result.map((workplace) => workplace.id)).toEqual([1, 3]);
    });

    it('ignores accessible ids that match no workplace', () => {
        const result = WorkplaceHelper.filterWorkplacesByAccess(workplaces, access({ shift: [99] }), 'shift');

        expect(result).toEqual([]);
    });

    it('reads the access list of the requested permission group only', () => {
        const workplaceAccess = access({ shift: [1], check_in: [2] });

        expect(WorkplaceHelper.filterWorkplacesByAccess(workplaces, workplaceAccess, 'check_in')).toEqual([
            workplaces[1],
        ]);
    });

    it('filters shift_check_in independently of the broader shift group', () => {
        const workplaceAccess = access({ shift: [1, 2], shift_check_in: [1] });

        expect(
            WorkplaceHelper.filterWorkplacesByAccess(workplaces, workplaceAccess, 'shift_check_in').map(
                (workplace) => workplace.id,
            ),
        ).toEqual([1]);
    });

    it('does not mutate the workplaces it was given', () => {
        const input = [...workplaces];

        WorkplaceHelper.filterWorkplacesByAccess(input, access({ shift: [1] }), 'shift');

        expect(input).toEqual(workplaces);
    });
});

describe('WorkplaceHelper.formatLabel', () => {
    it('returns the plain name for a normal workplace', () => {
        expect(WorkplaceHelper.formatLabel({ id: 1, name: 'Yokohama A', is_test: false }, t)).toBe('Yokohama A');
    });

    it('prefixes a test workplace with the test marker', () => {
        expect(WorkplaceHelper.formatLabel({ id: 3, name: 'Sandbox', is_test: true }, t)).toBe('[Test] Sandbox');
    });

    it('treats a missing is_test flag as a normal workplace', () => {
        expect(WorkplaceHelper.formatLabel({ id: 1, name: 'Yokohama A' }, t)).toBe('Yokohama A');
    });
});

describe('WorkplaceHelper.toSelectOptions', () => {
    it('maps workplaces to string values with formatted labels', () => {
        expect(WorkplaceHelper.toSelectOptions(workplaces, t)).toEqual([
            { value: '1', label: 'Yokohama A' },
            { value: '2', label: 'Kawasaki Pack' },
            { value: '3', label: '[Test] Sandbox' },
        ]);
    });

    it('preserves the order of the input', () => {
        const reversed = [...workplaces].reverse();

        expect(WorkplaceHelper.toSelectOptions(reversed, t).map((option) => option.value)).toEqual(['3', '2', '1']);
    });

    it('returns an empty list for no workplaces', () => {
        expect(WorkplaceHelper.toSelectOptions([], t)).toEqual([]);
    });
});
