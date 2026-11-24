import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import FixedScheduleCreateUpdate from './fixed_schedule_create_update';

const {
    useCreateFixedScheduleMutationMock,
    useUpdateFixedScheduleMutationMock,
    useGetWorkplaceShiftsQueryMock,
    useGetWorkplacePositionsQueryMock,
    notificationsMock,
} = vi.hoisted(() => ({
    useCreateFixedScheduleMutationMock: vi.fn(),
    useUpdateFixedScheduleMutationMock: vi.fn(),
    useGetWorkplaceShiftsQueryMock: vi.fn(),
    useGetWorkplacePositionsQueryMock: vi.fn(),
    notificationsMock: { show: vi.fn() },
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: notificationsMock,
}));

vi.mock('jotai', () => ({
    useAtom: () => [{ workplaces: [{ id: 1, name: 'Tokyo HQ' }] }, vi.fn()],
    atom: (init: any) => ({ init }),
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, type, loading, color }: any) =>
        React.createElement('button', { onClick, type, 'disabled': loading, 'data-color': color }, children),
    Checkbox: ({ label, checked, onChange }: any) =>
        React.createElement('label', null, [
            React.createElement('input', {
                key: 'cb',
                type: 'checkbox',
                checked,
                onChange: () => onChange?.({ currentTarget: { checked: !checked } }),
            }),
            label,
        ]),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened
            ? React.createElement('div', { 'data-testid': 'modal' }, React.createElement('h2', null, title), children)
            : null,
    Select: ({ label, data, value, onChange, disabled, placeholder, error }: any) =>
        React.createElement('div', null, [
            React.createElement(
                'select',
                {
                    'key': 'sel',
                    'aria-label': label || placeholder,
                    'value': value ?? '',
                    'onChange': (e: any) => onChange?.(e.target.value || null),
                    disabled,
                },
                [
                    React.createElement('option', { key: '', value: '' }, placeholder ?? ''),
                    ...(data ?? []).map((opt: any) =>
                        React.createElement('option', { key: opt.value, value: opt.value }, opt.label),
                    ),
                ],
            ),
            error ? React.createElement('span', { key: 'err', role: 'alert' }, error) : null,
        ]),
    Skeleton: () => React.createElement('div', { 'data-testid': 'skeleton' }),
}));

vi.mock('@mantine/form', () => ({
    useForm: ({ initialValues, validate }: any) => {
        const [values, setValues] = React.useState(initialValues);
        const [errors, setErrors] = React.useState<Record<string, string>>({});

        return {
            values,
            errors,
            setValues: (v: any) => setValues((prev: any) => ({ ...prev, ...v })),
            setFieldError: (field: string, msg: string) => setErrors((prev) => ({ ...prev, [field]: msg })),
            clearFieldError: (field: string) =>
                setErrors((prev) => {
                    const next = { ...prev };
                    delete next[field];
                    return next;
                }),
            resetDirty: vi.fn(),
            reset: () => {
                setValues(initialValues);
                setErrors({});
            },
            getInputProps: (field: string, opts?: any) => {
                if (opts?.type === 'checkbox') {
                    return {
                        checked: !!values[field],
                        onChange: (e: any) => setValues((prev: any) => ({ ...prev, [field]: e.currentTarget.checked })),
                    };
                }
                return {
                    value: values[field] ?? '',
                    onChange: (v: any) => setValues((prev: any) => ({ ...prev, [field]: v })),
                    error: errors[field],
                };
            },
            onSubmit: (handler: (v: any) => void) => (e: any) => {
                e.preventDefault();
                let validationErrors: Record<string, string> = {};
                if (typeof validate === 'function') {
                    validationErrors = validate(values);
                } else if (validate) {
                    for (const field of Object.keys(validate)) {
                        const message = validate[field](values[field], values, field);
                        if (message) {
                            validationErrors[field] = message;
                        }
                    }
                }
                if (Object.keys(validationErrors).length > 0) {
                    setErrors(validationErrors);
                    return;
                }
                handler(values);
            },
        };
    },
}));

vi.mock('@/pages/admin/fixed-schedule/api', () => ({
    useCreateFixedScheduleMutation: () => useCreateFixedScheduleMutationMock(),
    useUpdateFixedScheduleMutation: () => useUpdateFixedScheduleMutationMock(),
}));

