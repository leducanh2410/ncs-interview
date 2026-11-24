import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';
import ShiftCreateModal from '@/pages/manage/shift/partials/shift_create_modal';

const {
    useCreateShiftMutationMock,
    createMutateAsyncMock,
    useGetWorkplaceShiftsQueryMock,
    notificationsShowMock,
    setFormErrorMock,
    setFieldErrorMock,
    formResetMock,
} = vi.hoisted(() => ({
    useCreateShiftMutationMock: vi.fn(),
    createMutateAsyncMock: vi.fn(),
    useGetWorkplaceShiftsQueryMock: vi.fn(),
    notificationsShowMock: vi.fn(),
    setFormErrorMock: vi.fn(),
    setFieldErrorMock: vi.fn(),
    formResetMock: vi.fn(),
}));

const FORM_VALUES = {
    workplace_shift_id: '10',
    fixed_total: 2,
    temp_total: 1,
    max_registered_temp: '',
    break_minutes: '',
};

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/form', () => ({
    useForm: () => ({
        onSubmit: (handler: any) => (e: any) => {
            e?.preventDefault?.();
            return handler(FORM_VALUES);
        },
        getInputProps: (field: string) => ({ value: (FORM_VALUES as any)[field], onChange: vi.fn() }),
        reset: formResetMock,
        setFieldError: setFieldErrorMock,
    }),
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, type, loading }: any) =>
        React.createElement('button', { type: type ?? 'button', onClick, disabled: loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    NumberInput: ({ label, value }: any) =>
        React.createElement('label', null, label, React.createElement('input', { readOnly: true, value })),
    Select: ({ label, data, onChange }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement(
                'select',
                { onChange: (e: React.ChangeEvent<HTMLSelectElement>) => onChange?.(e.target.value) },
                (data ?? []).map((opt: any) =>
                    React.createElement('option', { key: opt.value, value: opt.value }, opt.label),
                ),
            ),
        ),
    SimpleGrid: ({ children }: any) => React.createElement('div', null, children),
}));

vi.mock('@mantine/dates', () => ({
    DatePickerInput: ({ label, value }: any) =>
        React.createElement('label', null, label, React.createElement('input', { readOnly: true, value })),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: { show: notificationsShowMock },
}));

vi.mock('jotai', () => ({
    useAtom: () => [{ workplaces: [{ id: 1, name: 'Tokyo HQ' }] }],
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: { init: { workplaces: [] } },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        formatWorkHour: () => ({ startTime: '09:00', endTime: '18:00' }),
    },
}));

vi.mock('@/helpers/form.helper', () => ({
    default: { setFormError: setFormErrorMock },
}));

vi.mock('@/pages/admin/workplace/api', () => ({
    useGetWorkplaceShiftsQuery: (...args: unknown[]) => useGetWorkplaceShiftsQueryMock(...args),
}));

vi.mock('@/pages/manage/shift/api', () => ({
    useCreateShiftMutation: (...args: unknown[]) => useCreateShiftMutationMock(...args),
}));

describe('ShiftCreateModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        createMutateAsyncMock.mockResolvedValue(undefined);
        useCreateShiftMutationMock.mockReturnValue({ mutateAsync: createMutateAsyncMock, isPending: false });
        useGetWorkplaceShiftsQueryMock.mockReturnValue({ data: { data: [] }, isPending: false });
    });

    it('renders nothing when isOpened is false', () => {
        const { container } = render(<ShiftCreateModal isOpened={false} onClose={vi.fn()} date="2026-06-26" />);

        expect(container.firstChild).toBeNull();
    });

    it('renders the workplace options from master data', () => {
        render(<ShiftCreateModal isOpened onClose={vi.fn()} date="2026-06-26" />);

        expect(screen.getByRole('option', { name: 'Tokyo HQ' })).toBeInTheDocument();
    });

    it('renders shift options formatted with start/end time from the workplace shifts query', () => {
        useGetWorkplaceShiftsQueryMock.mockReturnValue({
            data: { data: [{ id: 10, start_time: '09:00:00', end_time: '18:00:00' }] },
            isPending: false,
        });

        render(<ShiftCreateModal isOpened onClose={vi.fn()} date="2026-06-26" />);

        expect(screen.getByRole('option', { name: '09:00 ~ 18:00' })).toBeInTheDocument();
    });

    it('submits the form values plus date, resets the form, and closes on success', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<ShiftCreateModal isOpened onClose={onClose} date="2026-06-26" />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(createMutateAsyncMock).toHaveBeenCalledWith({ ...FORM_VALUES, date: '2026-06-26' });
        expect(formResetMock).toHaveBeenCalled();
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('applies field errors from an ApiValidationError without closing', async () => {
        createMutateAsyncMock.mockRejectedValue(
            new ApiValidationError({ status: 422, message: 'invalid', data: { fixed_total: ['bad'] } }),
        );
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<ShiftCreateModal isOpened onClose={onClose} date="2026-06-26" />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(setFormErrorMock).toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();
    });

    it('sets a workplace_shift_id field error when the shift already exists', async () => {
        createMutateAsyncMock.mockRejectedValue(
            new ApiError({ status: 409, message: 'SHIFT_ALREADY_EXISTS', data: {} }),
        );
        const user = userEvent.setup();
        render(<ShiftCreateModal isOpened onClose={vi.fn()} date="2026-06-26" />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(setFieldErrorMock).toHaveBeenCalledWith('workplace_shift_id', 'validation|Shift is already created');
        expect(notificationsShowMock).not.toHaveBeenCalled();
    });

    it('sets a workplace_shift_id field error when the workplace shift is inactive', async () => {
        createMutateAsyncMock.mockRejectedValue(
            new ApiError({ status: 409, message: 'SHIFT_CANNOT_CREATE_INACTIVE', data: {} }),
        );
        const user = userEvent.setup();
        render(<ShiftCreateModal isOpened onClose={vi.fn()} date="2026-06-26" />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(setFieldErrorMock).toHaveBeenCalledWith('workplace_shift_id', 'validation|Workplace shift is inactive');
    });

    it('shows a generic error notification for any other failure', async () => {
        createMutateAsyncMock.mockRejectedValue(new Error('boom'));
        const user = userEvent.setup();
        render(<ShiftCreateModal isOpened onClose={vi.fn()} date="2026-06-26" />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(notificationsShowMock).toHaveBeenCalledWith({
            color: 'red',
            message: 'error|Could not create shift',
        });
    });

    it('resets the form and closes when Cancel is clicked', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<ShiftCreateModal isOpened onClose={onClose} date="2026-06-26" />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(formResetMock).toHaveBeenCalled();
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('disables Save while the mutation is pending', () => {
        useCreateShiftMutationMock.mockReturnValue({ mutateAsync: createMutateAsyncMock, isPending: true });
        render(<ShiftCreateModal isOpened onClose={vi.fn()} date="2026-06-26" />);

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });
});
