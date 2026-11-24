import dayjs, { Dayjs } from 'dayjs';
import type { TFunction } from 'i18next';
import { getPaperIncompleteWarning } from '@/enums/user_paper_status.enum';
import { ApiMember, ApiPosition, ApiWorkplaceShift, DraftRowPayload, ShiftPlanningResponse } from './api';

// ---------------------------------------------------------------------------
// Shift Planner view-model + projection. The grid renders the projection of live
// ShiftUser rows + shift_plan_drafts rows (requirements §3.4). Draft/live status is
// per chip; there is no date/column mode.
// ---------------------------------------------------------------------------

export type WorktimeGroup = 'morning' | 'afternoon' | 'night';

export const WORKTIME_GROUPS: WorktimeGroup[] = ['morning', 'afternoon', 'night'];

export type EmployeeFilters = {
    search: string;
    worktimeGroups: WorktimeGroup[];
    fixedScheduleOnly: boolean;
};

export const EMPTY_EMPLOYEE_FILTERS: EmployeeFilters = {
    search: '',
    worktimeGroups: [],
    fixedScheduleOnly: false,
};

export type PlanFixedSchedule = { weekday: number; start: string; end: string; positionId?: number | null };

export type PlanEmployee = {
    id: number;
    name: string;
    staffNo: string;
    hasFixedSchedule: boolean;
    worktimeGroup: WorktimeGroup;
    fixedSchedules: PlanFixedSchedule[];
    absenceCount: number;
    paperRejected: boolean;
    paperStatus: string | null;
    expiringCards: string[];
    // Approved shifts worked on this workplace over the trailing activity window.
    recentShiftCount: number;
};

export type PlanShiftType = { id: number; label: string; startTime: string; endTime: string; isActive: boolean };

export type PlanPosition = { id: number; name: string; isActive: boolean };

// A chip is either a live ShiftUser row or a shift_plan_drafts row. Live chips can
// carry a draft overlay: `update` means staged edit, `remove` means planned removal.
export type PlanChip = {
    id: string;
    shiftTypeId: number;
    positionId: number | null;
    isFixed: boolean;
    source: 'live' | 'draft';
    action?: 'add' | 'update' | 'remove';
    confirmed?: boolean;
    liveId?: number;
    draftId?: number;
    originalShiftTypeId?: number;
    originalPositionId?: number | null;
    originalIsFixed?: boolean;
    targetFixedTotal?: number | null;
    targetTempTotal?: number | null;
};

export type PlanColumn = { date: string; day: Dayjs; isWeekend: boolean; monthLabel: string };

export const cellKey = (employeeId: number, date: string) => `${employeeId}|${date}`;

// ---- copy / paste ----------------------------------------------------------
// A chip stripped to the fields needed to recreate it as a fresh draft. Live
// identity (liveId/draftId/action) is intentionally dropped: everything pasted
// becomes a new `source: 'draft'` add.
export type ChipTemplate = Pick<
    PlanChip,
    'shiftTypeId' | 'positionId' | 'isFixed' | 'targetFixedTotal' | 'targetTempTotal'
>;

