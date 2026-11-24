import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WorkplaceShiftCreateUpdate from './workplace_shift_create_update';

const { useCreateWorkplaceShiftMutationMock, useUpdateWorkplaceShiftMutationMock, notificationsMock } = vi.hoisted(
    () => ({
        useCreateWorkplaceShiftMutationMock: vi.fn(),
        useUpdateWorkplaceShiftMutationMock: vi.fn(),
        notificationsMock: { show: vi.fn() },
    }),
);

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

vi.mock('@mantine/notifications', () => ({
    notifications: notificationsMock,
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick }: any) =>
        React.createElement('button', { onClick, 'data-testid': 'remove-leader-btn' }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick, type, loading }: any) =>
        React.createElement('button', { onClick, type: type || 'button', disabled: loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened
            ? React.createElement('div', { 'data-testid': 'modal' }, React.createElement('h2', null, title), children)
            : null,
    NumberInput: ({ label, value, onChange }: any) =>
        React.createElement('input', {
            'type': 'number',
            'aria-label': label,
            'value': value ?? '',
            'onChange': (e: any) => onChange?.(e.target.value === '' ? '' : Number(e.target.value)),
        }),
    Select: ({ label, data, value, onChange }: any) =>
        React.createElement(
            'select',
            {
                'aria-label': label,
                'value': value ?? '',
                'onChange': (e: any) => onChange?.(e.target.value || null),
            },
            (data ?? []).map((opt: any) =>
                React.createElement('option', { key: opt.value, value: opt.value }, opt.label),
            ),
        ),
    Title: ({ children }: any) => React.createElement('h3', null, children),
}));

vi.mock('@/components/DTimeInput', () => ({
    default: ({ label, value, onChange, error }: any) =>
        React.createElement('div', null, [
            React.createElement('input', {
                'key': 'input',
                'aria-label': label,
                'value': value ?? '',
                'onChange': (e: any) => onChange?.(e.target.value),
            }),
            error ? React.createElement('span', { key: 'err', role: 'alert' }, error) : null,
        ]),
}));

vi.mock('@/components/DUserSelectRemote', () => ({
    default: ({ label, value, onChange, error }: any) =>
        React.createElement('div', null, [
            React.createElement('input', {
                'key': 'input',
                'aria-label': label,
                'value': value ?? '',
                'onChange': (e: any) => onChange?.(e.target.value || null),
            }),
            error ? React.createElement('span', { key: 'err', role: 'alert' }, error) : null,
        ]),
}));

vi.mock('@/pages/admin/workplace/api', () => ({
    useCreateWorkplaceShiftMutation: () => useCreateWorkplaceShiftMutationMock(),
    useUpdateWorkplaceShiftMutation: () => useUpdateWorkplaceShiftMutationMock(),
}));

vi.mock('react-icons/lu', () => ({
    LuSave: () => React.createElement('span', null, 'save-icon'),
    LuTrash2: () => React.createElement('span', null, 'trash-icon'),
}));

const makeShift = (overrides: Record<string, any> = {}) =>
    ({
        id: 3,
        workplace_id: 1,
        start_time: '09:00:00',
        end_time: '18:00:00',
        start_break_time: null,
        end_break_time: null,
        salary_per_hour: null,
        check_in_round_type: 1,
        check_in_round_minutes: 15,
        check_out_round_type: -1,
        check_out_round_minutes: 15,
        date_adjustment: 0,
        leaders: [],
        ...overrides,
    }) as any;

function renderComponent(props: Partial<React.ComponentProps<typeof WorkplaceShiftCreateUpdate>> = {}) {
    const defaults = {
        workplaceId: '1',
        shift: null,
        opened: true,
        onClose: vi.fn(),
    };
    return { onClose: defaults.onClose, ...render(<WorkplaceShiftCreateUpdate {...defaults} {...props} />) };
}

