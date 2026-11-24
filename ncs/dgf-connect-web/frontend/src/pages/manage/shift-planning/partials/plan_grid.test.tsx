import React from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import dayjs from 'dayjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PlanChip, PlanColumn, PlanEmployee, setRegistries } from '@/pages/manage/shift-planning/partials/data';
import PlanGrid from '@/pages/manage/shift-planning/partials/plan_grid';
import { ApiConcreteShift } from '@/pages/manage/shift-planning/partials/api';

const { dataEditorSpy, cellEditorSpy, employeeInfoSpy, notificationsShowMock } = vi.hoisted(() => ({
    dataEditorSpy: vi.fn(),
    cellEditorSpy: vi.fn(),
    employeeInfoSpy: vi.fn(),
    notificationsShowMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@glideapps/glide-data-grid', () => {
    const CompactSelection = { empty: () => ({ items: [] }) };
    const GridCellKind = { Custom: 'custom' };
    const DataEditor = React.forwardRef((props: any, ref: any) => {
        dataEditorSpy(props);
        React.useImperativeHandle(ref, () => ({
            getBounds: () => ({ x: 100, y: 200, width: 150, height: 34 }),
        }));
        return React.createElement('div', { 'data-testid': 'data-editor' });
    });
    return { CompactSelection, GridCellKind, DataEditor };
});

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled }, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Stack: ({ children }: any) => React.createElement('div', null, children),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: { show: notificationsShowMock },
}));

vi.mock('@/pages/manage/shift-planning/partials/cell_editor', () => ({
    default: (props: any) => {
        cellEditorSpy(props);
        return React.createElement(
            'div',
            { 'data-testid': 'cell-editor' },
            React.createElement('button', { type: 'button', onClick: () => props.onAdd(1, 2, true) }, 'trigger-add'),
            React.createElement(
                'button',
                { type: 'button', onClick: () => props.onUpdate('chip-1', 1, 2, true) },
                'trigger-update',
            ),
            React.createElement(
                'button',
                { type: 'button', onClick: () => props.onRemove('chip-1') },
                'trigger-remove',
            ),
            React.createElement(
                'button',
                { type: 'button', onClick: () => props.onRequestCreateShift(1, 2, true) },
                'trigger-create',
            ),
        );
    },
}));

vi.mock('@/pages/manage/shift-planning/partials/employee_info', () => ({
    default: (props: any) => {
        employeeInfoSpy(props);
        return React.createElement('div', { 'data-testid': 'employee-info' }, props.employee.name);
    },
}));

const MORNING_SHIFT = { id: 1, label: 'Morning', startTime: '09:00', endTime: '18:00', isActive: true };
const STAFF_POSITION = { id: 1, name: 'Staff', isActive: true };

const buildEmployee = (overrides: Partial<PlanEmployee> = {}): PlanEmployee => ({
    id: 1,
    name: 'Yamada Taro',
    staffNo: '001',
    hasFixedSchedule: false,
    worktimeGroup: 'morning',
    fixedSchedules: [],
    absenceCount: 0,
    paperRejected: false,
    paperStatus: 'approved',
    expiringCards: [],
    recentShiftCount: 0,
    ...overrides,
});

const buildColumn = (date: string, overrides: Partial<PlanColumn> = {}): PlanColumn => ({
    date,
    day: dayjs(date),
    isWeekend: false,
    monthLabel: dayjs(date).format('YYYY MMM'),
    ...overrides,
});

const buildChip = (overrides: Partial<PlanChip> = {}): PlanChip => ({
    id: 'chip-1',
    shiftTypeId: 1,
    positionId: 1,
    isFixed: true,
    source: 'live',
    draftId: undefined,
    ...overrides,
});

const baseProps = () => ({
    columns: [buildColumn('2026-06-22'), buildColumn('2026-06-23')],
    employees: [buildEmployee()],
    cells: {} as Record<string, PlanChip[]>,
    dirtyIds: new Set<string>(),
    errors: {} as Record<string, string>,
    readOnly: false,
    shiftFilter: null,
    selectedHeaderShifts: [] as number[],
    shiftConfigs: new Map<string, ApiConcreteShift>(),
    headerCounts: new Map(),
    employeeFiltersActive: false,
    recentDays: 30,
    clipboardScope: 'scope-1',
    selectionResetKey: 0,
    shiftExists: () => true,
    onSelectionChange: vi.fn(),
    onAdd: vi.fn(),
    onUpdate: vi.fn(),
    onRemove: vi.fn(),
    onPaste: vi.fn(),
    onRequestCreateShift: vi.fn(),
});

