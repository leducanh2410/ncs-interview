import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import {
    CompactSelection,
    CustomCell,
    CustomRenderer,
    DataEditor,
    DataEditorRef,
    DrawHeaderCallback,
    GridCell,
    GridCellKind,
    GridColumn,
    GridSelection,
    HeaderClickedEventArgs,
    Item,
    Rectangle,
    Theme,
} from '@glideapps/glide-data-grid';
import '@glideapps/glide-data-grid/dist/index.css';
import { Button, Paper, Stack } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import dayjs from 'dayjs';
import CellEditor from '@/pages/manage/shift-planning/partials/cell_editor';
import {
    ChipTemplate,
    ClipCell,
    Clipboard,
    HeaderCount,
    PlanChip,
    PlanColumn,
    PlanEmployee,
    cellKey,
    chipToTemplate,
    effectiveChips,
    employeeWarnings,
    isCellFinalizable,
    positionById,
    positionName,
    resolveCopyPlan,
    resolvePasteTargets,
    shiftSlotKey,
    shiftTimeLabel,
    worktimeGroupLabel,
} from '@/pages/manage/shift-planning/partials/data';
import EmployeeInfo from '@/pages/manage/shift-planning/partials/employee_info';
import {
    CHIP_HEIGHT,
    DATE_COL_WIDTH,
    EMP_COL_WIDTH,
    GRID_GROUP_HEADER_HEIGHT,
    GRID_HEIGHT,
    HEADER_DATE_ZONE,
    ROW_HEIGHT,
    SHIFT_ROW_H,
    headerHeightFor,
} from '@/pages/manage/shift-planning/partials/grid_layout';
import { ApiConcreteShift } from '@/pages/manage/shift-planning/partials/api';

// Canvas needs concrete colors (CSS vars don't resolve in fillStyle).
// Colour follows the shift-detail page: green = fixed, blue = temp.
const FIXED_HEX = '#12b886'; // teal-6
const TEMP_HEX = '#228be6'; // blue-6

const COLORS = {
    weekend: '#f8f9fa',
    error: '#fff5f5',
    headerWeekend: '#f1f3f5',
    staff: '#868e96',
    meta: '#495057',
    regularDot: '#12b886',
    errorBorder: '#fa5252',
    warnRed: '#fa5252',
    warnYellow: '#f59f00',
    plus: '#ced4da',
    chipDraftBg: '#ffffff',
    unsaved: '#f76707', // orange-7 — corner dot for chips with unsaved changes
};

// ---- custom cell payloads -------------------------------------------------
type EmployeeCellData = {
    kind: 'sp-cell';
    variant: 'employee';
    employee: PlanEmployee;
    worktimeLabel: string;
    shiftCountLabel: string;
    warningColors: string[];
};
type PlanCellData = { kind: 'sp-cell'; variant: 'plan'; chips: PlanChip[]; error?: string; dirtyIds: Set<string> };
type SPCellData = EmployeeCellData | PlanCellData;
type SPCell = CustomCell<SPCellData>;

// ---- canvas helpers -------------------------------------------------------
const roundRect = (ctx: CanvasRenderingContext2D, x: number, y: number, w: number, h: number, r: number) => {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
};

const ellipsize = (ctx: CanvasRenderingContext2D, text: string, maxW: number) => {
    if (maxW <= 0) return '';
    if (ctx.measureText(text).width <= maxW) return text;
    let t = text;
    while (t.length > 1 && ctx.measureText(`${t}…`).width > maxW) t = t.slice(0, -1);
    return `${t}…`;
};

// Small warning triangle with a "!" — mirrors the LuTriangleAlert icons used in
// the shift add-user modal (red = paper rejected, yellow = expiring / absence).
const drawWarnTriangle = (ctx: CanvasRenderingContext2D, cx: number, cy: number, half: number, color: string) => {
    ctx.beginPath();
    ctx.moveTo(cx, cy - half);
    ctx.lineTo(cx + half, cy + half);
    ctx.lineTo(cx - half, cy + half);
    ctx.closePath();
    ctx.fillStyle = color;
    ctx.fill();
    ctx.fillStyle = '#fff';
    ctx.font = `700 ${Math.round(half * 1.2)}px sans-serif`;
    ctx.textAlign = 'center';
    ctx.fillText('!', cx, cy + half * 0.55);
    ctx.textAlign = 'start';
};

const chipText = (chip: PlanChip) =>
    `${shiftTimeLabel(chip.shiftTypeId)} · ${positionName(chip.positionId)} · ${chip.isFixed ? 'Fixed' : 'Temp'}`;