vi.mock('@/pages/admin/workplace/api', () => ({
    useGetWorkplaceShiftsQuery: (...args: unknown[]) => useGetWorkplaceShiftsQueryMock(...args),
    useGetWorkplacePositionsQuery: (...args: unknown[]) => useGetWorkplacePositionsQueryMock(...args),
}));

vi.mock('@/components/DUserSelectRemote', () => ({
    default: ({ label, disabled, value, onChange }: any) =>
        React.createElement('input', {
            'aria-label': label,
            disabled,
            'value': value ?? '',
            'onChange': (e: any) => onChange?.(e.target.value || null),
            'data-testid': 'user-select',
        }),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        getDayOfWeek: (day: number) => `day${day}`,
        formatWorkHour: (start: string, end: string) => ({ startTime: start, endTime: end }),
    },
}));

vi.mock('@/helpers/workplace.helper', () => ({
    default: {
        toSelectOptions: (_workplaces: any) => [{ value: '1', label: 'Tokyo HQ' }],
        filterWorkplacesByAccess: (workplaces: any) => workplaces,
    },
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: { init: { workplaces: [], workplace_access: {} } },
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

vi.mock('react-icons/lu', () => ({
    LuSave: () => React.createElement('span', null, 'save-icon'),
}));

const makeSchedule = (overrides: Record<string, any> = {}) =>
    ({
        id: 1,
        workplace_id: 1,
        user_id: 20,
        started_at: '2026-01-01',
        workplace: { id: 1, name: 'Tokyo HQ' },
        user: { id: 20, name: 'Alice' },
        details: [
            {
                id: 100,
                fixed_schedule_id: 1,
                day: 1,
                workplace_shift_id: 5,
                workplace_shift: { start_time: '09:00:00', end_time: '18:00:00' },
                workplace_position_id: 31,
                workplace_position: { id: 31, name: 'Picking' },
            },
        ],
        ...overrides,
    }) as any;

const defaultShifts = [
    { id: 5, workplace_id: 1, start_time: '09:00:00', end_time: '18:00:00' },
    { id: 6, workplace_id: 1, start_time: '14:00:00', end_time: '22:00:00' },
];

const defaultPositions = [
    { id: 31, workplace_id: 1, name: 'Picking' },
    { id: 32, workplace_id: 1, name: 'Packing' },
];

function renderComponent(props: Partial<React.ComponentProps<typeof FixedScheduleCreateUpdate>> = {}) {
    const defaults = {
        fixedSchedule: null,
        opened: true,
        onClose: vi.fn(),
        hasWorkplaceShiftListPermission: true,
        hasWorkplacePositionListPermission: true,
    };
    return { onClose: defaults.onClose, ...render(<FixedScheduleCreateUpdate {...defaults} {...props} />) };
}

describe('FixedScheduleCreateUpdate', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        useCreateFixedScheduleMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useUpdateFixedScheduleMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useGetWorkplaceShiftsQueryMock.mockReturnValue({ data: { data: defaultShifts }, isPending: false });
        useGetWorkplacePositionsQueryMock.mockReturnValue({ data: { data: defaultPositions }, isPending: false });
    });

    /****************************************
     * Rendering                            *
     ****************************************/

    it('renders nothing when closed', () => {
        renderComponent({ opened: false });

        expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
    });

    it('shows "Create schedule" title when no fixedSchedule is passed', () => {
        renderComponent({ fixedSchedule: null });

        expect(screen.getByText('Create schedule')).toBeInTheDocument();
    });

    it('shows "Edit schedule" title when a fixedSchedule is passed', () => {
        renderComponent({ fixedSchedule: makeSchedule() });

        expect(screen.getByText('Edit schedule')).toBeInTheDocument();
    });

    /****************************************
     * Edit mode — pre-population           *
     ****************************************/

    it('pre-populates workplace and user selects in edit mode', () => {
        renderComponent({ fixedSchedule: makeSchedule() });

        const workplaceSelect = screen.getByRole('combobox', { name: 'Workplace' });
        expect(workplaceSelect).toHaveValue('1');

        const userInput = screen.getByTestId('user-select');
        expect(userInput).toHaveValue('20');
    });

    it('disables workplace and user selects in edit mode', () => {
        renderComponent({ fixedSchedule: makeSchedule() });

        expect(screen.getByRole('combobox', { name: 'Workplace' })).toBeDisabled();
        expect(screen.getByTestId('user-select')).toBeDisabled();
    });

    it('checks the day checkbox that has an existing detail', () => {
        renderComponent({ fixedSchedule: makeSchedule() });

        const checkboxes = screen.getAllByRole('checkbox');
        // day 1 is checked, others are not
        const day1Checkbox = checkboxes.find((_, i) => screen.getAllByText(/day\d/)[i]?.textContent === 'day1');
        expect(day1Checkbox).toBeChecked();
    });

    /****************************************
     * Workplace select drives shift query  *
     ****************************************/

    it('passes empty string to shift query when no workplace selected', () => {
        renderComponent({ fixedSchedule: null });

        expect(useGetWorkplaceShiftsQueryMock).toHaveBeenCalledWith('', true);
    });

    it('passes workplace_id to shift query when a workplace is selected', () => {
        renderComponent({ fixedSchedule: makeSchedule() });

        expect(useGetWorkplaceShiftsQueryMock).toHaveBeenCalledWith('1', true);
    });

    it('passes hasWorkplaceShiftListPermission=false to shift query', () => {
        renderComponent({ fixedSchedule: null, hasWorkplaceShiftListPermission: false });

        expect(useGetWorkplaceShiftsQueryMock).toHaveBeenCalledWith('', false);
    });

    /****************************************
     * Day rows visibility                  *
     ****************************************/

    it('hides day rows when no workplace is selected', () => {
        renderComponent({ fixedSchedule: null });

        expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
    });

    it('shows 7 day checkboxes once a workplace is selected', async () => {
        renderComponent({ fixedSchedule: makeSchedule() });

        expect(screen.getAllByRole('checkbox')).toHaveLength(7);
    });

    it('shows a skeleton when shifts are loading', () => {
        useGetWorkplaceShiftsQueryMock.mockReturnValue({ data: undefined, isPending: true });
        renderComponent({ fixedSchedule: makeSchedule() });

        expect(screen.getAllByTestId('skeleton')).toHaveLength(7);
    });

    it('renders shift select options from the query result', () => {
        renderComponent({ fixedSchedule: makeSchedule() });

        const shiftSelects = screen.getAllByRole('combobox', { name: 'Select shift' });
        // Each shift select should have the two shifts as options
        const options = Array.from(shiftSelects[0].querySelectorAll('option'));
        const labels = options.map((o) => o.textContent);
        expect(labels).toContain('09:00:00 ~ 18:00:00');
        expect(labels).toContain('14:00:00 ~ 22:00:00');
    });

    it('passes workplace_id and permission to the position query', () => {
        renderComponent({ fixedSchedule: makeSchedule(), hasWorkplacePositionListPermission: false });

        expect(useGetWorkplacePositionsQueryMock).toHaveBeenCalledWith('1', false);
    });

    it('renders position select options from the query result', () => {
        renderComponent({ fixedSchedule: makeSchedule() });

        const positionSelects = screen.getAllByRole('combobox', { name: 'Select position' });
        const labels = Array.from(positionSelects[0].querySelectorAll('option')).map((o) => o.textContent);
        expect(labels).toContain('Picking');
        expect(labels).toContain('Packing');
    });

    it('pre-populates the position of an existing detail in edit mode', () => {
        renderComponent({ fixedSchedule: makeSchedule() });

        const positionSelects = screen.getAllByRole('combobox', { name: 'Select position' });
        expect(positionSelects[0]).toHaveValue('31');
    });

    it('keeps an existing position selectable only on the day that still holds it', () => {
        useGetWorkplacePositionsQueryMock.mockReturnValue({ data: { data: [defaultPositions[1]] }, isPending: false });
        renderComponent({ fixedSchedule: makeSchedule() });

        const positionSelects = screen.getAllByRole('combobox', { name: 'Select position' });
        const optionLabels = (select: HTMLElement) =>
            Array.from(select.querySelectorAll('option')).map((o) => o.textContent);

        expect(optionLabels(positionSelects[0])).toEqual(expect.arrayContaining(['Packing', 'Picking']));
        expect(positionSelects[0]).toHaveValue('31');
        for (const select of positionSelects.slice(1)) {
            expect(optionLabels(select)).toContain('Packing');
            expect(optionLabels(select)).not.toContain('Picking');
        }
    });

    it('does not offer a deactivated position on any day when it is not stored on the schedule', () => {
        useGetWorkplacePositionsQueryMock.mockReturnValue({ data: { data: [defaultPositions[1]] }, isPending: false });
        renderComponent({
            fixedSchedule: makeSchedule({
                details: [
                    {
                        id: 100,
                        fixed_schedule_id: 1,
                        day: 1,
                        workplace_shift_id: 5,
                        workplace_shift: { start_time: '09:00:00', end_time: '18:00:00' },
                        workplace_position_id: null,
                        workplace_position: null,
                    },
                ],
            }),
        });

        for (const select of screen.getAllByRole('combobox', { name: 'Select position' })) {
            const labels = Array.from(select.querySelectorAll('option')).map((o) => o.textContent);
            expect(labels).not.toContain('Picking');
        }
    });

    it('disables position selects without the position list permission', () => {
        useGetWorkplacePositionsQueryMock.mockReturnValue({ data: undefined, isPending: true });
        renderComponent({ fixedSchedule: makeSchedule(), hasWorkplacePositionListPermission: false });

        const positionSelects = screen.getAllByRole('combobox', { name: 'Select position' });
        expect(positionSelects[0]).toBeDisabled();
    });

    it('sends the selected position per day and null when none is selected', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUpdateFixedScheduleMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        renderComponent({ fixedSchedule: makeSchedule() });

        const checkboxes = screen.getAllByRole('checkbox');
        await userEvent.click(checkboxes[1]);
        const shiftSelects = screen.getAllByRole('combobox', { name: 'Select shift' });
        await userEvent.selectOptions(shiftSelects[1], '6');
        const positionSelects = screen.getAllByRole('combobox', { name: 'Select position' });
        await userEvent.selectOptions(positionSelects[0], '32');

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            const payload = mutateAsync.mock.calls[0][0].data;
            expect(payload.schedules[1]).toEqual({ day: 1, workplace_shift_id: '5', workplace_position_id: '32' });
            expect(payload.schedules[2]).toEqual({ day: 2, workplace_shift_id: '6', workplace_position_id: null });
        });
    });

    /****************************************
     * Create submit                        *
     ****************************************/

    it('calls createMutation and shows success notification on create', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useCreateFixedScheduleMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        const onClose = vi.fn();
        renderComponent({ fixedSchedule: null, onClose });

        // Select workplace to reveal day rows
        const workplaceSelect = screen.getByRole('combobox', { name: 'Workplace' });
        await userEvent.selectOptions(workplaceSelect, '1');

        // Select a user so client-side validation (required) passes
        const userInput = screen.getByTestId('user-select');
        await userEvent.type(userInput, '20');

        // Enable Monday (day 1) and pick a shift
        const checkboxes = screen.getAllByRole('checkbox');
        await userEvent.click(checkboxes[0]); // day 1 (index 0 = WEEK_DAYS[0] = 1)
        const shiftSelects = screen.getAllByRole('combobox', { name: 'Select shift' });
        await userEvent.selectOptions(shiftSelects[0], '5');

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(
                expect.objectContaining({
                    workplace_id: '1',
                    user_id: '20',
                    schedules: expect.objectContaining({ 1: expect.objectContaining({ day: 1 }) }),
                }),
            );
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
            expect(onClose).toHaveBeenCalled();
        });
    });

    /****************************************
     * Client-side validation               *
     ****************************************/

    it('blocks create submit and shows a required error when workplace/user are not selected', async () => {
        const mutateAsync = vi.fn();
        useCreateFixedScheduleMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ fixedSchedule: null });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByText(/Please select/)).toBeInTheDocument();
        });
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    it('blocks submit when an enabled day has no shift selected (requiredIf)', async () => {
        const mutateAsync = vi.fn();
        useCreateFixedScheduleMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ fixedSchedule: null });

        const workplaceSelect = screen.getByRole('combobox', { name: 'Workplace' });
        await userEvent.selectOptions(workplaceSelect, '1');

        const userInput = screen.getByTestId('user-select');
        await userEvent.type(userInput, '20');

        // Enable Monday (day 1) but leave its shift unselected
        const checkboxes = screen.getAllByRole('checkbox');
        await userEvent.click(checkboxes[0]);

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByText(/Please select/)).toBeInTheDocument();
        });
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    /****************************************
     * Update submit                        *
     ****************************************/

    it('calls updateMutation and shows success notification on update', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUpdateFixedScheduleMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        const onClose = vi.fn();
        renderComponent({ fixedSchedule: makeSchedule(), onClose });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(
                expect.objectContaining({
                    id: '1',
                    data: expect.objectContaining({ workplace_id: '1', user_id: '20' }),
                }),
            );
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
            expect(onClose).toHaveBeenCalled();
        });
    });

    it('sends only enabled days in update payload', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUpdateFixedScheduleMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ fixedSchedule: makeSchedule() });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            const payload = mutateAsync.mock.calls[0][0].data;
            expect(Object.keys(payload.schedules)).toEqual(['1']);
        });
    });

    /****************************************
     * API validation error handling        *
     ****************************************/

    it('maps API validation errors for a specific day onto the shift field', async () => {
        const { ApiValidationError } = await import('@/helpers/api.helper');
        const error = new ApiValidationError({
            status: 422,
            message: 'Validation error',
            data: { 'schedules.1': ['The shift is invalid.'] },
        });
        useUpdateFixedScheduleMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(error),
            isPending: false,
        });

        renderComponent({ fixedSchedule: makeSchedule() });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByText('validation|The shift is invalid.')).toBeInTheDocument();
        });
    });

    it("maps a per-day position API error onto that day's position field", async () => {
        const { ApiValidationError } = await import('@/helpers/api.helper');
        const mutateAsync = vi.fn().mockRejectedValue(
            new ApiValidationError({
                status: 422,
                message: 'error',
                data: { 'schedules.1.workplace_position_id': ['Invalid position.'] },
            }),
        );
        useUpdateFixedScheduleMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        renderComponent({ fixedSchedule: makeSchedule() });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            const positionSelects = screen.getAllByRole('combobox', { name: 'Select position' });
            expect(positionSelects[0].parentElement).toHaveTextContent('validation|Invalid position.');
            const shiftSelects = screen.getAllByRole('combobox', { name: 'Select shift' });
            expect(shiftSelects[0].parentElement).not.toHaveTextContent('validation|Invalid position.');
        });
    });

    it('clears user, shift and position selections when the workplace changes on create', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useCreateFixedScheduleMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        renderComponent({ fixedSchedule: null });

        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Workplace' }), '1');
        await userEvent.type(screen.getByTestId('user-select'), '20');
        await userEvent.click(screen.getAllByRole('checkbox')[0]);
        await userEvent.selectOptions(screen.getAllByRole('combobox', { name: 'Select shift' })[0], '5');
        await userEvent.selectOptions(screen.getAllByRole('combobox', { name: 'Select position' })[0], '32');

        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Workplace' }), '1');

        expect(screen.getByTestId('user-select')).toHaveValue('');
        expect(screen.getAllByRole('combobox', { name: 'Select shift' })[0]).toHaveValue('');
        expect(screen.getAllByRole('combobox', { name: 'Select position' })[0]).toHaveValue('');
    });

    it('clears the workplace required error once a workplace is picked', async () => {
        renderComponent({ fixedSchedule: null });

        const workplaceField = () => screen.getByRole('combobox', { name: 'Workplace' }).parentElement;
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => {
            expect(workplaceField()).toHaveTextContent(/Please select/);
        });

        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Workplace' }), '1');

        expect(workplaceField()).not.toHaveTextContent(/Please select/);
    });

    it('maps "schedules" (no day) API error to day_1_workplace_shift_id', async () => {
        const { ApiValidationError } = await import('@/helpers/api.helper');
        const error = new ApiValidationError({
            status: 422,
            message: 'Validation error',
            data: { schedules: ['At least one schedule is required.'] },
        });
        useUpdateFixedScheduleMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(error),
            isPending: false,
        });

        renderComponent({ fixedSchedule: makeSchedule() });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByText('validation|At least one schedule is required.')).toBeInTheDocument();
        });
    });

    /****************************************
     * Cancel button                        *
     ****************************************/

    it('calls onClose when Cancel is clicked', async () => {
        const onClose = vi.fn();
        renderComponent({ onClose });

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalled();
    });

    /****************************************
     * Save button loading state            *
     ****************************************/

    it('disables Save button while create is pending', () => {
        useCreateFixedScheduleMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: true });

        renderComponent({ fixedSchedule: null });

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });

    it('disables Save button while update is pending', () => {
        useUpdateFixedScheduleMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: true });

        renderComponent({ fixedSchedule: makeSchedule() });

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });
});
