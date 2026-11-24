import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ShiftDetail } from '@/types/models/shift';
import ConfigModal from '@/pages/manage/shift/partials/update/config_modal';

const { useUpdateShiftConfigMutationMock, showSuccessMock, showErrorMock, mutateAsyncMock } = vi.hoisted(() => ({
    useUpdateShiftConfigMutationMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
    mutateAsyncMock: vi.fn(),
}));

const FORM_VALUES = {
    fixed_total: 3,
    temp_total: 2,
    max_registered_temp: 5,
    break_minutes: 30,
    is_overtime_by_duration: true,
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
        getInputProps: (field: string, options?: { type?: string }) =>
            options?.type === 'checkbox'
                ? { checked: (FORM_VALUES as any)[field], onChange: vi.fn() }
                : { value: (FORM_VALUES as any)[field], onChange: vi.fn(), error: null },
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
    Checkbox: ({ label, checked }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', { type: 'checkbox', readOnly: true, checked: checked ?? false }),
        ),
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showSuccess: showSuccessMock, showError: showErrorMock },
}));

vi.mock('@/pages/manage/shift/api', () => ({
    useUpdateShiftConfigMutation: (...args: unknown[]) => useUpdateShiftConfigMutationMock(...args),
}));

const buildShift = (overrides: Partial<ShiftDetail> = {}): ShiftDetail =>
    ({
        id: 42,
        fixed_total: 3,
        temp_total: 2,
        max_registered_temp: 5,
        break_minutes: 30,
        ...overrides,
    }) as ShiftDetail;

describe('ConfigModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mutateAsyncMock.mockResolvedValue(undefined);
        useUpdateShiftConfigMutationMock.mockReturnValue({ mutateAsync: mutateAsyncMock, isPending: false });
    });

    it('renders nothing when isOpen is false', () => {
        const { container } = render(<ConfigModal isOpen={false} onClose={vi.fn()} shift={buildShift()} />);

        expect(container.firstChild).toBeNull();
    });

    it('requests the mutation for the given shift id', () => {
        render(<ConfigModal isOpen onClose={vi.fn()} shift={buildShift({ id: 7 })} />);

        expect(useUpdateShiftConfigMutationMock).toHaveBeenCalledWith('7');
    });

    it('renders the config fields with current values', () => {
        render(<ConfigModal isOpen onClose={vi.fn()} shift={buildShift()} />);

        expect(screen.getByDisplayValue('3')).toBeInTheDocument();
        expect(screen.getByDisplayValue('2')).toBeInTheDocument();
        expect(screen.getByDisplayValue('5')).toBeInTheDocument();
        expect(screen.getByDisplayValue('30')).toBeInTheDocument();
    });

    it('renders the overtime-by-duration checkbox with the current state', () => {
        render(<ConfigModal isOpen onClose={vi.fn()} shift={buildShift()} />);

        expect(screen.getByLabelText('Count work beyond shift duration as overtime')).toBeChecked();
    });

    it('submits the overtime-by-duration flag', async () => {
        const user = userEvent.setup();
        render(<ConfigModal isOpen onClose={vi.fn()} shift={buildShift()} />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(mutateAsyncMock).toHaveBeenCalledWith(expect.objectContaining({ is_overtime_by_duration: true }));
    });

    it('calls onClose when Cancel is clicked', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<ConfigModal isOpen onClose={onClose} shift={buildShift()} />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('submits the form values, shows a success notification, and closes on success', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<ConfigModal isOpen onClose={onClose} shift={buildShift()} />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(mutateAsyncMock).toHaveBeenCalledWith(FORM_VALUES);
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully updated shift config');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('shows an error notification and does not close when the mutation fails', async () => {
        mutateAsyncMock.mockRejectedValue(new Error('failed'));
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<ConfigModal isOpen onClose={onClose} shift={buildShift()} />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(showErrorMock).toHaveBeenCalledWith('error|Could not update shift config');
        expect(onClose).not.toHaveBeenCalled();
    });

    it('disables Save while the mutation is pending', () => {
        useUpdateShiftConfigMutationMock.mockReturnValue({ mutateAsync: mutateAsyncMock, isPending: true });
        render(<ConfigModal isOpen onClose={vi.fn()} shift={buildShift()} />);

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });
});