const drawEmployee = (ctx: CanvasRenderingContext2D, rect: Rectangle, theme: Theme, data: EmployeeCellData) => {
    const { employee: emp } = data;
    const x = rect.x + theme.cellHorizontalPadding;
    const right = rect.x + rect.width - theme.cellHorizontalPadding;
    // Two-line block (name / staff·worktime), vertically centred in the row.
    // The weekday fixed schedule lives in the cell-editor popup, not here.
    const nameY = rect.y + rect.height / 2 - 4;
    const metaY = rect.y + rect.height / 2 + 13;
    ctx.textBaseline = 'alphabetic';

    // Warning triangles, packed from the right edge of the name line.
    let warnLeft = right;
    data.warningColors.forEach((color) => {
        drawWarnTriangle(ctx, warnLeft - 6, nameY - 5, 6, color);
        warnLeft -= 17;
    });

    // Name (+ fixed-schedule dot).
    let nameX = x;
    if (emp.hasFixedSchedule) {
        ctx.beginPath();
        ctx.fillStyle = COLORS.regularDot;
        ctx.arc(x + 4, nameY - 4, 4, 0, Math.PI * 2);
        ctx.fill();
        nameX = x + 14;
    }
    ctx.fillStyle = theme.textDark;
    ctx.font = `600 13px ${theme.fontFamily}`;
    ctx.fillText(ellipsize(ctx, emp.name, warnLeft - nameX - 6), nameX, nameY);

    // Shift total for the visible range, right-aligned on the meta line.
    ctx.font = `600 11px ${theme.fontFamily}`;
    const countW = ctx.measureText(data.shiftCountLabel).width;
    ctx.fillStyle = COLORS.staff;
    ctx.textAlign = 'right';
    ctx.fillText(data.shiftCountLabel, right, metaY);
    ctx.textAlign = 'start';

    // Staff no · worktime group, ellipsized to leave room for the count.
    ctx.fillStyle = COLORS.meta;
    ctx.font = `11px ${theme.fontFamily}`;
    ctx.fillText(ellipsize(ctx, `${emp.staffNo} · ${data.worktimeLabel}`, right - x - countW - 8), x, metaY);
};

const strikeIfNeeded = (
    ctx: CanvasRenderingContext2D,
    text: string,
    tx: number,
    baseline: number,
    strike: boolean,
    color: string,
) => {
    if (!strike) return;
    const w = ctx.measureText(text).width;
    ctx.strokeStyle = color;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(tx, baseline - 4);
    ctx.lineTo(tx + w, baseline - 4);
    ctx.stroke();
};

const drawChips = (
    ctx: CanvasRenderingContext2D,
    rect: Rectangle,
    theme: Theme,
    chips: PlanChip[],
    dirtyIds: Set<string>,
    error?: string,
) => {
    const padX = 6;
    const padY = 5;
    const chipH = CHIP_HEIGHT; // two text lines: hours over position
    // The row shows one chip; any extras collapse into a "+N" badge to its right.
    const maxVisible = 1;
    const moreCount = chips.length - maxVisible;
    ctx.font = `11px ${theme.fontFamily}`;
    const moreLabel = moreCount > 0 ? `+${moreCount}` : '';
    // Reserve room on the right for the badge so the chip text doesn't run under it.
    // Cap that reservation so a wide badge (large "+N") can never squeeze the chip
    // itself down to zero width — the chip is the primary content, clip the badge first.
    const moreW = moreLabel ? Math.min(ctx.measureText(moreLabel).width + padX, rect.width * 0.4) : 0;
    const width = rect.width - padX * 2 - moreW;

    if (chips.length === 0) {
        ctx.fillStyle = COLORS.plus;
        ctx.font = `15px ${theme.fontFamily}`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('+', rect.x + rect.width / 2, rect.y + rect.height / 2);
        ctx.textAlign = 'start';
    }

    const cy = rect.y + padY;
    chips.slice(0, maxVisible).forEach((chip) => {
        const hex = chip.isFixed ? FIXED_HEX : TEMP_HEX;
        const cx = rect.x + padX;
        // Solid means real live data: finalized rows and rows staged for removal (the chip is
        // unchanged data, just marked to delete). Pending draft overlays on live rows render
        // dashed until Finalize applies them; removal is additionally shown with a strike-through.
        const removing = chip.action === 'remove';
        const solid = chip.source === 'live' && (chip.action == null || removing);
        ctx.globalAlpha = removing ? 0.7 : 1;
        roundRect(ctx, cx, cy, width, chipH, 4);
        if (solid) {
            ctx.fillStyle = hex;
            ctx.fill();
        } else {
            ctx.fillStyle = COLORS.chipDraftBg;
            ctx.fill();
            ctx.setLineDash([3, 2]);
            ctx.lineWidth = 1;
            ctx.strokeStyle = hex;
            ctx.stroke();
            ctx.setLineDash([]);
        }

        // Two lines: hours (bold) over position. Both ellipsize; position strikes
        // through when its position has been retired.
        const textColor = solid ? '#ffffff' : hex;
        const tx = cx + 7;
        const textMaxW = width - 14;
        ctx.textBaseline = 'middle';

        ctx.fillStyle = textColor;
        ctx.font = `600 11px ${theme.fontFamily}`;
        const timeText = ellipsize(ctx, shiftTimeLabel(chip.shiftTypeId), textMaxW);
        ctx.fillText(timeText, tx, cy + 10);
        strikeIfNeeded(ctx, timeText, tx, cy + 14, removing, textColor);

        const pos = positionById(chip.positionId);
        const posY = cy + 21;
        ctx.font = `11px ${theme.fontFamily}`;
        ctx.fillStyle = solid ? 'rgba(255,255,255,0.9)' : textColor;
        const posText = ellipsize(ctx, pos?.name ?? '?', textMaxW);
        ctx.fillText(posText, tx, posY);
        strikeIfNeeded(ctx, posText, tx, posY + 4, removing || pos?.isActive === false, ctx.fillStyle as string);

        ctx.globalAlpha = 1;
        // Unsaved marker: a crisp orange dot with a white halo in the top-right corner, so it
        // reads on both solid and dashed fills. Independent of fill (source) and strike (removal).
        if (dirtyIds.has(chip.id)) {
            const dotX = cx + width - 6;
            const dotY = cy + 6;
            ctx.beginPath();
            ctx.arc(dotX, dotY, 4.5, 0, Math.PI * 2);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.beginPath();
            ctx.arc(dotX, dotY, 3, 0, Math.PI * 2);
            ctx.fillStyle = COLORS.unsaved;
            ctx.fill();
        }
    });

    if (moreLabel) {
        ctx.textBaseline = 'middle';
        ctx.textAlign = 'right';
        ctx.fillStyle = COLORS.staff;
        ctx.font = `11px ${theme.fontFamily}`;
        ctx.fillText(moreLabel, rect.x + rect.width - padX, rect.y + rect.height / 2);
        ctx.textAlign = 'start';
    }

    if (error) {
        ctx.strokeStyle = COLORS.errorBorder;
        ctx.lineWidth = 1.5;
        ctx.strokeRect(rect.x + 1, rect.y + 1, rect.width - 2, rect.height - 2);
    }
};

