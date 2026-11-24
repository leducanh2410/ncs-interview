import { describe, expect, it } from 'vitest';
import {
    ClipSelection,
    Clipboard,
    PlanChip,
    PlanShiftType,
    buildDraftPayload,
    buildHeaderCounts,
    cellKey,
    chipToTemplate,
    classifyChipChange,
    effectiveChips,
    fixedScheduleLines,
    isCellFinalizable,
    isCellOccupied,
    newDraftChip,
    resolveCopyPlan,
    resolvePasteTargets,
    scopeErrorsToDirtyCells,
    setRegistries,
    shiftSlotKey,
    splitShiftTypesByCreated,
    toEmployees,
} from '@/pages/manage/shift-planning/partials/data';
import type { ShiftPlanningResponse } from '@/pages/manage/shift-planning/partials/api';

const liveChip = (over: Partial<PlanChip> = {}): PlanChip => ({
    id: 'l1',
    shiftTypeId: 10,
    positionId: 2,
    isFixed: false,
    source: 'live',
    ...over,
});

const draftChip = (over: Partial<PlanChip> = {}): PlanChip => ({
    id: 'd1',
    shiftTypeId: 11,
    positionId: null,
    isFixed: true,
    source: 'draft',
    action: 'add',
    ...over,
});

describe('newDraftChip', () => {
    it('creates a draft add chip from a template', () => {
        const chip = newDraftChip({
            shiftTypeId: 5,
            positionId: 3,
            isFixed: true,
            targetFixedTotal: 2,
            targetTempTotal: 4,
        });
        expect(chip).toMatchObject({
            shiftTypeId: 5,
            positionId: 3,
            isFixed: true,
            targetFixedTotal: 2,
            targetTempTotal: 4,
            source: 'draft',
            action: 'add',
        });
        expect(chip.id).toMatch(/^new-/);
    });

    it('generates a fresh id each call', () => {
        const a = newDraftChip({ shiftTypeId: 1, positionId: null, isFixed: false });
        const b = newDraftChip({ shiftTypeId: 1, positionId: null, isFixed: false });
        expect(a.id).not.toBe(b.id);
    });
});

describe('chipToTemplate', () => {
    it('keeps only the recreatable fields', () => {
        expect(
            chipToTemplate(liveChip({ liveId: 99, action: 'update', targetFixedTotal: 2, targetTempTotal: 4 })),
        ).toEqual({
            shiftTypeId: 10,
            positionId: 2,
            isFixed: false,
            targetFixedTotal: 2,
            targetTempTotal: 4,
        });
    });
});

describe('buildDraftPayload', () => {
    it('sends copied target totals for draft adds', () => {
        expect(
            buildDraftPayload({
                '7|2026-06-22': [
                    draftChip({
                        shiftTypeId: 10,
                        targetFixedTotal: 2,
                        targetTempTotal: 4,
                    }),
                ],
            }),
        ).toEqual([
            {
                date: '2026-06-22',
                workplace_shift_id: 10,
                user_id: 7,
                shift_user_id: null,
                workplace_position_id: null,
                is_fixed: true,
                action: 'add',
                target_fixed_total: 2,
                target_temp_total: 4,
            },
        ]);
    });
});

describe('effectiveChips / isCellOccupied / isCellFinalizable', () => {
    it('excludes live chips staged for removal', () => {
        const chips = [liveChip(), liveChip({ id: 'l2', action: 'remove' }), draftChip()];
        expect(effectiveChips(chips).map((c) => c.id)).toEqual(['l1', 'd1']);
    });

    it('reports occupancy from effective chips only', () => {
        expect(isCellOccupied(undefined)).toBe(false);
        expect(isCellOccupied([])).toBe(false);
        expect(isCellOccupied([liveChip({ action: 'remove' })])).toBe(false);
        expect(isCellOccupied([draftChip()])).toBe(true);
        expect(isCellOccupied([liveChip()])).toBe(true);
    });

    it('treats only saved draft adds/updates as finalizable', () => {
        expect(isCellFinalizable(undefined)).toBe(false);
        expect(isCellFinalizable([draftChip()])).toBe(false);
        expect(isCellFinalizable([draftChip({ draftId: 1 })])).toBe(true);
        expect(isCellFinalizable([liveChip({ action: 'update' })])).toBe(false);
        expect(isCellFinalizable([liveChip({ action: 'update', draftId: 2 })])).toBe(true);
        expect(isCellFinalizable([liveChip({ action: 'remove', draftId: 3 })])).toBe(false);
    });
});