// Single source of truth for creating a draft `add` chip (used by both the cell
// editor's add flow and paste).
export const newDraftChip = (t: ChipTemplate): PlanChip => ({
    id: `new-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
    shiftTypeId: t.shiftTypeId,
    positionId: t.positionId,
    isFixed: t.isFixed,
    targetFixedTotal: t.targetFixedTotal ?? null,
    targetTempTotal: t.targetTempTotal ?? null,
    source: 'draft',
    action: 'add',
});

export const chipToTemplate = (chip: PlanChip): ChipTemplate => ({
    shiftTypeId: chip.shiftTypeId,
    positionId: chip.positionId,
    isFixed: chip.isFixed,
    targetFixedTotal: chip.targetFixedTotal ?? null,
    targetTempTotal: chip.targetTempTotal ?? null,
});

// Chips that actually occupy the cell: live rows staged for removal don't count.
export const effectiveChips = (chips: PlanChip[]): PlanChip[] =>
    chips.filter((c) => !(c.source === 'live' && c.action === 'remove'));

export const isCellOccupied = (chips: PlanChip[] | undefined): boolean => effectiveChips(chips ?? []).length > 0;

// A cell is finalizable when it holds saved draft changes Finalize would commit: a draft add
// or a live row with a staged update. Removals take effect at Save, not Finalize.
export const isCellFinalizable = (chips: PlanChip[] | undefined): boolean =>
    (chips ?? []).some((c) => !!c.draftId && (c.source === 'draft' || (c.source === 'live' && c.action === 'update')));

// Classify a current chip against its last-saved baseline counterpart (matched by id).
// The Save diff and the "unsaved" dot share this classification of a chip versus its
// last-saved baseline. Mirrors save_confirm_modal's DraftDiffKind.
export type DraftChangeKind = 'add' | 'remove' | 'edit';

// Returns the change kind, or null when the chip matches the baseline. Draft chips that
// were deleted since the baseline have no current chip and are handled separately by the caller.
//
// The reverted-draft case is the subtle one: a live row whose saved draft (update/removal)
// is dialled back to the original live value ends up with `action: undefined`. The chip then
// looks identical to a clean live row, but a stale draft row still exists on the server. We
// flag it as changed so Save stays enabled and its full-state payload reconciles the draft away.
export const classifyChipChange = (chip: PlanChip, prev: PlanChip | undefined): DraftChangeKind | null => {
    if (chip.source === 'live') {
        if (chip.action === 'remove' && prev?.action !== 'remove') return 'remove';
        if (chip.action === 'update') return 'edit';
        if (!chip.action && (prev?.action === 'update' || prev?.action === 'remove')) return 'edit';
        return null;
    }
    if (!prev) return 'add';
    if (prev.shiftTypeId !== chip.shiftTypeId || prev.positionId !== chip.positionId || prev.isFixed !== chip.isFixed) {
        return 'edit';
    }
    return null;
};

// Clipboard geometry: each copied cell carries its offset from the block's
// top-left, so paste can replay the same shape anchored anywhere.
export type ClipCell = { dr: number; dc: number; chips: ChipTemplate[] };
export type Clipboard = { width: number; height: number; cells: ClipCell[] };

export type PasteTarget = { row: number; col: number; chips: ChipTemplate[] };

// Place the clipboard block with its top-left at (anchorRow, anchorCol). Returns
// `fits: false` (and no targets) when the block runs past the grid bounds; the
// caller blocks the whole paste in that case. `colCount`/`rowCount` are the
// counts of pasteable date columns / employee rows (excluding the employee col).
export const resolvePasteTargets = (
    clip: Clipboard,
    anchorRow: number,
    anchorCol: number,
    rowCount: number,
    colCount: number,
): { targets: PasteTarget[]; fits: boolean } => {
    if (anchorRow + clip.height > rowCount || anchorCol + clip.width > colCount) {
        return { targets: [], fits: false };
    }
    const targets = clip.cells.map((c) => ({
        row: anchorRow + c.dr,
        col: anchorCol + c.dc,
        chips: c.chips,
    }));
    return { targets, fits: true };
};

// Glide's selection rect, in grid coordinates (col 0 = employee column).
export type ClipSelection = { x: number; y: number; width: number; height: number };

// One cell of the resolved copy block: `dr`/`dc` are offsets within the block,
// `row` is the employee-row index and `col` is the 0-based date-column index
// (grid col − 1) the caller reads chips from.
export type ClipPlanCell = { dr: number; dc: number; row: number; col: number };
export type ClipPlan = { width: number; height: number; cells: ClipPlanCell[] };

// Resolve the rectangular selection into the copy block. `range` is clamped to
// date columns (col 0 = employee), so a selection touching the employee column
// still copies only its date cells. It is also clamped to the live grid bounds
// (`rowCount` employee rows, `colCount` date columns): a stale selection — e.g.
// the user selected a range then changed a filter/sort that shrank the grid —
// must not emit row/col indices the caller can't dereference. Returns null when
// nothing copyable is left. Pure geometry: the caller maps each cell to its chips.
export const resolveCopyPlan = (
    range: ClipSelection | undefined,
    rowCount = Infinity,
    colCount = Infinity,
): ClipPlan | null => {
    if (!range) return null;
    const startCol = Math.max(1, range.x);
    // Date columns occupy grid cols 1..colCount (grid col − 1 = date index).
    const endCol = Math.min(range.x + range.width - 1, colCount);
    if (endCol < startCol) return null;
    const startRow = range.y;
    const endRow = Math.min(range.y + range.height, rowCount); // exclusive
    if (startRow >= endRow) return null;
    const width = endCol - startCol + 1;
    const height = endRow - startRow;
    const cells: ClipPlanCell[] = [];
    for (let row = startRow; row < endRow; row++) {
        for (let col = startCol; col <= endCol; col++) {
            cells.push({ dr: row - startRow, dc: col - startCol, row, col: col - 1 });
        }
    }
    return { width, height, cells };
};

// ---- shift-type / position registries -------------------------------------
// The canvas grid draws synchronously from these lookups; index.page populates them
// from the GET payload before the grid renders.
let shiftTypeRegistry: Record<number, PlanShiftType> = {};
let positionRegistry: Record<number, PlanPosition> = {};

export const setRegistries = (shiftTypes: PlanShiftType[], positions: PlanPosition[]): void => {
    shiftTypeRegistry = Object.fromEntries(shiftTypes.map((s) => [s.id, s]));
    positionRegistry = Object.fromEntries(positions.map((p) => [p.id, p]));
};

export const getShiftTypes = (): PlanShiftType[] => Object.values(shiftTypeRegistry);
export const getActivePositions = (): PlanPosition[] => Object.values(positionRegistry).filter((p) => p.isActive);
export const positionById = (id: number | null): PlanPosition | undefined =>
    id == null ? undefined : positionRegistry[id];

export const shiftTimeLabel = (shiftTypeId: number): string => {
    const s = shiftTypeRegistry[shiftTypeId];
    return s ? `${s.startTime}~${s.endTime}` : '?';
};

export const positionName = (positionId: number | null): string => positionById(positionId)?.name ?? '?';

// ---- display helpers ------------------------------------------------------
const hm = (time: string | null): string => (time ? time.slice(0, 5) : '');

export const weekdayShort = (weekday: number): string => dayjs().day(weekday).format('ddd');

export const fixedScheduleLines = (emp: PlanEmployee): string[] =>
    emp.fixedSchedules.map((fs) => {
        const line = `${weekdayShort(fs.weekday)} ${fs.start}-${fs.end}`;
        const position = positionById(fs.positionId ?? null);
        return position ? `${line} (${position.name})` : line;
    });

// §5.1: an assignment defaults to Fixed when the employee has a matching FixedSchedule
// for the physical weekday + shift-type (same weekday + same start time).
export const matchesFixedSchedule = (emp: PlanEmployee, weekday: number, shiftStartTime: string): boolean =>
    emp.fixedSchedules.some((fs) => fs.weekday === weekday && fs.start === shiftStartTime);

export type PaperWarning = { color: 'red' | 'yellow'; message: string };

export const getPaperWarnings = (emp: PlanEmployee, t: (key: string) => string): PaperWarning[] => {
    const warnings: PaperWarning[] = [];
    if (emp.paperRejected) {
        warnings.push({ color: 'red', message: t('Paper has been rejected') });
    }
    if (emp.expiringCards.length > 0) {
        warnings.push({
            color: 'yellow',
            message: `${emp.expiringCards.join(', ')}: ${t('expired or expiring soon')}`,
        });
    }
    return warnings;
};

export type EmployeeWarning = { color: 'red' | 'yellow'; message: string };

export const employeeWarnings = (emp: PlanEmployee, t: TFunction): EmployeeWarning[] => {
    const out: EmployeeWarning[] = [];
    if (emp.paperRejected) {
        out.push({ color: 'red', message: t('Paper has been rejected') });
    }
    const paperIncompleteWarning = getPaperIncompleteWarning(emp.paperStatus, t);
    if (paperIncompleteWarning) {
        out.push({ color: 'yellow', message: paperIncompleteWarning });
    }
    if (emp.expiringCards.length > 0) {
        out.push({ color: 'yellow', message: `${emp.expiringCards.join(', ')}: ${t('expired or expiring soon')}` });
    }
    if (emp.absenceCount >= 1) {
        out.push({ color: 'yellow', message: t('{{count}} recent absences', { count: emp.absenceCount }) });
    }
    return out;
};

export const worktimeGroupLabel = (group: WorktimeGroup, t: (key: string) => string): string => {
    switch (group) {
        case 'morning':
            return t('Morning');
        case 'afternoon':
            return t('Afternoon');
        case 'night':
            return t('Night');
    }
};

// ---- projection from the GET payload --------------------------------------
export const buildColumns = (from: string, to: string): PlanColumn[] => {
    const cols: PlanColumn[] = [];
    let cursor = dayjs(from);
    const end = dayjs(to);
    while (cursor.isBefore(end) || cursor.isSame(end, 'day')) {
        const dow = cursor.day();
        cols.push({
            date: cursor.format('YYYY-MM-DD'),
            day: cursor,
            isWeekend: dow === 0 || dow === 6,
            monthLabel: cursor.format('YYYY-MM'),
        });
        cursor = cursor.add(1, 'day');
    }
    return cols;
};

export const toShiftTypes = (rows: ApiWorkplaceShift[]): PlanShiftType[] =>
    rows.map((r) => ({
        id: r.id,
        startTime: hm(r.start_time),
        endTime: hm(r.end_time),
        label: `${hm(r.start_time)}~${hm(r.end_time)}`,
        isActive: r.is_active,
    }));

export const toPositions = (rows: ApiPosition[]): PlanPosition[] =>
    rows.map((r) => ({ id: r.id, name: r.name, isActive: r.is_active }));

const deriveWorktimeGroup = (fixedSchedules: PlanFixedSchedule[]): WorktimeGroup => {
    const first = fixedSchedules[0];
    if (!first) return 'morning';
    const hour = Number(first.start.slice(0, 2));
    if (hour < 12) return 'morning';
    if (hour < 18) return 'afternoon';
    return 'night';
};

const expiringCards = (m: ApiMember, t: (key: string) => string): string[] => {
    const out: string[] = [];
    if (m.residence_card_expiry_alert) out.push(t('Residence card'));
    if (m.passport_expiry_alert) out.push(t('Passport'));
    if (m.student_card_expiry_alert) out.push(t('Student card'));
    return out;
};

export const toEmployees = (
    members: ApiMember[],
    shiftTypesById: Record<number, PlanShiftType>,
    t: (key: string) => string,
): PlanEmployee[] =>
    members.map((m) => {
        const fixedSchedules: PlanFixedSchedule[] = m.fixed_schedules.map((fs) => {
            const st = shiftTypesById[fs.workplace_shift_id];
            return {
                weekday: fs.day,
                start: st?.startTime ?? '',
                end: st?.endTime ?? '',
                positionId: fs.workplace_position_id ?? null,
            };
        });
        return {
            id: m.id,
            name: m.name,
            staffNo: m.staff_no ?? '',
            hasFixedSchedule: fixedSchedules.length > 0,
            worktimeGroup: deriveWorktimeGroup(fixedSchedules),
            fixedSchedules,
            absenceCount: m.absence_count,
            paperRejected: m.paper_status === 'rejected',
            paperStatus: m.paper_status,
            expiringCards: expiringCards(m, t),
            recentShiftCount: m.recent_shift_count,
        };
    });

// Projection of live ShiftUser rows + draft rows into per-cell chips.
export const buildCells = (resp: ShiftPlanningResponse): Record<string, PlanChip[]> => {
    const cells: Record<string, PlanChip[]> = {};
    const shiftById: Record<number, { date: string; workplaceShiftId: number; fixedTotal: number; tempTotal: number }> =
        {};
    const shiftByDateType: Record<string, { fixedTotal: number; tempTotal: number }> = {};
    resp.shifts.forEach((s) => {
        const totals = { fixedTotal: s.fixed_total, tempTotal: s.temp_total };
        shiftById[s.id] = { date: s.date, workplaceShiftId: s.workplace_shift_id, ...totals };
        shiftByDateType[`${s.date}|${s.workplace_shift_id}`] = totals;
    });

    const push = (key: string, chip: PlanChip) => {
        cells[key] = [...(cells[key] ?? []), chip];
    };

    resp.live_assignments.forEach((a) => {
        const sh = shiftById[a.shift_id];
        if (!sh) return;
        const positionId = a.workplace_position_id;
        const isFixed = !!a.is_fixed;
        push(cellKey(a.user_id, sh.date), {
            id: `live-${a.id}`,
            shiftTypeId: sh.workplaceShiftId,
            positionId,
            isFixed,
            source: 'live',
            confirmed: !!a.confirmed_at,
            liveId: a.id,
            originalShiftTypeId: sh.workplaceShiftId,
            originalPositionId: positionId,
            originalIsFixed: isFixed,
            targetFixedTotal: sh.fixedTotal,
            targetTempTotal: sh.tempTotal,
        });
    });

    resp.drafts.forEach((d) => {
        const key = cellKey(d.user_id, d.date);
        if (d.action === 'remove') {
            // Overlay a planned removal onto the matching live chip.
            const chip = (cells[key] ?? []).find(
                (c) =>
                    c.source === 'live' &&
                    (d.shift_user_id ? c.liveId === d.shift_user_id : c.shiftTypeId === d.workplace_shift_id),
            );
            if (chip) {
                chip.action = 'remove';
                chip.draftId = d.id;
            }
            return;
        }
        if (d.action === 'update') {
            const liveChip = (cells[key] ?? []).find(
                (c) =>
                    c.source === 'live' &&
                    (d.shift_user_id ? c.liveId === d.shift_user_id : c.shiftTypeId === d.workplace_shift_id),
            );
            if (!liveChip) return;
            const targetTotals = shiftByDateType[`${d.date}|${d.workplace_shift_id}`];
            liveChip.positionId = d.workplace_position_id;
            liveChip.isFixed = !!d.is_fixed;
            liveChip.shiftTypeId = d.workplace_shift_id;
            liveChip.action = 'update';
            liveChip.draftId = d.id;
            liveChip.targetFixedTotal = targetTotals?.fixedTotal ?? null;
            liveChip.targetTempTotal = targetTotals?.tempTotal ?? null;
            return;
        }
        const targetTotals = shiftByDateType[`${d.date}|${d.workplace_shift_id}`];
        push(key, {
            id: `draft-${d.id}`,
            shiftTypeId: d.workplace_shift_id,
            positionId: d.workplace_position_id,
            isFixed: !!d.is_fixed,
            source: 'draft',
            action: 'add',
            draftId: d.id,
            targetFixedTotal: targetTotals?.fixedTotal ?? null,
            targetTempTotal: targetTotals?.tempTotal ?? null,
        });
    });

    return cells;
};

export const concreteShiftKeys = (resp: ShiftPlanningResponse): Set<string> =>
    new Set(resp.shifts.map((s) => `${s.date}|${s.workplace_shift_id}`));

// Key for the header shift-summary maps (per day, per shift type).
export const shiftSlotKey = (date: string, shiftTypeId: number) => `${date}|${shiftTypeId}`;

export type HeaderCount = { fixed: number; temp: number };

// Tally the people currently planned per day + shift type from the in-progress plan, so the
// header summaries reflect edits live. Chips staged for removal don't count; draft `add` and
// live (kept / staged-update) chips do. Keyed by `${date}|${shiftTypeId}`.
export const buildHeaderCounts = (cells: Record<string, PlanChip[]>): Map<string, HeaderCount> => {
    const counts = new Map<string, HeaderCount>();
    Object.entries(cells).forEach(([key, chips]) => {
        const date = key.slice(key.indexOf('|') + 1);
        chips.forEach((chip) => {
            if (chip.action === 'remove') return;
            const slot = shiftSlotKey(date, chip.shiftTypeId);
            const entry = counts.get(slot) ?? { fixed: 0, temp: 0 };
            if (chip.isFixed) entry.fixed += 1;
            else entry.temp += 1;
            counts.set(slot, entry);
        });
    });
    return counts;
};

// Scope a flat list of save validation errors to the cells the admin actually changed this
// session. Capacity / work-hour checks flag every draft contributing to an over-limit slot —
// including drafts persisted in an earlier session — but on save the admin only acted on their
// unsaved cells, so a pre-existing draft they didn't touch shouldn't light up or pad the list. A
// cell is "dirty" when it holds any chip whose id is in `dirtyIds`. Errors without a user_id are
// always kept. Falls back to the full list when nothing lands on a dirty cell (e.g. live state
// shifted under an otherwise-unchanged plan), so a real failure is never silently dropped.
export const scopeErrorsToDirtyCells = <T extends { user_id?: number; date: string }>(
    errors: T[],
    cells: Record<string, PlanChip[]>,
    dirtyIds: Set<string>,
): T[] => {
    const dirtyCells = new Set<string>();
    Object.entries(cells).forEach(([key, chips]) => {
        if (chips.some((c) => dirtyIds.has(c.id))) dirtyCells.add(key);
    });
    const own = errors.filter((e) => e.user_id === undefined || dirtyCells.has(cellKey(e.user_id, e.date)));
    return own.length > 0 ? own : errors;
};

// Partition the workplace shift types into those that have at least one concrete shift in the
// visible window ("created") and those that have none, for the grouped header-shift selector.
export const splitShiftTypesByCreated = (
    shiftTypes: PlanShiftType[],
    resp: ShiftPlanningResponse,
): { created: PlanShiftType[]; notCreated: PlanShiftType[] } => {
    const createdIds = new Set(resp.shifts.map((s) => s.workplace_shift_id));
    const created: PlanShiftType[] = [];
    const notCreated: PlanShiftType[] = [];
    shiftTypes.forEach((s) => (createdIds.has(s.id) ? created : notCreated).push(s));
    return { created, notCreated };
};

// Build the PUT /draft payload from the current cells: every draft `add` chip plus
// every live chip flagged for update/removal. Reconciliation on the backend is scoped to the
// window, so unchanged live rows ("keep") need no row.
export const buildDraftPayload = (cells: Record<string, PlanChip[]>): DraftRowPayload[] => {
    const rows: DraftRowPayload[] = [];
    Object.entries(cells).forEach(([key, chips]) => {
        const [userId, date] = key.split('|');
        chips.forEach((chip) => {
            if (chip.source === 'draft' && chip.action === 'add') {
                rows.push({
                    date,
                    workplace_shift_id: chip.shiftTypeId,
                    user_id: Number(userId),
                    shift_user_id: null,
                    workplace_position_id: chip.positionId,
                    is_fixed: chip.isFixed,
                    action: 'add',
                    target_fixed_total: chip.targetFixedTotal ?? null,
                    target_temp_total: chip.targetTempTotal ?? null,
                });
            } else if (chip.source === 'live' && chip.action === 'update') {
                rows.push({
                    date,
                    workplace_shift_id: chip.shiftTypeId,
                    user_id: Number(userId),
                    shift_user_id: chip.liveId ?? null,
                    workplace_position_id: chip.positionId,
                    is_fixed: chip.isFixed,
                    action: 'update',
                    target_fixed_total: chip.targetFixedTotal ?? null,
                    target_temp_total: chip.targetTempTotal ?? null,
                });
            } else if (chip.source === 'live' && chip.action === 'remove') {
                rows.push({
                    date,
                    workplace_shift_id: chip.originalShiftTypeId ?? chip.shiftTypeId,
                    user_id: Number(userId),
                    shift_user_id: chip.liveId ?? null,
                    workplace_position_id:
                        chip.originalPositionId !== undefined ? chip.originalPositionId : chip.positionId,
                    is_fixed: chip.originalIsFixed ?? chip.isFixed,
                    action: 'remove',
                });
            }
        });
    });
    return rows;
};
