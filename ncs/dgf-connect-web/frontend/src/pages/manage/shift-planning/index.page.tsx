import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    LuCalendarRange,
    LuChevronDown,
    LuChevronUp,
    LuCircleHelp,
    LuEye,
    LuListFilter,
    LuLock,
    LuLogOut,
    LuPencil,
    LuRotateCcw,
    LuSave,
    LuSearch,
    LuSquareCheck,
    LuTriangleAlert,
} from 'react-icons/lu';
import dynamic from 'next/dynamic';
import Link from 'next/link';
import {
    ActionIcon,
    Anchor,
    Badge,
    Breadcrumbs,
    Button,
    Chip,
    CloseButton,
    Collapse,
    Divider,
    Flex,
    Group,
    Paper,
    Popover,
    Select,
    Stack,
    Switch,
    Text,
    TextInput,
    Title,
} from '@mantine/core';
import { MonthPickerInput } from '@mantine/dates';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import dayjs from 'dayjs';
import { useAtom } from 'jotai';
import WeeklyHourLimitHelper from '@/helpers/weekly_hour_limit.helper';
import { masterAtom } from '@/pages/global.state';
import CreateShiftModal, { CreateShiftValues } from '@/pages/manage/shift-planning/partials/create_shift_modal';
import {
    ChipTemplate,
    EMPTY_EMPLOYEE_FILTERS,
    EmployeeFilters,
    PlanChip,
    WORKTIME_GROUPS,
    WorktimeGroup,
    buildCells,
    buildColumns,
    buildDraftPayload,
    buildHeaderCounts,
    cellKey,
    classifyChipChange,
    isCellOccupied,
    newDraftChip,
    positionName,
    scopeErrorsToDirtyCells,
    setRegistries,
    shiftSlotKey,
    shiftTimeLabel,
    splitShiftTypesByCreated,
    toEmployees,
    toPositions,
    toShiftTypes,
    worktimeGroupLabel,
} from '@/pages/manage/shift-planning/partials/data';
import FinalizeResultModal, { FinalizeDateResult } from '@/pages/manage/shift-planning/partials/finalize_result_modal';
import PlanGridSkeleton from '@/pages/manage/shift-planning/partials/plan_grid_skeleton';
import SaveConfirmModal, { DraftDiffEntry } from '@/pages/manage/shift-planning/partials/save_confirm_modal';
import ValidationErrorsModal, {
    ValidationErrorEntry,
} from '@/pages/manage/shift-planning/partials/validation_errors_modal';
import styles from '@/pages/manage/shift-planning/index.module.css';
import {
    ApiConcreteShift,
    FinalizeErrorEntry,
    LockState,
    useCreateShiftMutation,
    useFinalizeMutation,
    useLockMutation,
    useReleaseLockMutation,
    useSaveDraftMutation,
    useShiftPlanningQuery,
    useUpdateShiftConfigMutation,
} from '@/pages/manage/shift-planning/partials/api';

// glide-data-grid renders to canvas and touches window — load client-side only.
const PlanGrid = dynamic(() => import('@/pages/manage/shift-planning/partials/plan_grid'), {
    ssr: false,
    loading: () => <PlanGridSkeleton />,
});

type PendingCreate = {
    employeeId: number;
    date: string;
    shiftTypeId: number;
    positionId: number;
    isFixed: boolean;
} | null;

const CONTROL_W = 220; // shared width so the toolbar selects line up evenly
const HEARTBEAT_MS = 150_000; // renew well inside the 5-min server TTL
const IDLE_MS = 5 * 60_000; // stop renewing after this much inactivity → lock auto-frees

const clone = (cells: Record<string, PlanChip[]>): Record<string, PlanChip[]> =>
    Object.fromEntries(Object.entries(cells).map(([k, v]) => [k, v.map((c) => ({ ...c }))]));

// Render an hour amount compactly: drop the decimal for whole numbers, otherwise
// keep one decimal place (e.g. 3 → "3", 2.5 → "2.5").
const formatHours = (hours: number): string =>
    Number.isInteger(hours) ? String(hours) : (Math.round(hours * 10) / 10).toString();