const spRenderer: CustomRenderer<SPCell> = {
    kind: GridCellKind.Custom,
    isMatch: (c): c is SPCell => (c.data as SPCellData)?.kind === 'sp-cell',
    draw: (args, cell) => {
        const { ctx, rect, theme } = args;
        if (cell.data.variant === 'employee') drawEmployee(ctx, rect, theme as Theme, cell.data);
        else drawChips(ctx, rect, theme as Theme, cell.data.chips, cell.data.dirtyIds, cell.data.error);
        return true;
    },
};

// ---- header shift summaries ----------------------------------------------
// One two-line block under a day's date: the shift time range over its counts. Counts split
// fixed/temp (green/blue, matching the chip legend) with the max-registered-temp cap; assigned
// numbers come from the live in-progress plan. A muted dash means no concrete shift exists that
// day. Segments drop from the right (max first) if the column is too narrow to fit them all.
const drawHeaderShiftBlock = (
    ctx: CanvasRenderingContext2D,
    theme: Theme,
    rect: Rectangle,
    blockY: number,
    date: string,
    shiftTypeId: number,
    shiftConfigs: Map<string, ApiConcreteShift>,
    headerCounts: Map<string, HeaderCount>,
) => {
    const padX = theme.cellHorizontalPadding;
    const x = rect.x + padX;
    const maxW = rect.width - padX * 2;
    const cfg = shiftConfigs.get(shiftSlotKey(date, shiftTypeId));

    ctx.textAlign = 'start';
    ctx.textBaseline = 'alphabetic';

    // Line 1: shift time range (muted further when the shift isn't created that day).
    ctx.font = `600 11px ${theme.fontFamily}`;
    ctx.fillStyle = cfg ? COLORS.meta : COLORS.staff;
    ctx.fillText(ellipsize(ctx, shiftTimeLabel(shiftTypeId), maxW), x, blockY + 11);

    const y2 = blockY + 24;
    if (!cfg) {
        ctx.font = `11px ${theme.fontFamily}`;
        ctx.fillStyle = COLORS.plus;
        ctx.fillText('—', x, y2);
        return;
    }

    // Line 2: F a/f · T a/t · max N.
    const counts = headerCounts.get(shiftSlotKey(date, shiftTypeId)) ?? { fixed: 0, temp: 0 };
    const segments: { text: string; color: string }[] = [
        { text: `F ${counts.fixed}/${cfg.fixed_total}`, color: FIXED_HEX },
        { text: `T ${counts.temp}/${cfg.temp_total}`, color: TEMP_HEX },
    ];
    if (cfg.max_registered_temp != null) {
        segments.push({ text: `max ${cfg.max_registered_temp}`, color: COLORS.staff });
    }
    ctx.font = `11px ${theme.fontFamily}`;
    const sep = ' · ';
    const sepW = ctx.measureText(sep).width;
    const limit = x + maxW;
    let cx = x;
    for (let i = 0; i < segments.length; i++) {
        const prefixW = i > 0 ? sepW : 0;
        const w = ctx.measureText(segments[i].text).width;
        if (cx + prefixW + w > limit) break; // no room for this segment — drop it (and the rest)
        if (i > 0) {
            ctx.fillStyle = COLORS.staff;
            ctx.fillText(sep, cx, y2);
            cx += sepW;
        }
        ctx.fillStyle = segments[i].color;
        ctx.fillText(segments[i].text, cx, y2);
        cx += w;
    }
};