const lastEditorProps = () => dataEditorSpy.mock.calls[dataEditorSpy.mock.calls.length - 1][0];

describe('PlanGrid', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setRegistries([MORNING_SHIFT], [STAFF_POSITION]);
    });

    it('titles the employee column with the sort indicator and filtered hint', () => {
        render(<PlanGrid {...baseProps()} employeeFiltersActive />);

        const [empCol] = lastEditorProps().columns;
        expect(empCol.title).toBe('Employee (filtered) ↓');
    });

    it('titles date columns as MM/DD (weekday) grouped by month', () => {
        render(<PlanGrid {...baseProps()} />);

        const [, firstDateCol] = lastEditorProps().columns;
        expect(firstDateCol.title).toBe('06/22 (Mon)');
        expect(firstDateCol.group).toBe('2026 Jun');
    });

    it('builds the employee cell content with warnings and shift count', () => {
        render(<PlanGrid {...baseProps()} employees={[buildEmployee({ paperRejected: true, recentShiftCount: 5 })]} />);

        const cell = lastEditorProps().getCellContent([0, 0]);
        expect(cell.data.variant).toBe('employee');
        expect(cell.data.shiftCountLabel).toBe('5 shifts');
        expect(cell.data.warningColors).toHaveLength(1);
    });

    it('builds a plan cell with the chips for that employee/date', () => {
        const chip = buildChip();
        render(<PlanGrid {...baseProps()} cells={{ '1|2026-06-22': [chip] }} />);

        const cell = lastEditorProps().getCellContent([1, 0]);
        expect(cell.data.variant).toBe('plan');
        expect(cell.data.chips).toEqual([chip]);
    });

    it('filters plan-cell chips by the active shiftFilter', () => {
        const chips = [buildChip({ id: 'a', shiftTypeId: 1 }), buildChip({ id: 'b', shiftTypeId: 2 })];
        render(<PlanGrid {...baseProps()} cells={{ '1|2026-06-22': chips }} shiftFilter={1} />);

        const cell = lastEditorProps().getCellContent([1, 0]);
        expect(cell.data.chips.map((c: PlanChip) => c.id)).toEqual(['a']);
    });

    it('only reports an error on a plan cell when it actually has chips', () => {
        render(<PlanGrid {...baseProps()} cells={{ '1|2026-06-22': [] }} errors={{ '1|2026-06-22': 'Overlap' }} />);

        const cell = lastEditorProps().getCellContent([1, 0]);
        expect(cell.data.error).toBeUndefined();
    });

    it('sorts employees by recent shift count descending by default', () => {
        render(
            <PlanGrid
                {...baseProps()}
                employees={[
                    buildEmployee({ id: 1, name: 'Low', recentShiftCount: 1 }),
                    buildEmployee({ id: 2, name: 'High', recentShiftCount: 9 }),
                ]}
            />,
        );

        expect(lastEditorProps().getCellContent([0, 0]).data.employee.name).toBe('High');
    });

    it('toggles back to caller order when the employee header is clicked', () => {
        render(
            <PlanGrid
                {...baseProps()}
                employees={[
                    buildEmployee({ id: 1, name: 'Low', recentShiftCount: 1 }),
                    buildEmployee({ id: 2, name: 'High', recentShiftCount: 9 }),
                ]}
            />,
        );

        act(() => lastEditorProps().onHeaderClicked(0, { localEventY: 0 }));

        expect(lastEditorProps().getCellContent([0, 0]).data.employee.name).toBe('Low');
        expect(lastEditorProps().columns[0].title).toBe('Employee');
    });

    it('opens the employee info panel when the employee cell is clicked', () => {
        render(<PlanGrid {...baseProps()} />);

        act(() => lastEditorProps().onCellClicked([0, 0]));

        expect(screen.getByTestId('employee-info')).toHaveTextContent('Yamada Taro');
    });

    it('opens the cell editor when a plan cell is clicked, wired to the right employee/date', () => {
        render(<PlanGrid {...baseProps()} />);

        act(() => lastEditorProps().onCellClicked([2, 0]));

        expect(screen.getByTestId('cell-editor')).toBeInTheDocument();
        expect(cellEditorSpy).toHaveBeenCalledWith(
            expect.objectContaining({ employee: expect.objectContaining({ id: 1 }) }),
        );
    });

    it('forwards onAdd/onUpdate/onRemove/onRequestCreateShift from the cell editor with employeeId and date bound in', async () => {
        const user = userEvent.setup();
        const onAdd = vi.fn();
        const onUpdate = vi.fn();
        const onRemove = vi.fn();
        const onRequestCreateShift = vi.fn();
        render(
            <PlanGrid
                {...baseProps()}
                onAdd={onAdd}
                onUpdate={onUpdate}
                onRemove={onRemove}
                onRequestCreateShift={onRequestCreateShift}
            />,
        );

        act(() => lastEditorProps().onCellClicked([1, 0]));
        await user.click(screen.getByRole('button', { name: 'trigger-add' }));
        await user.click(screen.getByRole('button', { name: 'trigger-update' }));
        await user.click(screen.getByRole('button', { name: 'trigger-remove' }));
        await user.click(screen.getByRole('button', { name: 'trigger-create' }));

        expect(onAdd).toHaveBeenCalledWith(1, '2026-06-22', 1, 2, true);
        expect(onUpdate).toHaveBeenCalledWith(1, '2026-06-22', 'chip-1', 1, 2, true);
        expect(onRemove).toHaveBeenCalledWith(1, '2026-06-22', 'chip-1');
        expect(onRequestCreateShift).toHaveBeenCalledWith(1, '2026-06-22', 1, 2, true);
    });

    it('opens the shift config editor from a header click on an existing shift summary zone', () => {
        const onEditShiftConfig = vi.fn();
        render(
            <PlanGrid
                {...baseProps()}
                selectedHeaderShifts={[1]}
                shiftConfigs={
                    new Map([
                        [
                            '2026-06-22|1',
                            { fixed_total: 2, temp_total: 1, max_registered_temp: null } as ApiConcreteShift,
                        ],
                    ])
                }
                onEditShiftConfig={onEditShiftConfig}
            />,
        );

        act(() => lastEditorProps().onHeaderClicked(1, { localEventY: 30 }));

        expect(onEditShiftConfig).toHaveBeenCalledWith('2026-06-22', 1);
    });

    it('does not open shift config for a date header click above the shift summary zone; selects the column instead', () => {
        const onEditShiftConfig = vi.fn();
        const onSelectionChange = vi.fn();
        render(
            <PlanGrid
                {...baseProps()}
                selectedHeaderShifts={[1]}
                onEditShiftConfig={onEditShiftConfig}
                onSelectionChange={onSelectionChange}
                cells={{ '1|2026-06-22': [buildChip({ source: 'draft', draftId: 5 })] }}
            />,
        );

        act(() => lastEditorProps().onHeaderClicked(1, { localEventY: 0 }));

        expect(onEditShiftConfig).not.toHaveBeenCalled();
        expect(onSelectionChange).toHaveBeenCalledWith([{ employeeId: 1, date: '2026-06-22' }]);
    });

    it('reports only finalizable cells within the selected column', () => {
        const onSelectionChange = vi.fn();
        render(
            <PlanGrid
                {...baseProps()}
                onSelectionChange={onSelectionChange}
                cells={{
                    '1|2026-06-22': [buildChip({ source: 'draft', draftId: 5 })],
                    '1|2026-06-23': [buildChip({ source: 'live', action: undefined })],
                }}
            />,
        );

        act(() => lastEditorProps().onHeaderClicked(1, { localEventY: 0 }));
        expect(onSelectionChange).toHaveBeenLastCalledWith([{ employeeId: 1, date: '2026-06-22' }]);

        onSelectionChange.mockClear();
        act(() => lastEditorProps().onHeaderClicked(2, { localEventY: 0 }));
        expect(onSelectionChange).toHaveBeenLastCalledWith([]);
    });

    it('copies the selected range via the context menu and notifies success', () => {
        render(<PlanGrid {...baseProps()} cells={{ '1|2026-06-22': [buildChip()] }} />);

        act(() =>
            lastEditorProps().onGridSelectionChange({
                current: { cell: [1, 0], range: { x: 1, y: 0, width: 1, height: 1 }, rangeStack: [] },
                columns: {},
                rows: {},
            }),
        );
        act(() =>
            lastEditorProps().onCellContextMenu([1, 0], {
                preventDefault: vi.fn(),
                bounds: { x: 10, y: 20, width: 100, height: 30 },
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Copy' }));

        expect(notificationsShowMock).toHaveBeenCalledWith({ color: 'teal', message: 'Copied' });
    });

    it('shows a "nothing to copy" notice when copying an empty selection', () => {
        render(<PlanGrid {...baseProps()} />);

        act(() =>
            lastEditorProps().onCellContextMenu([1, 0], {
                preventDefault: vi.fn(),
                bounds: { x: 10, y: 20, width: 100, height: 30 },
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Copy' }));

        expect(notificationsShowMock).toHaveBeenCalledWith({ color: 'gray', message: 'Nothing to copy.' });
    });

    it('pastes a copied cell into the target and calls onPaste with resolved targets', () => {
        const onPaste = vi.fn();
        render(<PlanGrid {...baseProps()} onPaste={onPaste} cells={{ '1|2026-06-22': [buildChip()] }} />);

        act(() =>
            lastEditorProps().onCellContextMenu([1, 0], {
                preventDefault: vi.fn(),
                bounds: { x: 10, y: 20, width: 100, height: 30 },
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Copy' }));

        act(() =>
            lastEditorProps().onCellContextMenu([2, 0], {
                preventDefault: vi.fn(),
                bounds: { x: 10, y: 20, width: 100, height: 30 },
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Paste' }));

        expect(onPaste).toHaveBeenCalledWith([{ employeeId: 1, date: '2026-06-23', chips: expect.any(Array) }]);
    });

    it('disables Paste in the context menu when readOnly', () => {
        render(<PlanGrid {...baseProps()} readOnly cells={{ '1|2026-06-22': [buildChip()] }} />);

        act(() =>
            lastEditorProps().onCellContextMenu([1, 0], {
                preventDefault: vi.fn(),
                bounds: { x: 10, y: 20, width: 100, height: 30 },
            }),
        );

        expect(screen.getByRole('button', { name: 'Paste' })).toBeDisabled();
    });

    it('copies and pastes via Ctrl+C / Ctrl+V keyboard shortcuts while the grid is active', () => {
        const onPaste = vi.fn();
        const { container } = render(
            <PlanGrid {...baseProps()} onPaste={onPaste} cells={{ '1|2026-06-22': [buildChip()] }} />,
        );

        fireEvent.mouseDown(container.firstChild as Element);
        act(() =>
            lastEditorProps().onGridSelectionChange({
                current: { cell: [1, 0], range: { x: 1, y: 0, width: 1, height: 1 }, rangeStack: [] },
                columns: {},
                rows: {},
            }),
        );

        act(() => {
            fireEvent.keyDown(window, { key: 'c', ctrlKey: true });
        });
        expect(notificationsShowMock).toHaveBeenCalledWith({ color: 'teal', message: 'Copied' });

        act(() => {
            fireEvent.keyDown(window, { key: 'v', ctrlKey: true });
        });
        expect(onPaste).toHaveBeenCalledWith([{ employeeId: 1, date: '2026-06-22', chips: expect.any(Array) }]);
    });

    it('clears the clipboard when clipboardScope changes', () => {
        const onPaste = vi.fn();
        const { rerender } = render(
            <PlanGrid
                {...baseProps()}
                onPaste={onPaste}
                cells={{ '1|2026-06-22': [buildChip()] }}
                clipboardScope="a"
            />,
        );

        act(() =>
            lastEditorProps().onCellContextMenu([1, 0], {
                preventDefault: vi.fn(),
                bounds: { x: 10, y: 20, width: 100, height: 30 },
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Copy' }));

        rerender(
            <PlanGrid
                {...baseProps()}
                onPaste={onPaste}
                cells={{ '1|2026-06-22': [buildChip()] }}
                clipboardScope="b"
            />,
        );

        act(() =>
            lastEditorProps().onCellContextMenu([2, 0], {
                preventDefault: vi.fn(),
                bounds: { x: 10, y: 20, width: 100, height: 30 },
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Paste' }));

        expect(onPaste).not.toHaveBeenCalled();
    });

    it('closes open panels and clears selection when selectionResetKey changes', () => {
        const { rerender } = render(<PlanGrid {...baseProps()} selectionResetKey={0} />);

        act(() => lastEditorProps().onCellClicked([0, 0]));
        expect(screen.getByTestId('employee-info')).toBeInTheDocument();

        rerender(<PlanGrid {...baseProps()} selectionResetKey={1} />);

        expect(screen.queryByTestId('employee-info')).not.toBeInTheDocument();
    });
});