describe('resolvePasteTargets', () => {
    const tpl = (shiftTypeId: number) => ({ shiftTypeId, positionId: null, isFixed: false });

    const singleCell: Clipboard = {
        width: 1,
        height: 1,
        cells: [{ dr: 0, dc: 0, chips: [tpl(1)] }],
    };

    it('places a single cell at the anchor', () => {
        const { targets, fits } = resolvePasteTargets(singleCell, 3, 4, 10, 10);
        expect(fits).toBe(true);
        expect(targets).toEqual([{ row: 3, col: 4, chips: [tpl(1)] }]);
    });

    it('replays a rectangular block offset from the anchor', () => {
        const block: Clipboard = {
            width: 2,
            height: 2,
            cells: [
                { dr: 0, dc: 0, chips: [tpl(1)] },
                { dr: 0, dc: 1, chips: [tpl(2)] },
                { dr: 1, dc: 0, chips: [tpl(3)] },
                { dr: 1, dc: 1, chips: [tpl(4)] },
            ],
        };
        const { targets, fits } = resolvePasteTargets(block, 1, 1, 5, 5);
        expect(fits).toBe(true);
        expect(targets).toContainEqual({ row: 2, col: 2, chips: [tpl(4)] });
        expect(targets).toHaveLength(4);
    });

    it('maps a multi-row block across rows', () => {
        const col: Clipboard = {
            width: 1,
            height: 3,
            cells: [
                { dr: 0, dc: 0, chips: [tpl(1)] },
                { dr: 1, dc: 0, chips: [] },
                { dr: 2, dc: 0, chips: [tpl(2)] },
            ],
        };
        const { targets, fits } = resolvePasteTargets(col, 0, 5, 3, 8);
        expect(fits).toBe(true);
        expect(targets.map((t) => t.col)).toEqual([5, 5, 5]);
        expect(targets.map((t) => t.row)).toEqual([0, 1, 2]);
    });

    it('reports a block that runs past the last column', () => {
        const block: Clipboard = {
            width: 3,
            height: 1,
            cells: [{ dr: 0, dc: 0, chips: [] }],
        };
        expect(resolvePasteTargets(block, 0, 5, 10, 7).fits).toBe(false);
    });

    it('reports a block that runs past the last row', () => {
        const { fits } = resolvePasteTargets({ ...singleCell, height: 4 }, 8, 0, 10, 10);
        expect(fits).toBe(false);
    });
});

describe('resolveCopyPlan', () => {
    // Glide selection rect in grid coords (x=0 is the employee column).
    const sel = (x: number, y: number, width: number, height: number): ClipSelection => ({ x, y, width, height });

    it('returns null when nothing is selected', () => {
        expect(resolveCopyPlan(undefined)).toBeNull();
    });

    it('returns null when the selection touches only the employee column', () => {
        expect(resolveCopyPlan(sel(0, 0, 1, 1))).toBeNull();
    });

    it('copies a single selected cell', () => {
        const plan = resolveCopyPlan(sel(3, 2, 1, 1));
        expect(plan).toEqual({
            width: 1,
            height: 1,
            cells: [{ dr: 0, dc: 0, row: 2, col: 2 }],
        });
    });

    it('maps a rectangular block to 0-based date-column indices', () => {
        const plan = resolveCopyPlan(sel(1, 0, 2, 2));
        expect(plan?.cells).toEqual([
            { dr: 0, dc: 0, row: 0, col: 0 },
            { dr: 0, dc: 1, row: 0, col: 1 },
            { dr: 1, dc: 0, row: 1, col: 0 },
            { dr: 1, dc: 1, row: 1, col: 1 },
        ]);
    });

    it('clamps a rect that starts on the employee column', () => {
        const plan = resolveCopyPlan(sel(0, 0, 3, 1));
        // col 0 (employee) is dropped: width 2 over date columns 0 and 1.
        expect(plan).toEqual({
            width: 2,
            height: 1,
            cells: [
                { dr: 0, dc: 0, row: 0, col: 0 },
                { dr: 0, dc: 1, row: 0, col: 1 },
            ],
        });
    });

    it('clamps a stale selection to the current grid bounds', () => {
        // Selection spans 3 rows × 3 date cols, but the grid now has only 2 rows / 2 date cols
        // (e.g. a filter/sort shrank it). The block must not emit out-of-range row/col indices.
        const plan = resolveCopyPlan(sel(1, 0, 3, 3), 2, 2);
        expect(plan).toEqual({
            width: 2,
            height: 2,
            cells: [
                { dr: 0, dc: 0, row: 0, col: 0 },
                { dr: 0, dc: 1, row: 0, col: 1 },
                { dr: 1, dc: 0, row: 1, col: 0 },
                { dr: 1, dc: 1, row: 1, col: 1 },
            ],
        });
    });

    it('returns null when the selection starts past the last date column', () => {
        expect(resolveCopyPlan(sel(5, 0, 2, 1), 10, 3)).toBeNull();
    });

    it('returns null when the selection starts past the last row', () => {
        expect(resolveCopyPlan(sel(1, 8, 1, 2), 5, 10)).toBeNull();
    });
});