// ---- component ------------------------------------------------------------
type Props = {
    columns: PlanColumn[];
    employees: PlanEmployee[];
    cells: Record<string, PlanChip[]>;
    dirtyIds: Set<string>; // chip ids with unsaved changes — marked with a corner dot
    errors: Record<string, string>;
    readOnly: boolean;
    shiftFilter: number | null; // show only this shift type's chips; null = all
    // Shift types whose per-day totals are shown under each date header (in this order). Empty =
    // no summaries → the header keeps its original single-line height.
    selectedHeaderShifts: number[];
    shiftConfigs: Map<string, ApiConcreteShift>; // key `${date}|${shiftTypeId}`; presence = created
    headerCounts: Map<string, HeaderCount>; // key `${date}|${shiftTypeId}`; planned fixed/temp tally
    employeeFiltersActive: boolean; // drives the "Employee (filtered)" header hint
    recentDays: number; // trailing window (days) behind the per-employee shift count
    clipboardScope: string; // changes when copied shift templates should no longer be reused
    selectionResetKey: number; // bump to clear the grid selection (e.g. after Finalize)
    shiftExists: (date: string, shiftTypeId: number) => boolean;
    // Reports the finalizable (employee, date) cells inside the current selection.
    onSelectionChange: (targets: { employeeId: number; date: string }[]) => void;
    // Open the config editor for a created shift from its header summary. Undefined => view only.
    onEditShiftConfig?: (date: string, shiftTypeId: number) => void;
    onAdd: (employeeId: number, date: string, shiftTypeId: number, positionId: number, isFixed: boolean) => void;
    onUpdate: (
        employeeId: number,
        date: string,
        chipId: string,
        shiftTypeId: number,
        positionId: number,
        isFixed: boolean,
    ) => void;
    onRemove: (employeeId: number, date: string, chipId: string) => void;
    onPaste: (targets: { employeeId: number; date: string; chips: ChipTemplate[] }[]) => void;
    onRequestCreateShift: (
        employeeId: number,
        date: string,
        shiftTypeId: number,
        positionId: number,
        isFixed: boolean,
    ) => void;
};

type EditorState = { employeeId: number; date: string; col: number; row: number } | null;

const EMPTY_SELECTION: GridSelection = {
    current: undefined,
    columns: CompactSelection.empty(),
    rows: CompactSelection.empty(),
};

const EDITOR_GAP = 4;
const VIEWPORT_MARGIN = 8;