describe('WorkplaceShiftCreateUpdate', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        useCreateWorkplaceShiftMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useUpdateWorkplaceShiftMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
    });

    it('renders nothing when closed', () => {
        renderComponent({ opened: false });

        expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
    });

    it('shows "Create shift" title when no shift is passed', () => {
        renderComponent({ shift: null });

        expect(screen.getByText('Create shift')).toBeInTheDocument();
    });

    it('shows "Edit shift" title and pre-fills time fields when a shift is passed', () => {
        renderComponent({ shift: makeShift() });

        expect(screen.getByText('Edit shift')).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Start time' })).toHaveValue('09:00:00');
        expect(screen.getByRole('textbox', { name: 'End time' })).toHaveValue('18:00:00');
    });

    it('pre-fills existing leaders when editing a shift', () => {
        renderComponent({
            shift: makeShift({ leaders: [{ user_id: 20, bonus_per_hour: 500 }] }),
        });

        expect(screen.getByRole('textbox', { name: 'User' })).toHaveValue('20');
        expect(screen.getByRole('spinbutton', { name: 'Bonus' })).toHaveValue(500);
    });

    it('blocks submit and does not call the mutation when start/end time are empty', async () => {
        const mutateAsync = vi.fn();
        useCreateWorkplaceShiftMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ shift: null });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getAllByRole('alert').length).toBeGreaterThan(0);
        });
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    it('calls createMutation with the entered times on create', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useCreateWorkplaceShiftMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        const onClose = vi.fn();

        renderComponent({ shift: null, onClose });

        await userEvent.type(screen.getByRole('textbox', { name: 'Start time' }), '09:00');
        await userEvent.type(screen.getByRole('textbox', { name: 'End time' }), '18:00');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(
                expect.objectContaining({ start_time: '09:00', end_time: '18:00', leaders: [] }),
            );
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
            expect(onClose).toHaveBeenCalled();
        });
    });

    it('calls updateMutation with the shiftId when editing', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUpdateWorkplaceShiftMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ shift: makeShift({ id: 11 }) });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(expect.objectContaining({ shiftId: '11' }));
        });
    });

    it('adds a leader row when "Add leader" is clicked', async () => {
        renderComponent({ shift: null });

        expect(screen.queryByRole('textbox', { name: 'User' })).not.toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Add leader' }));

        expect(screen.getByRole('textbox', { name: 'User' })).toBeInTheDocument();
        expect(screen.getByRole('spinbutton', { name: 'Bonus' })).toBeInTheDocument();
    });

    it('removes a leader row when its delete icon is clicked', async () => {
        renderComponent({ shift: null });

        await userEvent.click(screen.getByRole('button', { name: 'Add leader' }));
        expect(screen.getByRole('textbox', { name: 'User' })).toBeInTheDocument();

        await userEvent.click(screen.getByTestId('remove-leader-btn'));

        expect(screen.queryByRole('textbox', { name: 'User' })).not.toBeInTheDocument();
    });

    it('blocks submit when a leader row is missing a user', async () => {
        const mutateAsync = vi.fn();
        useCreateWorkplaceShiftMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ shift: null });

        await userEvent.type(screen.getByRole('textbox', { name: 'Start time' }), '09:00');
        await userEvent.type(screen.getByRole('textbox', { name: 'End time' }), '18:00');
        await userEvent.click(screen.getByRole('button', { name: 'Add leader' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getAllByRole('alert').length).toBeGreaterThan(0);
        });
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    it('submits leader rows with the user_id split from a composite value', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useCreateWorkplaceShiftMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ shift: null });

        await userEvent.type(screen.getByRole('textbox', { name: 'Start time' }), '09:00');
        await userEvent.type(screen.getByRole('textbox', { name: 'End time' }), '18:00');
        await userEvent.click(screen.getByRole('button', { name: 'Add leader' }));
        await userEvent.type(screen.getByRole('textbox', { name: 'User' }), '20|Alice');
        await userEvent.type(screen.getByRole('spinbutton', { name: 'Bonus' }), '5');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(
                expect.objectContaining({
                    leaders: [expect.objectContaining({ user_id: '20', bonus: 5 })],
                }),
            );
        });
    });

    it('calls onClose when Cancel is clicked without submitting the form', async () => {
        const mutateAsync = vi.fn();
        useCreateWorkplaceShiftMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        const onClose = vi.fn();
        renderComponent({ onClose });

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalled();
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    it('disables the Save button while a mutation is pending', () => {
        useCreateWorkplaceShiftMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: true });

        renderComponent({ shift: null });

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });
});