describe('classifyChipChange', () => {
    it('flags a fresh draft add', () => {
        expect(classifyChipChange(draftChip(), undefined)).toBe('add');
    });

    it('flags an edited draft against its baseline', () => {
        const prev = draftChip({ shiftTypeId: 11 });
        expect(classifyChipChange(draftChip({ shiftTypeId: 12 }), prev)).toBe('edit');
    });

    it('treats an unchanged saved draft as clean', () => {
        const prev = draftChip();
        expect(classifyChipChange(draftChip(), prev)).toBeNull();
    });

    it('flags a staged live removal the baseline did not carry', () => {
        const prev = liveChip();
        expect(classifyChipChange(liveChip({ action: 'remove' }), prev)).toBe('remove');
    });

    it('treats an already-saved live removal as clean', () => {
        const prev = liveChip({ action: 'remove' });
        expect(classifyChipChange(liveChip({ action: 'remove' }), prev)).toBeNull();
    });

    it('flags a staged live update', () => {
        const prev = liveChip();
        expect(classifyChipChange(liveChip({ action: 'update', shiftTypeId: 99 }), prev)).toBe('edit');
    });

    it('flags a reverted saved draft update so Save can clear the stale row', () => {
        // Baseline holds a saved live update; reverting it back to the live value drops the
        // action but leaves a stale draft on the server, so it must still register as a change.
        const prev = liveChip({ action: 'update', draftId: 7, shiftTypeId: 99 });
        const reverted = liveChip({ action: undefined });
        expect(classifyChipChange(reverted, prev)).toBe('edit');
    });

    it('flags a reverted saved live removal', () => {
        const prev = liveChip({ action: 'remove', draftId: 7 });
        const reverted = liveChip({ action: undefined });
        expect(classifyChipChange(reverted, prev)).toBe('edit');
    });

    it('treats a clean live row with no draft history as unchanged', () => {
        expect(classifyChipChange(liveChip(), liveChip())).toBeNull();
    });
});

const chip = (over: Partial<PlanChip>): PlanChip => ({
    id: Math.random().toString(36),
    shiftTypeId: 1,
    positionId: null,
    isFixed: false,
    source: 'draft',
    ...over,
});

describe('buildHeaderCounts', () => {
    it('tallies fixed and temp per day + shift type, aggregating across employees', () => {
        const cells = {
            '1|2026-07-01': [chip({ shiftTypeId: 1, isFixed: true }), chip({ shiftTypeId: 1, isFixed: false })],
            '2|2026-07-01': [chip({ shiftTypeId: 1, isFixed: true })],
            '3|2026-07-01': [chip({ shiftTypeId: 2, isFixed: false })],
        };
        const counts = buildHeaderCounts(cells);
        expect(counts.get(shiftSlotKey('2026-07-01', 1))).toEqual({ fixed: 2, temp: 1 });
        expect(counts.get(shiftSlotKey('2026-07-01', 2))).toEqual({ fixed: 0, temp: 1 });
    });

    it('counts live and draft-add chips but excludes ones staged for removal', () => {
        const cells = {
            '1|2026-07-01': [
                chip({ shiftTypeId: 1, isFixed: true, source: 'live' }),
                chip({ shiftTypeId: 1, isFixed: true, source: 'live', action: 'remove' }),
                chip({ shiftTypeId: 1, isFixed: false, source: 'draft', action: 'add' }),
            ],
        };
        expect(buildHeaderCounts(cells).get(shiftSlotKey('2026-07-01', 1))).toEqual({ fixed: 1, temp: 1 });
    });

    it('keys by the cell date even for multi-digit employee ids', () => {
        const counts = buildHeaderCounts({ '1234|2026-07-02': [chip({ shiftTypeId: 5, isFixed: true })] });
        expect(counts.get(shiftSlotKey('2026-07-02', 5))).toEqual({ fixed: 1, temp: 0 });
    });

    it('returns an empty map when there are no cells', () => {
        expect(buildHeaderCounts({}).size).toBe(0);
    });
});