const IndexPage = () => {
    const { t } = useTranslation();
    const [masterData] = useAtom(masterAtom);

    const workplaceOptions = useMemo(
        () => masterData.workplaces.map((w) => ({ value: String(w.id), label: w.name })),
        [masterData.workplaces],
    );
    const [workplaceId, setWorkplaceId] = useState<string | null>(workplaceOptions[0]?.value ?? null);
    useEffect(() => {
        if (!workplaceId && workplaceOptions.length > 0) setWorkplaceId(workplaceOptions[0].value);
    }, [workplaceOptions, workplaceId]);

    const earliestAnchor = useMemo(() => dayjs().startOf('month'), []);
    const [anchor, setAnchor] = useState(() => dayjs().startOf('month'));
    const [memberActivityDays, setMemberActivityDays] = useState('60');
    const from = anchor.format('YYYY-MM-DD');

    // Collapsible page filter (workplace / activity window / month) to free up vertical space.
    const [filtersOpen, { toggle: toggleFilters }] = useDisclosure(true);
    const memberActivityOptions = useMemo(
        () => [
            { value: '30', label: t('{{count}} days', { count: 30 }) },
            { value: '60', label: t('{{count}} days', { count: 60 }) },
            { value: '90', label: t('{{count}} days', { count: 90 }) },
            { value: '180', label: t('{{count}} days', { count: 180 }) },
            { value: '0', label: t('All') },
        ],
        [t],
    );

    const query = useShiftPlanningQuery(workplaceId, from, memberActivityDays);
    const data = query.data;

    const [filters, setFilters] = useState<EmployeeFilters>(EMPTY_EMPLOYEE_FILTERS);
    const [shiftFilter, setShiftFilter] = useState<string | null>(null);
    // Shift types whose per-day totals are shown under each date header. Default none.
    const [headerShifts, setHeaderShifts] = useState<string[]>([]);

    const [cells, setCells] = useState<Record<string, PlanChip[]>>({});
    const [baseline, setBaseline] = useState<Record<string, PlanChip[]>>({});
    // Finalizable (employee, date) cells inside the grid's current selection, reported
    // up by the grid. `selectionResetKey` is bumped to clear that selection after Finalize.
    const [finalizeTargets, setFinalizeTargets] = useState<{ employeeId: number; date: string }[]>([]);
    const [selectionResetKey, setSelectionResetKey] = useState(0);
    // Concrete shift configs in the window, keyed `${date}|${workplace_shift_id}`. Presence also
    // means the shift exists for that day. Kept in local state (not refetched) so unsaved drafts
    // survive a config edit/create.
    const [shiftConfigs, setShiftConfigs] = useState<Record<string, ApiConcreteShift>>({});
    const [activeErrors, setActiveErrors] = useState<Record<string, string>>({});
    const [lock, setLock] = useState<LockState | null>(null);

    const [pendingCreate, setPendingCreate] = useState<PendingCreate>(null);
    // Header shift summary the admin clicked to edit its config (date + shift type).
    const [editingShift, setEditingShift] = useState<{ date: string; shiftTypeId: number } | null>(null);
    const [saveOpen, setSaveOpen] = useState(false);
    const [saveErrors, setSaveErrors] = useState<ValidationErrorEntry[] | null>(null);
    const [finalizeResults, setFinalizeResults] = useState<FinalizeDateResult[] | null>(null);

    const saveMutation = useSaveDraftMutation();
    const finalizeMutation = useFinalizeMutation();
    const lockMutation = useLockMutation();
    const releaseMutation = useReleaseLockMutation();
    const createShiftMutation = useCreateShiftMutation();
    const updateShiftConfigMutation = useUpdateShiftConfigMutation();

    // Populate the canvas lookup registries from the payload before the grid renders.
    const shiftTypes = useMemo(() => (data ? toShiftTypes(data.workplace_shifts) : []), [data]);
    const positions = useMemo(() => (data ? toPositions(data.positions) : []), [data]);
    setRegistries(shiftTypes, positions);

    const shiftTotalsByKey = useMemo(() => {
        const map = new Map<string, { targetFixedTotal: number; targetTempTotal: number }>();
        data?.shifts.forEach((shift) => {
            map.set(`${shift.date}|${shift.workplace_shift_id}`, {
                targetFixedTotal: shift.fixed_total,
                targetTempTotal: shift.temp_total,
            });
        });
        return map;
    }, [data]);

    const shiftTotalsFor = (date: string, shiftTypeId: number) =>
        shiftTotalsByKey.get(`${date}|${shiftTypeId}`) ?? { targetFixedTotal: null, targetTempTotal: null };

    const employees = useMemo(() => {
        if (!data) return [];
        const byId = Object.fromEntries(shiftTypes.map((s) => [s.id, s]));
        return toEmployees(data.members, byId, t);
    }, [data, shiftTypes, t]);

    const columns = useMemo(() => (data ? buildColumns(data.window.from, data.window.to) : []), [data]);

    // (Re)initialise local state on every successful fetch (window/workplace change or
    // an explicit refetch after save/finalize). Local edits are intentionally reset.
    useEffect(() => {
        if (!data) return;
        const seeded = buildCells(data);
        setCells(clone(seeded));
        setBaseline(seeded);
        setShiftConfigs(Object.fromEntries(data.shifts.map((s) => [shiftSlotKey(s.date, s.workplace_shift_id), s])));
        setLock(data.lock);
        setActiveErrors({});
    }, [query.dataUpdatedAt]);

    const iHoldLock = !!lock?.is_me;
    const readOnly = !iHoldLock;

    // ----- activity-gated lock heartbeat (requirements §4.1) -----
    const lastActivity = useRef(Date.now());
    const markActivity = () => {
        lastActivity.current = Date.now();
    };
    useEffect(() => {
        if (!iHoldLock || !workplaceId) return;
        const id = setInterval(() => {
            // Only renew while genuinely active; idle tabs stop renewing so the TTL frees
            // the workplace automatically even if left open.
            if (Date.now() - lastActivity.current > IDLE_MS) return;
            lockMutation.mutate(
                { workplace_id: Number(workplaceId) },
                { onSuccess: (res) => res.data?.lock && setLock(res.data.lock) },
            );
        }, HEARTBEAT_MS);
        return () => clearInterval(id);
    }, [iHoldLock, workplaceId]);

    // Release on unmount if we still hold the lock.
    const lockRef = useRef<{ hold: boolean; workplaceId: string | null }>({ hold: false, workplaceId: null });
    lockRef.current = { hold: iHoldLock, workplaceId };
    useEffect(() => {
        return () => {
            if (lockRef.current.hold && lockRef.current.workplaceId) {
                releaseMutation.mutate(Number(lockRef.current.workplaceId));
            }
        };
    }, []);

    const filteredEmployees = useMemo(() => {
        const q = filters.search.trim().toLowerCase();
        return employees
            .filter((e) => {
                if (q && !e.name.toLowerCase().includes(q) && !e.staffNo.toLowerCase().includes(q)) return false;
                if (filters.worktimeGroups.length > 0 && !filters.worktimeGroups.includes(e.worktimeGroup))
                    return false;
                if (filters.fixedScheduleOnly && !e.hasFixedSchedule) return false;
                return true;
            })
            .sort((a, b) => Number(b.hasFixedSchedule) - Number(a.hasFixedSchedule) || a.name.localeCompare(b.name));
    }, [employees, filters]);

    // Grid (client-side) filters: shift display + employee search/worktime/fixed.
    // These re-project already-loaded data and never refetch.
    const employeeFiltersActive =
        filters.search.trim() !== '' || filters.worktimeGroups.length > 0 || filters.fixedScheduleOnly;
    const gridFilterCount =
        (shiftFilter ? 1 : 0) +
        (filters.search.trim() ? 1 : 0) +
        (filters.worktimeGroups.length > 0 ? 1 : 0) +
        (filters.fixedScheduleOnly ? 1 : 0);
    const clearGridFilters = () => {
        setShiftFilter(null);
        setFilters(EMPTY_EMPLOYEE_FILTERS);
    };

    const employeeName = useMemo(() => {
        const map = new Map<number, string>();
        employees.forEach((e) => map.set(e.id, e.name));
        return map;
    }, [employees]);

    // ----- draft diff for the Save modal -----
    const diff = useMemo<DraftDiffEntry[]>(() => {
        const flatten = (src: Record<string, PlanChip[]>) => {
            const map = new Map<string, { key: string; chip: PlanChip }>();
            Object.entries(src).forEach(([key, chips]) => chips.forEach((chip) => map.set(chip.id, { key, chip })));
            return map;
        };
        const cur = flatten(cells);
        const base = flatten(baseline);
        const entries: DraftDiffEntry[] = [];
        const describe = (key: string, chip: PlanChip, kind: DraftDiffEntry['kind']): DraftDiffEntry => {
            const [empId, date] = key.split('|');
            return {
                kind,
                // Live removals take effect immediately at Save; adds and updates stay silent
                // draft changes until Finalize.
                liveImpact: chip.source === 'live' && chip.action === 'remove',
                employeeName: employeeName.get(Number(empId)) ?? '',
                dateLabel: dayjs(date).format('ddd, MMM D'),
                chipLabel: `${shiftTimeLabel(chip.shiftTypeId)} · ${positionName(chip.positionId)} · ${chip.isFixed ? t('Fixed') : t('Temp')}`,
            };
        };
        cur.forEach(({ key, chip }, id) => {
            const kind = classifyChipChange(chip, base.get(id)?.chip);
            if (kind) entries.push(describe(key, chip, kind));
        });
        base.forEach(({ key, chip }, id) => {
            if (chip.source === 'draft' && !cur.has(id)) entries.push(describe(key, chip, 'remove'));
        });
        return entries;
    }, [cells, baseline, employeeName, t]);

    const dirtyCount = diff.length;

    // Ids of chips that differ from the last-saved baseline, so the grid/editor can mark them
    // unsaved (a corner dot). Same comparison the diff above uses, keyed by chip id. Drafts that
    // were deleted aren't here — there's no chip left to mark. Stays consistent with dirtyCount.
    const dirtyIds = useMemo(() => {
        const ids = new Set<string>();
        const base = new Map<string, PlanChip>();
        Object.values(baseline).forEach((chips) => chips.forEach((c) => base.set(c.id, c)));
        Object.values(cells).forEach((chips) =>
            chips.forEach((chip) => {
                if (classifyChipChange(chip, base.get(chip.id))) ids.add(chip.id);
            }),
        );
        return ids;
    }, [cells, baseline]);

    // ----- cell mutations -----
    const addChip = (employeeId: number, date: string, shiftTypeId: number, positionId: number, isFixed: boolean) => {
        markActivity();
        const key = cellKey(employeeId, date);
        const chip = newDraftChip({ shiftTypeId, positionId, isFixed, ...shiftTotalsFor(date, shiftTypeId) });
        setCells((prev) => ({ ...prev, [key]: [...(prev[key] ?? []), chip] }));
    };

    // Bulk paste: stamp copied chips into the resolved targets as fresh drafts.
    // Blocked only when a target that will receive chips already holds data (live or
    // draft); empty source cells are dropped and leave their targets untouched.
    const pasteChips = (targets: { employeeId: number; date: string; chips: ChipTemplate[] }[]) => {
        const nonEmptyTargets = targets.filter((target) => target.chips.length > 0);
        if (readOnly || nonEmptyTargets.length === 0) return;
        const blocked = nonEmptyTargets.some((t) => isCellOccupied(cells[cellKey(t.employeeId, t.date)]));
        if (blocked) {
            notifications.show({ color: 'orange', message: t('Paste blocked: a target cell already has shifts.') });
            return;
        }
        markActivity();
        const count = nonEmptyTargets.reduce((sum, t) => sum + t.chips.length, 0);
        setCells((prev) => {
            const next = { ...prev };
            nonEmptyTargets.forEach((target) => {
                const key = cellKey(target.employeeId, target.date);
                next[key] = [...(next[key] ?? []), ...target.chips.map(newDraftChip)];
            });
            return next;
        });
        notifications.show({ color: 'teal', message: t('Pasted {{count}} shifts.', { count }) });
    };

    const removeChip = (employeeId: number, date: string, chipId: string) => {
        markActivity();
        const key = cellKey(employeeId, date);
        setCells((prev) => {
            const chips = prev[key] ?? [];
            const chip = chips.find((c) => c.id === chipId);
            if (!chip) return prev;
            if (chip.source === 'live') {
                // Toggle planned removal on a live row. If the row had a staged edit,
                // discard that edit and show the original live assignment as removed.
                return {
                    ...prev,
                    [key]: chips.map((c) =>
                        c.id === chipId
                            ? c.action === 'remove'
                                ? {
                                      ...c,
                                      action: undefined,
                                      shiftTypeId: c.originalShiftTypeId ?? c.shiftTypeId,
                                      positionId:
                                          c.originalPositionId !== undefined ? c.originalPositionId : c.positionId,
                                      isFixed: c.originalIsFixed ?? c.isFixed,
                                  }
                                : {
                                      ...c,
                                      action: 'remove',
                                      shiftTypeId: c.originalShiftTypeId ?? c.shiftTypeId,
                                      positionId:
                                          c.originalPositionId !== undefined ? c.originalPositionId : c.positionId,
                                      isFixed: c.originalIsFixed ?? c.isFixed,
                                  }
                            : c,
                    ),
                };
            }
            return { ...prev, [key]: chips.filter((c) => c.id !== chipId) };
        });
    };

    const updateChip = (
        employeeId: number,
        date: string,
        chipId: string,
        shiftTypeId: number,
        positionId: number,
        isFixed: boolean,
    ) => {
        markActivity();
        const key = cellKey(employeeId, date);
        setCells((prev) => ({
            ...prev,
            [key]: (prev[key] ?? []).map((c) => {
                if (c.id !== chipId) return c;
                // Keep the chip's own target totals when the shift type is unchanged;
                // otherwise pull the destination type's totals.
                const totals =
                    shiftTypeId === c.shiftTypeId
                        ? {
                              targetFixedTotal: c.targetFixedTotal ?? null,
                              targetTempTotal: c.targetTempTotal ?? null,
                          }
                        : shiftTotalsFor(date, shiftTypeId);
                if (c.source === 'draft') {
                    return { ...c, shiftTypeId, positionId, isFixed, ...totals };
                }

                const originalShiftTypeId = c.originalShiftTypeId ?? c.shiftTypeId;
                const originalPositionId = c.originalPositionId !== undefined ? c.originalPositionId : c.positionId;
                const originalIsFixed = c.originalIsFixed ?? c.isFixed;
                const changed =
                    shiftTypeId !== originalShiftTypeId ||
                    positionId !== originalPositionId ||
                    isFixed !== originalIsFixed;

                return {
                    ...c,
                    shiftTypeId,
                    positionId,
                    isFixed,
                    action: changed ? 'update' : undefined,
                    originalShiftTypeId,
                    originalPositionId,
                    originalIsFixed,
                    ...totals,
                };
            }),
        }));
    };

    // ----- lock actions -----
    const acquireLock = (force = false) => {
        if (!workplaceId) return;
        markActivity();
        lockMutation.mutate(
            { workplace_id: Number(workplaceId), force },
            {
                onSuccess: (res) => res.data?.lock && setLock(res.data.lock),
                onError: (err: any) => {
                    if (err?.data) setLock({ ...err.data });
                    notifications.show({
                        color: 'orange',
                        message: t('This workplace is being edited by another admin.'),
                    });
                },
            },
        );
    };

    const releaseLock = () => {
        if (!workplaceId) return;
        releaseMutation.mutate(Number(workplaceId), { onSuccess: () => setLock(null) });
    };

    // ----- save -----
    const handleSave = () => {
        if (!data || !workplaceId) return;
        saveMutation.mutate(
            {
                workplace_id: Number(workplaceId),
                from: data.window.from,
                to: data.window.to,
                drafts: buildDraftPayload(cells),
            },
            {
                onSuccess: () => {
                    setSaveOpen(false);
                    notifications.show({ color: 'teal', message: t('Changes saved.') });
                    query.refetch();
                },
                onError: (err: any) => {
                    // The lock only blocks a save when another admin is actively holding it
                    // (a lapsed own-lock is transparently re-acquired server-side). Surface that
                    // clearly and reflect the takeover so the grid drops into read-only.
                    if (err?.message === 'SHIFT_PLANNING_LOCK_REQUIRED') {
                        setSaveOpen(false);
                        notifications.show({
                            color: 'orange',
                            message: t('This workplace is being edited by another admin.'),
                        });
                        query.refetch();
                        return;
                    }
                    const errors: FinalizeErrorEntry[] | undefined = err?.data?.errors;
                    if (!errors || errors.length === 0) {
                        notifications.show({ color: 'red', message: t('Could not save the draft.') });
                        return;
                    }
                    // Blocking save: nothing persisted. Light up the offending cells and list
                    // the reasons so the admin can fix them before retrying (spec §3.3). Scope the
                    // surfaced errors to the cells the admin actually changed this save, so a draft
                    // saved earlier that an over-capacity slot also flags isn't shown or highlighted.
                    const shownErrors = scopeErrorsToDirtyCells(errors, cells, dirtyIds);

                    const failingErrors: Record<string, string> = {};
                    const entries: ValidationErrorEntry[] = shownErrors.map((e) => {
                        const reason = errorReason(e);
                        if (e.user_id) failingErrors[cellKey(e.user_id, e.date)] = reason;
                        return {
                            employeeName: e.user_id ? (employeeName.get(e.user_id) ?? '') : '',
                            dateLabel: dayjs(e.date).format('ddd, MMM D'),
                            reason,
                        };
                    });
                    setActiveErrors(failingErrors);
                    setSaveOpen(false);
                    setSaveErrors(entries);
                },
            },
        );
    };

    const handleDiscard = () => {
        setCells(clone(baseline));
        setActiveErrors({});
    };

    // Stable so the grid's report effect doesn't re-fire on every render.
    const handleSelectionChange = useCallback(
        (targets: { employeeId: number; date: string }[]) => setFinalizeTargets(targets),
        [],
    );

    // ----- finalize -----
    const errorReason = (e: FinalizeErrorEntry): string => {
        const extra = e.extra ?? {};
        switch (e.code) {
            case 'SCHEDULE_SHIFT_OVERLAP':
                return t('Overlaps another shift');
            case 'SCHEDULE_MAX_SHIFTS_EXCEEDED':
                return t('Exceeds max shifts per day');
            case 'SCHEDULE_MAX_WORK_HOURS_EXCEEDED': {
                const over = Number(extra.over_hours);
                return Number.isFinite(over) && over > 0
                    ? t('Exceeds weekly work-hour limit by {{hours}}h', { hours: formatHours(over) })
                    : t('Exceeds weekly work-hour limit');
            }
            case 'SCHEDULE_MIN_REST_HOURS_VIOLATED':
                return t('Not enough rest between shifts');
            case 'SCHEDULE_USER_NOT_MEMBER':
                return t('Not a member of this workplace');
            case 'SCHEDULE_USER_DISABLED':
                return t('Account is disabled');
            case 'SCHEDULE_USER_PAPER_REJECTED':
                return t('Documents have been rejected');
            case 'SCHEDULE_USER_MISSING_STAFF_NO':
                return t('Missing staff number');
            case 'SCHEDULE_EXPIRED_RESIDENCE_CARD':
            case 'SCHEDULE_EXPIRED_PASSPORT':
            case 'SCHEDULE_EXPIRED_STUDENT_CARD':
                return t('Document expired or expiring soon');
            case 'SHIFT_PLANNING_SHIFT_MISSING':
                return t('No concrete shift exists for this date');
            case 'SHIFT_PLANNING_DATE_TOO_SOON':
                return t('Date is before the minimum planning window');
            case 'SCHEDULE_CAPACITY_EXCEEDED':
                return t(
                    '{{type}} over capacity: {{projected}}/{{capacity}} ({{pre}} live + {{planner}} from your plan)',
                    {
                        type: extra.is_fixed ? t('Fixed') : t('Temp'),
                        projected: extra.projected,
                        capacity: extra.capacity,
                        pre: extra.pre_existing,
                        planner: extra.planner,
                    },
                );
            default:
                return t('Validation error');
        }
    };

    const finalizeDisabled = readOnly || dirtyCount > 0 || finalizeTargets.length === 0;

    const handleFinalize = () => {
        if (finalizeDisabled || !workplaceId) return;
        markActivity();
        finalizeMutation.mutate(
            {
                workplace_id: Number(workplaceId),
                cells: finalizeTargets.map((c) => ({ user_id: c.employeeId, date: c.date })),
            },
            {
                onSuccess: (res) => {
                    const results = res.data?.results ?? [];
                    const failingErrors: Record<string, string> = {};
                    const modalResults: FinalizeDateResult[] = results.map((r) => {
                        const errors = r.errors.map((e) => {
                            const reason = errorReason(e);
                            if (e.user_id) failingErrors[cellKey(e.user_id, r.date)] = reason;
                            const who = e.user_id ? `${employeeName.get(e.user_id) ?? ''}: ` : '';
                            return `${who}${reason}`;
                        });
                        return {
                            dateLabel: dayjs(r.date).format('ddd, MMM D'),
                            committed: r.committed,
                            notifiedCount: r.notified_count,
                            errors,
                        };
                    });
                    setActiveErrors(failingErrors);
                    setFinalizeTargets([]);
                    setSelectionResetKey((k) => k + 1);
                    setFinalizeResults(modalResults);
                    query.refetch();
                },
                onError: () => notifications.show({ color: 'red', message: t('Could not finalize.') }),
            },
        );
    };

    // ----- create shift -----
    const handleCreateShift = (values: CreateShiftValues) => {
        if (!pendingCreate) return;
        createShiftMutation.mutate(
            {
                date: pendingCreate.date,
                workplace_shift_id: pendingCreate.shiftTypeId,
                fixed_total: values.fixedTotal,
                temp_total: values.tempTotal,
                max_registered_temp: values.maxRegisteredTemp,
                break_minutes: values.breakMinutes,
            },
            {
                onSuccess: (res) => {
                    const key = shiftSlotKey(pendingCreate.date, pendingCreate.shiftTypeId);
                    setShiftConfigs((prev) => ({
                        ...prev,
                        [key]: {
                            id: res.data?.id ?? 0,
                            date: pendingCreate.date,
                            workplace_shift_id: pendingCreate.shiftTypeId,
                            fixed_total: values.fixedTotal,
                            temp_total: values.tempTotal,
                            max_registered_temp: values.maxRegisteredTemp,
                            break_minutes: values.breakMinutes,
                        },
                    }));
                    addChip(
                        pendingCreate.employeeId,
                        pendingCreate.date,
                        pendingCreate.shiftTypeId,
                        pendingCreate.positionId,
                        pendingCreate.isFixed,
                    );
                    setPendingCreate(null);
                },
                onError: () => notifications.show({ color: 'red', message: t('Could not create the shift.') }),
            },
        );
    };

    // ----- update shift config (from a header summary) -----
    const editingConfig = editingShift ? shiftConfigs[shiftSlotKey(editingShift.date, editingShift.shiftTypeId)] : null;

    const handleUpdateShiftConfig = (values: CreateShiftValues) => {
        if (!editingShift || !editingConfig) return;
        markActivity();
        updateShiftConfigMutation.mutate(
            {
                shiftId: editingConfig.id,
                fixed_total: values.fixedTotal,
                temp_total: values.tempTotal,
                max_registered_temp: values.maxRegisteredTemp,
                break_minutes: values.breakMinutes,
            },
            {
                onSuccess: () => {
                    const key = shiftSlotKey(editingShift.date, editingShift.shiftTypeId);
                    setShiftConfigs((prev) => ({
                        ...prev,
                        [key]: {
                            ...prev[key],
                            fixed_total: values.fixedTotal,
                            temp_total: values.tempTotal,
                            max_registered_temp: values.maxRegisteredTemp,
                            break_minutes: values.breakMinutes,
                        },
                    }));
                    setEditingShift(null);
                    notifications.show({ color: 'teal', message: t('Shift updated.') });
                },
                onError: (error) =>
                    notifications.show({
                        color: 'red',
                        message:
                            WeeklyHourLimitHelper.getLengthenedShiftMessage(error, t) ??
                            t('Could not update the shift.'),
                    }),
            },
        );
    };

    const windowLabel =
        columns.length > 0
            ? `${dayjs(columns[0].date).format('MMM D')} – ${dayjs(columns[columns.length - 1].date).format('MMM D, YYYY')}`
            : '';

    // One-line recap of the page filter shown in the header while it is collapsed.
    const filterSummary = [
        workplaceOptions.find((w) => w.value === workplaceId)?.label,
        memberActivityOptions.find((o) => o.value === memberActivityDays)?.label,
        anchor.format('MMM YYYY'),
    ]
        .filter(Boolean)
        .join(' · ');

    const pendingShiftLabel = pendingCreate ? shiftTimeLabel(pendingCreate.shiftTypeId) : '';

    // ----- header shift summaries -----
    const selectedHeaderShiftIds = useMemo(() => headerShifts.map(Number), [headerShifts]);
    const headerCounts = useMemo(() => buildHeaderCounts(cells), [cells]);
    const shiftConfigsMap = useMemo(() => new Map(Object.entries(shiftConfigs)), [shiftConfigs]);
    // Grouped shift types for the "Show shift totals" selector: those with a concrete shift in the
    // window ("Created") vs. those without.
    const headerShiftGroups = useMemo(
        () => (data ? splitShiftTypesByCreated(shiftTypes, data) : { created: [], notCreated: [] }),
        [data, shiftTypes],
    );

    const editingValues: CreateShiftValues | undefined = editingConfig
        ? {
              fixedTotal: editingConfig.fixed_total,
              tempTotal: editingConfig.temp_total,
              maxRegisteredTemp: editingConfig.max_registered_temp,
              breakMinutes: editingConfig.break_minutes,
          }
        : undefined;

    const legend = (
        <div className={styles.legend} style={{ flexDirection: 'column', alignItems: 'flex-start', gap: 8 }}>
            <span className={styles.legendItem}>
                <span className={styles.legendSwatch} style={{ background: '#12b886' }} />
                {t('Fixed')}
            </span>
            <span className={styles.legendItem}>
                <span className={styles.legendSwatch} style={{ background: '#228be6' }} />
                {t('Temp')}
            </span>
            <span className={styles.legendItem}>
                <span className={styles.legendSwatch} style={{ background: '#fff', border: '1px dashed #868e96' }} />
                {t('Draft (planned)')} · {t('solid = live')}
            </span>
            <span className={styles.legendItem}>
                <span
                    className={styles.legendSwatch}
                    style={{
                        background:
                            'linear-gradient(to bottom, #868e96 calc(50% - 0.5px), #fff calc(50% - 0.5px), #fff calc(50% + 0.5px), #868e96 calc(50% + 0.5px))',
                    }}
                />
                {t('Staged for removal')}
            </span>
            <span className={styles.legendItem}>
                <span
                    style={{
                        width: 8,
                        height: 8,
                        borderRadius: '50%',
                        background: '#f76707',
                        border: '1.5px solid #fff',
                        boxShadow: '0 0 0 1px #f76707',
                    }}
                />
                {t('Unsaved changes')}
            </span>
            <span className={styles.legendItem}>
                <span
                    className={styles.legendSwatch}
                    style={{ background: '#fff5f5', border: '1.5px solid #fa5252' }}
                />
                {t('Validation / conflict error')}
            </span>
            <span className={styles.legendItem}>
                <span className={styles.regularDot} /> {t('Fixed schedule')}
            </span>
            <span className={styles.legendItem}>
                <LuTriangleAlert size={14} color="#f59f00" /> {t('Paper / absence warning')}
            </span>
        </div>
    );

    // Compact lock status for the header row — replaces the full-width banner.
    const lockControl =
        lock && !lock.is_me ? (
            <Group gap={6} wrap="nowrap">
                <Badge color="orange" variant="light" radius="sm" leftSection={<LuLock size={12} />}>
                    {t('Editing by {{name}}', { name: lock.name })}
                </Badge>
                <Button
                    variant="light"
                    color="orange"
                    leftSection={<LuPencil size={14} />}
                    onClick={() => acquireLock(true)}
                >
                    {t('Take over')}
                </Button>
            </Group>
        ) : iHoldLock ? (
            <Group gap={6} wrap="nowrap">
                <Badge color="teal" variant="light" radius="sm" leftSection={<LuPencil size={12} />}>
                    {t('You are editing')}
                </Badge>
                <Button variant="light" color="teal" leftSection={<LuLogOut size={14} />} onClick={releaseLock}>
                    {t('Stop editing')}
                </Button>
            </Group>
        ) : (
            <Group gap={6} wrap="nowrap">
                <Badge color="gray" variant="light" radius="sm" leftSection={<LuEye size={12} />}>
                    {t('View only')}
                </Badge>
                <Button
                    variant="filled"
                    leftSection={<LuPencil size={14} />}
                    loading={lockMutation.isPending}
                    onClick={() => acquireLock(false)}
                >
                    {t('Start editing')}
                </Button>
            </Group>
        );

    return (
        <div onPointerDown={markActivity}>
            <Breadcrumbs>
                <Anchor component={Link} href="/">
                    {t('Home')}
                </Anchor>
                <Text>{t('Shift Planner')}</Text>
            </Breadcrumbs>

            <Paper withBorder mb="md" mt="md">
                <Group
                    justify="space-between"
                    wrap="nowrap"
                    px="md"
                    py="xs"
                    onClick={toggleFilters}
                    style={{ cursor: 'pointer' }}
                >
                    <Group gap="xs" wrap="nowrap" miw={0}>
                        <LuCalendarRange size={20} />
                        <Title order={4}>{t('Shift Planner')}</Title>
                        {!filtersOpen && (
                            <Text size="sm" c="dimmed" lineClamp={1}>
                                {filterSummary}
                            </Text>
                        )}
                    </Group>
                    <ActionIcon
                        variant="subtle"
                        color="gray"
                        aria-label={filtersOpen ? t('Collapse filters') : t('Expand filters')}
                    >
                        {filtersOpen ? <LuChevronUp size={18} /> : <LuChevronDown size={18} />}
                    </ActionIcon>
                </Group>
                <Collapse expanded={filtersOpen}>
                    <Flex gap="md" align="flex-start" wrap="wrap" px="md" pb="md">
                        <Select
                            label={t('Workplace')}
                            data={workplaceOptions}
                            value={workplaceId}
                            onChange={(v) => v && setWorkplaceId(v)}
                            searchable
                            w={CONTROL_W}
                        />
                        <Select
                            label={t('Active within')}
                            data={memberActivityOptions}
                            value={memberActivityDays}
                            onChange={(v) => v && setMemberActivityDays(v)}
                            w={CONTROL_W}
                        />
                        <MonthPickerInput
                            label={t('Planning month')}
                            description={windowLabel}
                            inputWrapperOrder={['label', 'input', 'description', 'error']}
                            valueFormat={t('FORMAT_MONTH')}
                            minDate={earliestAnchor.toDate()}
                            value={anchor.format('YYYY-MM-DD')}
                            onChange={(v) => v && setAnchor(dayjs(v).startOf('month'))}
                            w={CONTROL_W}
                        />
                    </Flex>
                </Collapse>
            </Paper>

            <Flex mb="sm" gap="sm" align="center" justify="space-between" wrap="wrap">
                <Group gap="sm" wrap="wrap">
                    <Popover width={280} position="bottom-start" withArrow shadow="md" trapFocus>
                        <Popover.Target>
                            <Button
                                variant={gridFilterCount > 0 ? 'light' : 'default'}
                                leftSection={<LuListFilter size={16} />}
                            >
                                {t('Filter')}
                                {gridFilterCount > 0 && (
                                    <Badge ml={8} size="sm" circle variant="filled">
                                        {gridFilterCount}
                                    </Badge>
                                )}
                            </Button>
                        </Popover.Target>
                        <Popover.Dropdown>
                            <Stack gap="sm">
                                <Select
                                    size="xs"
                                    label={t('Display shift')}
                                    data={[
                                        { value: '', label: t('All shifts') },
                                        ...shiftTypes.map((s) => ({ value: s.id.toString(), label: s.label })),
                                    ]}
                                    value={shiftFilter ?? ''}
                                    onChange={(v) => setShiftFilter(v ? v : null)}
                                />
                                <TextInput
                                    size="xs"
                                    label={t('Search employee')}
                                    placeholder={t('Name or staff no.')}
                                    leftSection={<LuSearch size={14} />}
                                    value={filters.search}
                                    onChange={(e) => setFilters({ ...filters, search: e.currentTarget.value })}
                                    rightSection={
                                        filters.search ? (
                                            <CloseButton
                                                size="sm"
                                                aria-label={t('Clear search')}
                                                onClick={() => setFilters({ ...filters, search: '' })}
                                            />
                                        ) : undefined
                                    }
                                />
                                <div>
                                    <Text size="xs" c="dimmed" mb={4}>
                                        {t('Worktime group')}
                                    </Text>
                                    <Chip.Group
                                        multiple
                                        value={filters.worktimeGroups}
                                        onChange={(v) =>
                                            setFilters({ ...filters, worktimeGroups: v as WorktimeGroup[] })
                                        }
                                    >
                                        <Group gap={6}>
                                            {WORKTIME_GROUPS.map((g) => (
                                                <Chip key={g} size="xs" value={g}>
                                                    {worktimeGroupLabel(g, t)}
                                                </Chip>
                                            ))}
                                        </Group>
                                    </Chip.Group>
                                </div>
                                <Switch
                                    size="xs"
                                    label={t('Fixed schedule only')}
                                    checked={filters.fixedScheduleOnly}
                                    onChange={(e) =>
                                        setFilters({ ...filters, fixedScheduleOnly: e.currentTarget.checked })
                                    }
                                />
                                <Button
                                    size="xs"
                                    variant="subtle"
                                    color="gray"
                                    disabled={gridFilterCount === 0}
                                    onClick={clearGridFilters}
                                >
                                    {t('Clear filters')}
                                </Button>
                            </Stack>
                        </Popover.Dropdown>
                    </Popover>

                    <Popover width={300} position="bottom-start" withArrow shadow="md" trapFocus>
                        <Popover.Target>
                            <Button
                                variant={headerShifts.length > 0 ? 'light' : 'default'}
                                leftSection={<LuEye size={16} />}
                            >
                                {t('Show shift totals')}
                                {headerShifts.length > 0 && (
                                    <Badge ml={8} size="sm" circle variant="filled">
                                        {headerShifts.length}
                                    </Badge>
                                )}
                            </Button>
                        </Popover.Target>
                        <Popover.Dropdown>
                            <Stack gap="sm">
                                <div style={{ maxHeight: 280, overflowY: 'auto' }}>
                                    <Chip.Group multiple value={headerShifts} onChange={setHeaderShifts}>
                                        <Stack gap="xs">
                                            {headerShiftGroups.created.length > 0 && (
                                                <div>
                                                    <Text size="xs" c="dimmed" mb={4}>
                                                        {t('Created')}
                                                    </Text>
                                                    <Group gap={6}>
                                                        {headerShiftGroups.created.map((s) => (
                                                            <Chip key={s.id} size="xs" value={String(s.id)}>
                                                                {s.label}
                                                            </Chip>
                                                        ))}
                                                    </Group>
                                                </div>
                                            )}
                                            {headerShiftGroups.notCreated.length > 0 && (
                                                <div>
                                                    <Text size="xs" c="dimmed" mb={4}>
                                                        {t('Not created')}
                                                    </Text>
                                                    <Group gap={6}>
                                                        {headerShiftGroups.notCreated.map((s) => (
                                                            <Chip key={s.id} size="xs" value={String(s.id)}>
                                                                {s.label}
                                                            </Chip>
                                                        ))}
                                                    </Group>
                                                </div>
                                            )}
                                        </Stack>
                                    </Chip.Group>
                                </div>
                                {headerShifts.length > 0 && (
                                    <Button size="xs" variant="subtle" color="gray" onClick={() => setHeaderShifts([])}>
                                        {t('Clear')}
                                    </Button>
                                )}
                            </Stack>
                        </Popover.Dropdown>
                    </Popover>

                    <Popover width={300} position="bottom-start" withArrow shadow="md">
                        <Popover.Target>
                            <ActionIcon variant="subtle" color="gray" size="lg" aria-label={t('Legend')}>
                                <LuCircleHelp size={18} />
                            </ActionIcon>
                        </Popover.Target>
                        <Popover.Dropdown>
                            <Text size="xs" fw={600} mb={8}>
                                {t('Legend')}
                            </Text>
                            {legend}
                        </Popover.Dropdown>
                    </Popover>
                </Group>

                <Group gap="sm" wrap="wrap">
                    {lockControl}
                    <Divider orientation="vertical" />
                    <Button
                        variant="default"
                        leftSection={<LuRotateCcw size={16} />}
                        disabled={readOnly || dirtyCount === 0}
                        onClick={handleDiscard}
                    >
                        {t('Discard')}
                    </Button>
                    <Button
                        leftSection={<LuSave size={16} />}
                        color="teal"
                        disabled={readOnly || dirtyCount === 0}
                        loading={saveMutation.isPending}
                        onClick={() => setSaveOpen(true)}
                    >
                        {t('Save changes')}
                        {dirtyCount > 0 && (
                            <Badge ml={8} size="sm" circle variant="white" color="teal">
                                {dirtyCount}
                            </Badge>
                        )}
                    </Button>
                    <Button
                        color="blue"
                        leftSection={<LuSquareCheck size={16} />}
                        disabled={finalizeDisabled}
                        loading={finalizeMutation.isPending}
                        onClick={handleFinalize}
                    >
                        {t('Finalize selected')} ({finalizeTargets.length})
                    </Button>
                </Group>
            </Flex>

            <Paper withBorder radius="md" style={{ overflow: 'hidden' }}>
                {query.isLoading ? (
                    <PlanGridSkeleton />
                ) : (
                    <PlanGrid
                        columns={columns}
                        employees={filteredEmployees}
                        cells={cells}
                        dirtyIds={dirtyIds}
                        errors={activeErrors}
                        readOnly={readOnly}
                        shiftFilter={shiftFilter ? Number(shiftFilter) : null}
                        selectedHeaderShifts={selectedHeaderShiftIds}
                        shiftConfigs={shiftConfigsMap}
                        headerCounts={headerCounts}
                        employeeFiltersActive={employeeFiltersActive}
                        recentDays={data?.window.recent_shift_days ?? Number(memberActivityDays)}
                        clipboardScope={`${workplaceId ?? ''}|${data?.window.from ?? from}|${data?.window.to ?? ''}`}
                        selectionResetKey={selectionResetKey}
                        shiftExists={(date, shiftTypeId) => !!shiftConfigs[shiftSlotKey(date, shiftTypeId)]}
                        onSelectionChange={handleSelectionChange}
                        onEditShiftConfig={
                            readOnly ? undefined : (date, shiftTypeId) => setEditingShift({ date, shiftTypeId })
                        }
                        onAdd={addChip}
                        onUpdate={updateChip}
                        onRemove={removeChip}
                        onPaste={pasteChips}
                        onRequestCreateShift={(employeeId, date, shiftTypeId, positionId, isFixed) =>
                            setPendingCreate({ employeeId, date, shiftTypeId, positionId, isFixed })
                        }
                    />
                )}
            </Paper>

            <SaveConfirmModal
                opened={saveOpen}
                onClose={() => setSaveOpen(false)}
                onConfirm={handleSave}
                entries={diff}
            />

            <ValidationErrorsModal
                opened={saveErrors !== null}
                onClose={() => setSaveErrors(null)}
                entries={saveErrors ?? []}
                trigger="save"
            />

            <FinalizeResultModal
                opened={finalizeResults !== null}
                onClose={() => setFinalizeResults(null)}
                results={finalizeResults ?? []}
            />

            <CreateShiftModal
                opened={pendingCreate !== null}
                onClose={() => setPendingCreate(null)}
                date={pendingCreate?.date ?? ''}
                shiftTypeLabel={pendingShiftLabel}
                loading={createShiftMutation.isPending}
                onConfirm={handleCreateShift}
            />

            <CreateShiftModal
                opened={editingShift !== null}
                onClose={() => setEditingShift(null)}
                date={editingShift?.date ?? ''}
                shiftTypeLabel={editingShift ? shiftTimeLabel(editingShift.shiftTypeId) : ''}
                title={t('Update shift')}
                initialValues={editingValues}
                loading={updateShiftConfigMutation.isPending}
                onConfirm={handleUpdateShiftConfig}
            />
        </div>
    );
};

export default IndexPage;
