import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import dayjs from 'dayjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CellEditor from '@/pages/manage/shift-planning/partials/cell_editor';
import { PlanChip, PlanEmployee, setRegistries } from '@/pages/manage/shift-planning/partials/data';

const { chipSpy } = vi.hoisted(() => ({ chipSpy: vi.fn() }));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/pages/manage/shift-planning/partials/chip', () => ({
    default: (props: any) => {
        chipSpy(props);
        return React.createElement(
            'div',
            { 'data-testid': `chip-${props.chip.id}`, 'data-selected': String(!!props.selected) },
            React.createElement('button', { type: 'button', onClick: props.onSelect }, `select-${props.chip.id}`),
            props.onRemove &&
                React.createElement('button', { type: 'button', onClick: props.onRemove }, `remove-${props.chip.id}`),
        );
    },
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children }: any) => React.createElement('div', { role: 'alert' }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Box: ({ title }: any) => React.createElement('div', { title }),
    Button: ({ children, onClick, disabled, variant }: any) =>
        React.createElement('button', { 'type': 'button', onClick, disabled, 'data-variant': variant }, children),
    Divider: ({ label }: any) => React.createElement('div', null, label),
    Group: ({ children }: any) => React.createElement('div', null, children),
    SegmentedControl: ({ value, onChange, data }: any) =>
        React.createElement(
            'div',
            { role: 'radiogroup' },
            data.map((d: any) =>
                React.createElement(
                    'button',
                    {
                        'key': d.value,
                        'type': 'button',
                        'aria-pressed': value === d.value,
                        'onClick': () => onChange(d.value),
                    },
                    d.label,
                ),
            ),
        ),
    Select: ({ placeholder, value, onChange, data }: any) =>
        React.createElement(
            'select',
            {
                'aria-label': placeholder,
                'value': value ?? '',
                'onChange': (e: React.ChangeEvent<HTMLSelectElement>) => onChange(e.target.value || null),
            },
            React.createElement('option', { value: '' }, ''),
            (data ?? []).map((d: any) =>
                d.items
                    ? React.createElement(
                          'optgroup',
                          { key: d.group, label: d.group },
                          d.items.map((opt: any) =>
                              React.createElement('option', { key: opt.value, value: opt.value }, opt.label),
                          ),
                      )
                    : React.createElement('option', { key: d.value, value: d.value }, d.label),
            ),
        ),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

const MORNING_SHIFT = { id: 1, label: 'Morning', startTime: '09:00', endTime: '18:00', isActive: true };
const NIGHT_SHIFT = { id: 2, label: 'Night', startTime: '22:00', endTime: '06:00', isActive: true };
const STAFF_POSITION = { id: 1, name: 'Staff', isActive: true };
const LEADER_POSITION = { id: 2, name: 'Leader', isActive: true };

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

const buildChip = (overrides: Partial<PlanChip> = {}): PlanChip => ({
    id: 'chip-1',
    shiftTypeId: 1,
    positionId: 1,
    isFixed: true,
    source: 'draft',
    ...overrides,
});

const DATE = dayjs('2026-06-26'); // a Friday

const baseProps = {
    employee: buildEmployee(),
    date: DATE,
    readOnly: false,
    chips: [] as PlanChip[],
    dirtyIds: new Set<string>(),
    shiftExists: () => true,
    onAdd: vi.fn(),
    onUpdate: vi.fn(),
    onRemove: vi.fn(),
    onRequestCreateShift: vi.fn(),
};

describe('CellEditor', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setRegistries([MORNING_SHIFT, NIGHT_SHIFT], [STAFF_POSITION, LEADER_POSITION]);
    });

    it('renders employee info: name, staff no, worktime, and formatted date', () => {
        render(<CellEditor {...baseProps} />);

        expect(screen.getByText('Yamada Taro')).toBeInTheDocument();
        expect(screen.getByText('001')).toBeInTheDocument();
        expect(screen.getAllByText('Morning').length).toBeGreaterThan(0);
        expect(screen.getByText('Friday, June 26, 2026')).toBeInTheDocument();
    });

    it('shows the fixed-schedule marker and schedule lines when the employee has one', () => {
        render(
            <CellEditor
                {...baseProps}
                employee={buildEmployee({
                    hasFixedSchedule: true,
                    fixedSchedules: [{ weekday: 5, start: '09:00', end: '18:00' }],
                })}
            />,
        );

        expect(screen.getByTitle('Fixed schedule')).toBeInTheDocument();
        expect(screen.getByText(/09:00-18:00/)).toBeInTheDocument();
    });

    it('shows employee warnings', () => {
        render(<CellEditor {...baseProps} employee={buildEmployee({ paperRejected: true })} />);

        expect(screen.getByText('Paper has been rejected')).toBeInTheDocument();
    });

    it('shows the error alert when errorReason is provided', () => {
        render(<CellEditor {...baseProps} errorReason="Overlap detected" />);

        expect(screen.getByRole('alert')).toHaveTextContent('Overlap detected');
    });

    it('shows the View only badge and hides the edit form when readOnly', () => {
        render(<CellEditor {...baseProps} readOnly chips={[buildChip()]} />);

        expect(screen.getByText('View only')).toBeInTheDocument();
        expect(screen.queryByLabelText('Shift type')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add shift' })).not.toBeInTheDocument();
    });

    it('does not pass onSelect/onRemove to Chip when readOnly', () => {
        render(<CellEditor {...baseProps} readOnly chips={[buildChip()]} />);

        expect(chipSpy).toHaveBeenCalledWith(expect.objectContaining({ onSelect: undefined, onRemove: undefined }));
    });

    it('selects the first chip by default and prefills the edit form from it', () => {
        render(<CellEditor {...baseProps} chips={[buildChip({ id: 'a', shiftTypeId: 2, positionId: 2 })]} />);

        expect(screen.getByTestId('chip-a')).toHaveAttribute('data-selected', 'true');
        expect(screen.getByLabelText('Shift type')).toHaveValue('2');
        expect(screen.getByLabelText('Position')).toHaveValue('2');
        expect(screen.getByText('Edit assignment')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Update' })).toBeInTheDocument();
    });

    it('shows Add assignment and no preselected chip when there are no chips', () => {
        render(<CellEditor {...baseProps} />);

        expect(screen.getByText('Add assignment')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();
    });

    it('splits shift type options into Created and Not yet created groups', () => {
        render(
            <CellEditor
                {...baseProps}
                shiftExists={(id) => id === 1}
                chips={[buildChip({ id: 'a', shiftTypeId: 1 })]}
            />,
        );

        const select = screen.getByLabelText('Shift type');
        const groups = select.querySelectorAll('optgroup');
        expect(groups).toHaveLength(2);
        expect(groups[0]).toHaveAttribute('label', 'Created');
        expect(groups[1]).toHaveAttribute('label', 'Not yet created');
    });

    it('re-derives Fixed/Temp from the matching fixed schedule when adding and picking a shift type', async () => {
        const user = userEvent.setup();
        render(
            <CellEditor
                {...baseProps}
                employee={buildEmployee({ fixedSchedules: [{ weekday: 5, start: '09:00', end: '18:00' }] })}
            />,
        );

        await user.selectOptions(screen.getByLabelText('Shift type'), '1');

        expect(screen.getByRole('button', { name: 'Fixed', pressed: true })).toBeInTheDocument();
    });

    it('does not re-derive Fixed/Temp when editing an existing chip', async () => {
        const user = userEvent.setup();
        render(
            <CellEditor
                {...baseProps}
                employee={buildEmployee({ fixedSchedules: [{ weekday: 5, start: '09:00', end: '18:00' }] })}
                chips={[buildChip({ id: 'a', shiftTypeId: 2, isFixed: false })]}
            />,
        );

        expect(screen.getByRole('button', { name: 'Temp', pressed: true })).toBeInTheDocument();

        await user.selectOptions(screen.getByLabelText('Shift type'), '1');

        expect(screen.getByRole('button', { name: 'Temp', pressed: true })).toBeInTheDocument();
    });

    it('switches to add mode when Add shift is clicked', async () => {
        const user = userEvent.setup();
        render(<CellEditor {...baseProps} chips={[buildChip({ id: 'a' })]} />);

        await user.click(screen.getByRole('button', { name: 'Add shift' }));

        expect(screen.getByText('Add assignment')).toBeInTheDocument();
        expect(screen.getByLabelText('Shift type')).toHaveValue('');
    });

    it('calls onUpdate with the current form values when submitting while editing', async () => {
        const user = userEvent.setup();
        const onUpdate = vi.fn();
        render(
            <CellEditor
                {...baseProps}
                onUpdate={onUpdate}
                chips={[buildChip({ id: 'a', shiftTypeId: 1, positionId: 1, isFixed: true })]}
            />,
        );

        await user.selectOptions(screen.getByLabelText('Position'), '2');
        await user.click(screen.getByRole('button', { name: 'Update' }));

        expect(onUpdate).toHaveBeenCalledWith('a', 1, 2, true);
    });

    it('calls onAdd when submitting a new assignment for a shift that already exists', async () => {
        const user = userEvent.setup();
        const onAdd = vi.fn();
        render(<CellEditor {...baseProps} onAdd={onAdd} shiftExists={() => true} />);

        await user.selectOptions(screen.getByLabelText('Shift type'), '1');
        await user.click(screen.getByRole('button', { name: 'Add' }));

        expect(onAdd).toHaveBeenCalledWith(1, 1, expect.any(Boolean));
    });

    it('calls onRequestCreateShift and shows the create-shift hint when adding for a shift that does not exist yet', async () => {
        const user = userEvent.setup();
        const onRequestCreateShift = vi.fn();
        render(<CellEditor {...baseProps} onRequestCreateShift={onRequestCreateShift} shiftExists={() => false} />);

        await user.selectOptions(screen.getByLabelText('Shift type'), '1');

        expect(
            screen.getByText('No shift exists for this date yet — Add will open the create-shift form.'),
        ).toBeInTheDocument();
        const submitButton = screen.getByRole('button', { name: 'Create shift & add' });
        expect(submitButton).toHaveAttribute('data-variant', 'light');

        await user.click(submitButton);

        expect(onRequestCreateShift).toHaveBeenCalledWith(1, 1, expect.any(Boolean));
    });

    it('disables submit until a shift type and position are selected', () => {
        render(<CellEditor {...baseProps} />);

        expect(screen.getByRole('button', { name: 'Add' })).toBeDisabled();
    });

    it('re-selects the next remaining chip after removing the selected draft chip', async () => {
        const user = userEvent.setup();
        const onRemove = vi.fn();
        render(
            <CellEditor
                {...baseProps}
                onRemove={onRemove}
                chips={[
                    buildChip({ id: 'a', shiftTypeId: 1, source: 'draft' }),
                    buildChip({ id: 'b', shiftTypeId: 2, source: 'draft' }),
                ]}
            />,
        );
        // 'a' is selected by default.
        expect(screen.getByLabelText('Shift type')).toHaveValue('1');

        await user.click(screen.getByRole('button', { name: 'remove-a' }));

        expect(onRemove).toHaveBeenCalledWith('a');
        expect(screen.getByLabelText('Shift type')).toHaveValue('2');
    });

    it('falls back to add mode after removing the last remaining draft chip', async () => {
        const user = userEvent.setup();
        const onRemove = vi.fn();
        render(<CellEditor {...baseProps} onRemove={onRemove} chips={[buildChip({ id: 'a', source: 'draft' })]} />);

        await user.click(screen.getByRole('button', { name: 'remove-a' }));

        expect(onRemove).toHaveBeenCalledWith('a');
        expect(screen.getByText('Add assignment')).toBeInTheDocument();
    });

    it('does not change the selected chip after removing a selected live chip', async () => {
        const user = userEvent.setup();
        const onRemove = vi.fn();
        render(<CellEditor {...baseProps} onRemove={onRemove} chips={[buildChip({ id: 'a', source: 'live' })]} />);

        await user.click(screen.getByRole('button', { name: 'remove-a' }));

        expect(onRemove).toHaveBeenCalledWith('a');
        expect(screen.getByText('Edit assignment')).toBeInTheDocument();
    });
});