describe('splitShiftTypesByCreated', () => {
    const types: PlanShiftType[] = [
        { id: 1, label: '09:00~17:00', startTime: '09:00', endTime: '17:00', isActive: true },
        { id: 2, label: '13:00~21:00', startTime: '13:00', endTime: '21:00', isActive: true },
        { id: 3, label: '22:00~06:00', startTime: '22:00', endTime: '06:00', isActive: false },
    ];

    it('partitions by whether a concrete shift exists in the window, preserving order', () => {
        const resp = {
            shifts: [
                { id: 10, date: '2026-07-01', workplace_shift_id: 1 },
                { id: 11, date: '2026-07-02', workplace_shift_id: 1 },
                { id: 12, date: '2026-07-02', workplace_shift_id: 3 },
            ],
        } as unknown as ShiftPlanningResponse;
        const { created, notCreated } = splitShiftTypesByCreated(types, resp);
        expect(created.map((s) => s.id)).toEqual([1, 3]);
        expect(notCreated.map((s) => s.id)).toEqual([2]);
    });

    it('puts everything in notCreated when no shifts exist', () => {
        const resp = { shifts: [] } as unknown as ShiftPlanningResponse;
        const { created, notCreated } = splitShiftTypesByCreated(types, resp);
        expect(created).toEqual([]);
        expect(notCreated.map((s) => s.id)).toEqual([1, 2, 3]);
    });
});

describe('scopeErrorsToDirtyCells', () => {
    const date = '2026-07-10';

    it('drops errors on cells the admin did not change this session', () => {
        // Over-capacity slot flags both employees, but only employee 2 was added this save.
        const cells = {
            [cellKey(1, date)]: [chip({ id: 'saved-a' })], // persisted earlier — clean
            [cellKey(2, date)]: [chip({ id: 'new-b' })], // just added — dirty
        };
        const errors = [
            { user_id: 1, date, code: 'SCHEDULE_CAPACITY_EXCEEDED' },
            { user_id: 2, date, code: 'SCHEDULE_CAPACITY_EXCEEDED' },
        ];
        expect(scopeErrorsToDirtyCells(errors, cells, new Set(['new-b']))).toEqual([
            { user_id: 2, date, code: 'SCHEDULE_CAPACITY_EXCEEDED' },
        ]);
    });

    it('treats a cell as dirty when any of its chips is unsaved', () => {
        const cells = { [cellKey(1, date)]: [chip({ id: 'clean' }), chip({ id: 'dirty' })] };
        const errors = [{ user_id: 1, date, code: 'X' }];
        expect(scopeErrorsToDirtyCells(errors, cells, new Set(['dirty']))).toEqual(errors);
    });

    it('always keeps errors that have no user_id', () => {
        const errors = [{ date, code: 'GLOBAL' }];
        expect(scopeErrorsToDirtyCells(errors, {}, new Set())).toEqual(errors);
    });

    it('falls back to the full list when nothing lands on a dirty cell', () => {
        // e.g. live state shifted under a plan the admin did not touch — still surface it.
        const cells = { [cellKey(1, date)]: [chip({ id: 'saved-a' })] };
        const errors = [{ user_id: 1, date, code: 'SCHEDULE_CAPACITY_EXCEEDED' }];
        expect(scopeErrorsToDirtyCells(errors, cells, new Set())).toEqual(errors);
    });
});

describe('toEmployees / fixedScheduleLines with positions', () => {
    const shiftTypesById = {
        10: { id: 10, label: '09:00~18:00', startTime: '09:00', endTime: '18:00', isActive: true },
    };
    const member = {
        id: 7,
        staff_no: '001',
        name: 'Sato',
        paper_status: null,
        is_disabled: false,
        student_card_expiry_alert: false,
        residence_card_expiry_alert: false,
        passport_expiry_alert: false,
        absence_count: 0,
        recent_shift_count: 0,
        fixed_schedules: [
            { workplace_shift_id: 10, day: 1, workplace_position_id: 3 },
            { workplace_shift_id: 10, day: 2, workplace_position_id: null },
        ],
    };

    it('carries the fixed schedule position id onto the employee', () => {
        const [emp] = toEmployees([member], shiftTypesById, (k) => k);

        expect(emp.fixedSchedules.map((fs) => fs.positionId)).toEqual([3, null]);
    });

    it('appends the position name to the line only when the position is known', () => {
        setRegistries([], [{ id: 3, name: 'Picking', isActive: true }]);
        const [emp] = toEmployees([member], shiftTypesById, (k) => k);

        const lines = fixedScheduleLines(emp);
        expect(lines[0]).toBe('Mon 09:00-18:00 (Picking)');
        expect(lines[1]).toBe('Tue 09:00-18:00');
    });
});