// Floating panel. Portaled to <body> (so the grid's overflow:hidden can't clip
// it) and positioned against a live viewport rect, flipping above when there is
// not enough room below. Reused for the cell editor and the header filter menu.
const FloatingPanel: React.FC<{ anchor: Rectangle; onClose: () => void; children: React.ReactNode }> = ({
    anchor,
    onClose,
    children,
}) => {
    const ref = useRef<HTMLDivElement>(null);
    const [style, setStyle] = useState<React.CSSProperties>({
        position: 'fixed',
        left: 0,
        top: 0,
        zIndex: 1000,
        visibility: 'hidden',
    });

    useLayoutEffect(() => {
        const el = ref.current;
        if (!el) return;
        const w = el.offsetWidth;
        const h = el.offsetHeight;

        let top = anchor.y + anchor.height + EDITOR_GAP;
        if (top + h > window.innerHeight - VIEWPORT_MARGIN) {
            const above = anchor.y - h - EDITOR_GAP;
            top =
                above >= VIEWPORT_MARGIN ? above : Math.max(VIEWPORT_MARGIN, window.innerHeight - h - VIEWPORT_MARGIN);
        }

        let left = anchor.x;
        if (left + w > window.innerWidth - VIEWPORT_MARGIN) left = window.innerWidth - w - VIEWPORT_MARGIN;
        if (left < VIEWPORT_MARGIN) left = VIEWPORT_MARGIN;

        setStyle({ position: 'fixed', left, top, zIndex: 1000, visibility: 'visible' });
    }, [anchor.x, anchor.y, anchor.width, anchor.height]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return createPortal(
        <>
            <div style={{ position: 'fixed', inset: 0, zIndex: 999 }} onMouseDown={onClose} />
            <div ref={ref} style={style} onMouseDown={(e) => e.stopPropagation()}>
                <Paper shadow="md" withBorder radius="md" p="sm">
                    {children}
                </Paper>
            </div>
        </>,
        document.body,
    );
};

const PlanGrid: React.FC<Props> = ({
    columns,
    employees,
    cells,
    dirtyIds,
    errors,
    readOnly,
    shiftFilter,
    selectedHeaderShifts,
    shiftConfigs,
    headerCounts,
    employeeFiltersActive,
    recentDays,
    clipboardScope,
    selectionResetKey,
    shiftExists,
    onSelectionChange,
    onEditShiftConfig,
    onAdd,
    onUpdate,
    onRemove,
    onPaste,
    onRequestCreateShift,
}) => {
    const { t } = useTranslation();
    const gridRef = useRef<DataEditorRef>(null);
    const gridWrapRef = useRef<HTMLDivElement>(null);
    const [editor, setEditor] = useState<EditorState>(null);
    const [gridActive, setGridActive] = useState(false);
    // Glide rectangular selection (cell/range) — copy source, paste anchor, and the
    // Finalize target set.
    const [gridSelection, setGridSelection] = useState<GridSelection>(EMPTY_SELECTION);
    // In-memory clipboard (block of chip templates). Grid-local; cleared when the
    // loaded planning context changes.
    const clipboard = useRef<Clipboard | null>(null);
    const [contextMenu, setContextMenu] = useState<{ cell: Item; x: number; y: number } | null>(null);
    // Read-only employee summary popover (selectable name / staff no). Separate
    // from `editor`, which is the plan-cell assignment editor.
    const [empPanel, setEmpPanel] = useState<{ employeeId: number; col: number; row: number } | null>(null);
    // Rows sort by recent shift count (desc) by default; click the Employee header
    // to toggle back to the incoming (caller-provided) order.
    const [sortByCount, setSortByCount] = useState(true);
    // Bumped whenever the grid scrolls/resizes so the editor anchor recomputes
    // from the cell's live position instead of a stale captured rect.
    const [reflow, setReflow] = useState(0);

    useEffect(() => {
        const onResize = () => setReflow((n) => n + 1);
        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, []);

    useEffect(() => {
        clipboard.current = null;
        // A new planning context invalidates the selection's row/column coordinates.
        setGridSelection(EMPTY_SELECTION);
    }, [clipboardScope]);

    // Explicit selection clear requested by the page (e.g. after a successful Finalize).
    useEffect(() => {
        setGridSelection(EMPTY_SELECTION);
        setEditor(null);
        setEmpPanel(null);
    }, [selectionResetKey]);

    useEffect(() => {
        const onPointerDown = (event: MouseEvent) => {
            if (!gridWrapRef.current?.contains(event.target as Node)) setGridActive(false);
        };
        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, []);

    const headerTheme = useCallback((c: PlanColumn): Partial<Theme> | undefined => {
        if (c.isWeekend) return { bgHeader: COLORS.headerWeekend };
        return undefined;
    }, []);

    const gridColumns = useMemo<GridColumn[]>(() => {
        const empCol: GridColumn = {
            // Trailing ↓ marks the active shift-count sort (click the header to toggle).
            title: `${employeeFiltersActive ? t('Employee (filtered)') : t('Employee')}${sortByCount ? ' ↓' : ''}`,
            width: EMP_COL_WIDTH,
            group: '',
        };
        const dateCols: GridColumn[] = columns.map((c) => ({
            // MM/DD (short weekday). Click the header to select the whole date column.
            title: `${c.day.format('MM/DD')} (${c.day.format('ddd')})`,
            width: DATE_COL_WIDTH,
            group: c.day.format('YYYY MMM'),
            themeOverride: headerTheme(c),
        }));
        return [empCol, ...dateCols];
    }, [columns, headerTheme, employeeFiltersActive, sortByCount, t]);

    // Custom header: when shifts are selected for display, top-align every title and draw the
    // per-day shift summaries under each date. With nothing selected, fall through to the default
    // header so the standard single-line view is untouched.
    const drawHeader = useCallback<DrawHeaderCallback>(
        (args, drawContent) => {
            if (selectedHeaderShifts.length === 0) {
                drawContent();
                return;
            }
            const { ctx, rect, theme, columnIndex } = args;
            const padX = theme.cellHorizontalPadding;
            // bgHeader (weekend / finalize-selected highlight) is already painted before this call.
            ctx.font = `600 12px ${theme.fontFamily}`;
            ctx.fillStyle = theme.textHeader;
            ctx.textAlign = 'start';
            ctx.textBaseline = 'middle';
            ctx.fillText(
                ellipsize(ctx, args.column.title, rect.width - padX * 2),
                rect.x + padX,
                rect.y + HEADER_DATE_ZONE / 2,
            );
            if (columnIndex === 0) return; // employee column: title only
            const column = columns[columnIndex - 1];
            if (!column) return;
            selectedHeaderShifts.forEach((shiftTypeId, i) => {
                drawHeaderShiftBlock(
                    ctx,
                    theme as Theme,
                    rect,
                    rect.y + HEADER_DATE_ZONE + i * SHIFT_ROW_H,
                    column.date,
                    shiftTypeId,
                    shiftConfigs,
                    headerCounts,
                );
            });
        },
        [columns, selectedHeaderShifts, shiftConfigs, headerCounts],
    );

    const cellBg = useCallback((c: PlanColumn, hasError: boolean): string | undefined => {
        if (hasError) return COLORS.error;
        if (c.isWeekend) return COLORS.weekend;
        return undefined;
    }, []);

    // Row order: caller's order by default, or recent shift count (desc) when toggled.
    // Sort is stable so equal counts keep the caller's relative order.
    const sortedEmployees = useMemo(() => {
        if (!sortByCount) return employees;
        return employees
            .map((emp, i) => ({ emp, i }))
            .sort((a, b) => b.emp.recentShiftCount - a.emp.recentShiftCount || a.i - b.i)
            .map(({ emp }) => emp);
    }, [employees, sortByCount]);

    // ----- copy / paste -------------------------------------------------------
    // Templates for one (employee, date) cell — full cell content, ignoring the
    // active shiftFilter so hidden chips aren't silently dropped.
    const cellTemplates = useCallback(
        (employeeId: number, date: string): ChipTemplate[] =>
            effectiveChips(cells[cellKey(employeeId, date)] ?? []).map(chipToTemplate),
        [cells],
    );

    const buildSingleCellClipboard = useCallback(
        ([col, row]: Item): Clipboard | null => {
            if (col < 1 || row < 0 || row >= sortedEmployees.length || col - 1 >= columns.length) return null;
            return {
                width: 1,
                height: 1,
                cells: [{ dr: 0, dc: 0, chips: cellTemplates(sortedEmployees[row].id, columns[col - 1].date) }],
            };
        },
        [columns, sortedEmployees, cellTemplates],
    );

    // Resolve the copy block (the rectangular selection) and stamp each planned cell
    // with its chips.
    const buildClipboard = useCallback((): Clipboard | null => {
        const plan = resolveCopyPlan(gridSelection.current?.range, sortedEmployees.length, columns.length);
        if (!plan) return null;
        const cells: ClipCell[] = plan.cells.map(({ dr, dc, row, col }) => ({
            dr,
            dc,
            chips: cellTemplates(sortedEmployees[row].id, columns[col].date),
        }));
        return { width: plan.width, height: plan.height, cells };
    }, [gridSelection, columns, sortedEmployees, cellTemplates]);

    // Whether a (grid) cell belongs to the active rectangular selection.
    const cellInSelection = useCallback(
        ([col, row]: Item): boolean => {
            const r = gridSelection.current?.range;
            return !!r && col >= r.x && col < r.x + r.width && row >= r.y && row < r.y + r.height;
        },
        [gridSelection],
    );

    // Finalizable (employee, date) cells inside the current rectangular selection.
    // Reported up so the toolbar can drive the Finalize button and request.
    const finalizeTargets = useMemo<{ employeeId: number; date: string }[]>(() => {
        const r = gridSelection.current?.range;
        if (!r) return [];
        const startCol = Math.max(1, r.x);
        const endCol = Math.min(r.x + r.width - 1, columns.length);
        const endRow = Math.min(r.y + r.height, sortedEmployees.length);
        const out: { employeeId: number; date: string }[] = [];
        for (let row = r.y; row < endRow; row++) {
            for (let col = startCol; col <= endCol; col++) {
                const employeeId = sortedEmployees[row].id;
                const date = columns[col - 1].date;
                if (isCellFinalizable(cells[cellKey(employeeId, date)])) out.push({ employeeId, date });
            }
        }
        return out;
    }, [gridSelection, columns, sortedEmployees, cells]);

    useEffect(() => {
        onSelectionChange(finalizeTargets);
    }, [finalizeTargets, onSelectionChange]);

    const handleCopy = useCallback(
        (sourceCell?: Item) => {
            // Right-click (sourceCell set): copy the active selection when the clicked
            // cell is part of it, otherwise copy just that cell. Keyboard copy (no
            // sourceCell) always copies the active selection.
            const clip =
                sourceCell && !cellInSelection(sourceCell)
                    ? buildSingleCellClipboard(sourceCell)
                    : (buildClipboard() ?? (sourceCell ? buildSingleCellClipboard(sourceCell) : null));
            const total = clip?.cells.reduce((n, c) => n + c.chips.length, 0) ?? 0;
            if (!clip || total === 0) {
                notifications.show({ color: 'gray', message: t('Nothing to copy.') });
                return;
            }
            clipboard.current = clip;
            notifications.show({ color: 'teal', message: t('Copied') });
        },
        [buildClipboard, buildSingleCellClipboard, cellInSelection, t],
    );

    const handlePaste = useCallback(
        (targetCell?: Item) => {
            if (readOnly) return;
            const clip = clipboard.current;
            if (!clip) return;
            const cell = targetCell ?? gridSelection.current?.cell;
            if (!cell || cell[0] < 1) {
                notifications.show({ color: 'gray', message: t('Select a target cell to paste.') });
                return;
            }
            const anchorCol = cell[0] - 1;
            const anchorRow = cell[1];
            const { targets, fits } = resolvePasteTargets(
                clip,
                anchorRow,
                anchorCol,
                sortedEmployees.length,
                columns.length,
            );
            if (!fits) {
                notifications.show({ color: 'orange', message: t('The copied block does not fit here.') });
                return;
            }
            onPaste(
                targets.map((target) => ({
                    employeeId: sortedEmployees[target.row].id,
                    date: columns[target.col].date,
                    chips: target.chips,
                })),
            );
        },
        [readOnly, gridSelection, sortedEmployees, columns, onPaste, t],
    );

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (!gridActive || !(e.ctrlKey || e.metaKey)) return;
            const el = e.target as HTMLElement | null;
            const tag = el?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el?.isContentEditable) return;
            const key = e.key.toLowerCase();
            if (key === 'c' && window.getSelection()?.toString()) return;
            if (key === 'c') {
                e.preventDefault();
                handleCopy();
            } else if (key === 'v') {
                e.preventDefault();
                handlePaste();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [gridActive, handleCopy, handlePaste]);

    const getCellContent = useCallback(
        ([col, row]: Item): GridCell => {
            const emp = sortedEmployees[row];
            if (col === 0) {
                const warningColors = employeeWarnings(emp, t).map((w) =>
                    w.color === 'red' ? COLORS.warnRed : COLORS.warnYellow,
                );
                const shiftCount = emp.recentShiftCount;
                return {
                    kind: GridCellKind.Custom,
                    allowOverlay: false,
                    copyData: emp.name,
                    data: {
                        kind: 'sp-cell',
                        variant: 'employee',
                        employee: emp,
                        worktimeLabel: worktimeGroupLabel(emp.worktimeGroup, t),
                        shiftCountLabel: t('{{count}} shifts', { count: shiftCount }),
                        warningColors,
                    },
                } as SPCell;
            }
            const column = columns[col - 1];
            const key = cellKey(emp.id, column.date);
            const allChips = cells[key] ?? [];
            const chips = shiftFilter ? allChips.filter((c) => c.shiftTypeId === shiftFilter) : allChips;
            const error = errors[key] && allChips.length > 0 ? errors[key] : undefined;
            const bg = cellBg(column, !!error);
            return {
                kind: GridCellKind.Custom,
                allowOverlay: false,
                copyData: chips.map(chipText).join(', '),
                themeOverride: bg ? { bgCell: bg } : undefined,
                data: { kind: 'sp-cell', variant: 'plan', chips, error, dirtyIds },
            } as SPCell;
        },
        [sortedEmployees, columns, cells, dirtyIds, errors, cellBg, shiftFilter, t],
    );

    const onCellClicked = useCallback(
        ([col, row]: Item) => {
            if (col === 0) {
                // Employee cell: open the read-only summary (selectable text).
                setEmpPanel({ employeeId: sortedEmployees[row].id, col, row });
                return;
            }
            setEditor({ employeeId: sortedEmployees[row].id, date: columns[col - 1].date, col, row });
        },
        [columns, sortedEmployees],
    );

    const onHeaderClicked = useCallback(
        (col: number, event: HeaderClickedEventArgs) => {
            if (col === 0) {
                // Toggle shift-count sort. Close any open popovers first: they hold
                // captured row indices that the re-sort would otherwise misalign.
                setEditor(null);
                setEmpPanel(null);
                setSortByCount((s) => !s);
                return;
            }
            // A click below the date band lands on a shift summary: open that shift's config
            // (when editable and the shift actually exists), without selecting the column.
            if (selectedHeaderShifts.length > 0 && event.localEventY >= HEADER_DATE_ZONE) {
                const idx = Math.floor((event.localEventY - HEADER_DATE_ZONE) / SHIFT_ROW_H);
                const shiftTypeId = selectedHeaderShifts[idx];
                const date = columns[col - 1].date;
                if (shiftTypeId != null && onEditShiftConfig && shiftConfigs.has(shiftSlotKey(date, shiftTypeId))) {
                    onEditShiftConfig(date, shiftTypeId);
                }
                return;
            }
            // Select the whole date column so it can be finalized in a single click.
            setGridSelection({
                columns: CompactSelection.empty(),
                rows: CompactSelection.empty(),
                current: {
                    cell: [col, 0],
                    range: { x: col, y: 0, width: 1, height: sortedEmployees.length },
                    rangeStack: [],
                },
            });
        },
        [columns, sortedEmployees.length, selectedHeaderShifts, shiftConfigs, onEditShiftConfig],
    );

    const activeEmp = editor ? employees.find((emp) => emp.id === editor.employeeId) : undefined;
    const activeChips = editor ? (cells[cellKey(editor.employeeId, editor.date)] ?? []) : [];
    const activeError = editor ? errors[cellKey(editor.employeeId, editor.date)] : undefined;
    // Live cell rect (viewport coords). Recomputed on open and on every scroll
    // (reflow) so the panel follows the cell even when the grid auto-scrolls it
    // into view after a click. `undefined` => the cell scrolled out of view.
    const anchor = useMemo<Rectangle | undefined>(() => {
        if (!editor) return undefined;
        return gridRef.current?.getBounds(editor.col, editor.row);
    }, [editor, reflow]);

    const empPanelEmployee = empPanel ? employees.find((emp) => emp.id === empPanel.employeeId) : undefined;
    const empAnchor = useMemo<Rectangle | undefined>(() => {
        if (!empPanel) return undefined;
        return gridRef.current?.getBounds(empPanel.col, empPanel.row);
    }, [empPanel, reflow]);

    return (
        <div ref={gridWrapRef} style={{ position: 'relative' }} onMouseDownCapture={() => setGridActive(true)}>
            <DataEditor
                ref={gridRef}
                columns={gridColumns}
                rows={sortedEmployees.length}
                getCellContent={getCellContent}
                onCellClicked={onCellClicked}
                onCellContextMenu={(cell, event) => {
                    if (cell[0] < 1) return;
                    event.preventDefault();
                    setGridActive(true);
                    setContextMenu({
                        cell,
                        x: event.bounds.x + Math.min(24, event.bounds.width / 2),
                        y: event.bounds.y + Math.min(24, event.bounds.height / 2),
                    });
                }}
                onHeaderClicked={onHeaderClicked}
                drawHeader={drawHeader}
                onVisibleRegionChanged={() => {
                    setContextMenu(null);
                    setReflow((n) => n + 1);
                }}
                customRenderers={[spRenderer]}
                freezeColumns={1}
                rowHeight={ROW_HEIGHT}
                headerHeight={headerHeightFor(selectedHeaderShifts.length)}
                groupHeaderHeight={GRID_GROUP_HEADER_HEIGHT}
                rowMarkers="none"
                smoothScrollX
                smoothScrollY
                rangeSelect="rect"
                columnSelect="none"
                gridSelection={gridSelection}
                onGridSelectionChange={(selection) => {
                    setContextMenu(null);
                    setGridSelection(selection);
                }}
                // We drive copy/paste ourselves (chips, draft semantics, occupancy
                // block); disable Glide's built-ins so they don't double-fire.
                keybindings={{ copy: false, paste: false, cut: false }}
                width="100%"
                height={GRID_HEIGHT}
            />

            {contextMenu &&
                createPortal(
                    <>
                        <div
                            style={{ position: 'fixed', inset: 0, zIndex: 1001 }}
                            onMouseDown={() => setContextMenu(null)}
                            onContextMenu={(e) => {
                                e.preventDefault();
                                setContextMenu(null);
                            }}
                        />
                        <Paper
                            shadow="md"
                            withBorder
                            radius="md"
                            p={4}
                            style={{ position: 'fixed', left: contextMenu.x, top: contextMenu.y, zIndex: 1002 }}
                            onMouseDown={(e) => e.stopPropagation()}
                            onContextMenu={(e) => e.preventDefault()}
                        >
                            <Stack gap={2}>
                                <Button
                                    size="xs"
                                    variant="subtle"
                                    color="gray"
                                    justify="flex-start"
                                    onClick={() => {
                                        handleCopy(contextMenu.cell);
                                        setContextMenu(null);
                                    }}
                                >
                                    {t('Copy')}
                                </Button>
                                <Button
                                    size="xs"
                                    variant="subtle"
                                    color="gray"
                                    justify="flex-start"
                                    disabled={readOnly || !clipboard.current}
                                    onClick={() => {
                                        handlePaste(contextMenu.cell);
                                        setContextMenu(null);
                                    }}
                                >
                                    {t('Paste')}
                                </Button>
                            </Stack>
                        </Paper>
                    </>,
                    document.body,
                )}

            {editor && activeEmp && anchor && (
                <FloatingPanel anchor={anchor} onClose={() => setEditor(null)}>
                    <CellEditor
                        key={cellKey(editor.employeeId, editor.date)}
                        employee={activeEmp}
                        date={dayjs(editor.date)}
                        readOnly={readOnly}
                        chips={activeChips}
                        dirtyIds={dirtyIds}
                        errorReason={activeChips.length > 0 ? activeError : undefined}
                        shiftExists={(shiftTypeId) => shiftExists(editor.date, shiftTypeId)}
                        onAdd={(s, p, f) => onAdd(editor.employeeId, editor.date, s, p, f)}
                        onUpdate={(chipId, s, p, f) => onUpdate(editor.employeeId, editor.date, chipId, s, p, f)}
                        onRemove={(chipId) => onRemove(editor.employeeId, editor.date, chipId)}
                        onRequestCreateShift={(s, p, f) =>
                            onRequestCreateShift(editor.employeeId, editor.date, s, p, f)
                        }
                    />
                </FloatingPanel>
            )}

            {empPanel && empPanelEmployee && empAnchor && (
                <FloatingPanel anchor={empAnchor} onClose={() => setEmpPanel(null)}>
                    <EmployeeInfo employee={empPanelEmployee} recentDays={recentDays} />
                </FloatingPanel>
            )}
        </div>
    );
};

export default PlanGrid;
