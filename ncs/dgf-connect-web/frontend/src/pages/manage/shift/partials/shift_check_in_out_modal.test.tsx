import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';
import ShiftCheckInOutModal from '@/pages/manage/shift/partials/shift_check_in_out_modal';

const {
    useCheckInMutationMock,
    useCheckOutMutationMock,
    checkInMutateAsyncMock,
    checkOutMutateAsyncMock,
    showSuccessMock,
    showErrorMock,
    setFormErrorMock,
    formResetMock,
} = vi.hoisted(() => ({
    useCheckInMutationMock: vi.fn(),
    useCheckOutMutationMock: vi.fn(),
    checkInMutateAsyncMock: vi.fn(),
    checkOutMutateAsyncMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
    setFormErrorMock: vi.fn(),
    formResetMock: vi.fn(),
}));

const FORM_VALUES = { date: '2026-06-26', time: '09:00' };

vi.mock('react-i18next', () => ({
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
    }),
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, type, loading }: any) =>
        React.createElement('button', { type: type ?? 'button', onClick, disabled: loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
}));

vi.mock('@mantine/dates', () => ({
    DatePickerInput: ({ label, value }: any) =>
        React.createElement('label', null, label, React.createElement('input', { readOnly: true, value })),
}));

vi.mock('@/components/DTimeInput', () => ({
    default: ({ label, value }: any) =>
        React.createElement('label', null, label, React.createElement('input', { readOnly: true, value })),
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showSuccess: showSuccessMock, showError: showErrorMock },
}));

vi.mock('@/helpers/form.helper', () => ({
    default: { setFormError: setFormErrorMock },
}));

vi.mock('@/pages/manage/shift/api', () => ({
    useCheckInMutation: (...args: unknown[]) => useCheckInMutationMock(...args),
    useCheckOutMutation: (...args: unknown[]) => useCheckOutMutationMock(...args),
}));

describe('ShiftCheckInOutModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        checkInMutateAsyncMock.mockResolvedValue(undefined);
        checkOutMutateAsyncMock.mockResolvedValue(undefined);
        useCheckInMutationMock.mockReturnValue({ mutateAsync: checkInMutateAsyncMock, isPending: false });
        useCheckOutMutationMock.mockReturnValue({ mutateAsync: checkOutMutateAsyncMock, isPending: false });
    });

    const baseProps = {
        shiftId: '1',
        date: '2026-06-26',
        setShiftUserId: vi.fn(),
        onSuccess: vi.fn(),
    };

    it('renders nothing when shiftUserId is null', () => {
        const { container } = render(<ShiftCheckInOutModal {...baseProps} shiftUserId={null} isCheckIn />);

        expect(container.firstChild).toBeNull();
    });

    it('shows the Check in title when isCheckIn is true', () => {
        render(<ShiftCheckInOutModal {...baseProps} shiftUserId="5" isCheckIn />);

        expect(screen.getByText('Check in')).toBeInTheDocument();
        expect(screen.getByText('Check in time')).toBeInTheDocument();
    });

    it('shows the Check out title when isCheckIn is false', () => {
        render(<ShiftCheckInOutModal {...baseProps} shiftUserId="5" isCheckIn={false} />);

        expect(screen.getByText('Check out')).toBeInTheDocument();
        expect(screen.getByText('Check out time')).toBeInTheDocument();
    });

    it('submits via checkIn mutation, calls onSuccess, shows a success notice, and closes on check-in', async () => {
        const user = userEvent.setup();
        const onSuccess = vi.fn();
        const setShiftUserId = vi.fn();
        render(
            <ShiftCheckInOutModal
                {...baseProps}
                shiftUserId="5"
                isCheckIn
                onSuccess={onSuccess}
                setShiftUserId={setShiftUserId}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(checkInMutateAsyncMock).toHaveBeenCalledWith({ shiftUserId: '5', date: '2026-06-26', time: '09:00' });
        expect(checkOutMutateAsyncMock).not.toHaveBeenCalled();
        expect(onSuccess).toHaveBeenCalledWith(FORM_VALUES, true);
        expect(showSuccessMock).toHaveBeenCalledWith('Check in success');
        expect(setShiftUserId).toHaveBeenCalledWith(null);
    });

    it('submits via checkOut mutation and shows the check-out success notice', async () => {
        const user = userEvent.setup();
        render(<ShiftCheckInOutModal {...baseProps} shiftUserId="5" isCheckIn={false} />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(checkOutMutateAsyncMock).toHaveBeenCalledWith({ shiftUserId: '5', date: '2026-06-26', time: '09:00' });
        expect(checkInMutateAsyncMock).not.toHaveBeenCalled();
        expect(showSuccessMock).toHaveBeenCalledWith('Check out success');
    });

    it('sets a form error without a toast and does not close when the mutation fails validation', async () => {
        checkInMutateAsyncMock.mockRejectedValue(
            new ApiValidationError({ status: 422, message: 'E_UNPROCESSABLE_ENTITY', data: { time: ['invalid'] } }),
        );
        const user = userEvent.setup();
        const setShiftUserId = vi.fn();
        render(<ShiftCheckInOutModal {...baseProps} shiftUserId="5" isCheckIn setShiftUserId={setShiftUserId} />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(setFormErrorMock).toHaveBeenCalled();
        expect(showErrorMock).not.toHaveBeenCalled();
        expect(setShiftUserId).not.toHaveBeenCalled();
    });

    /**
     * shift.check_in is per workplace but the Check in button is gated by the flat
     * permission list, so an admin can reach a row the API refuses. Only 422 reaches
     * FormHelper, so a 403 used to leave the modal silent.
     */
    it('explains an out-of-scope workplace instead of failing silently', async () => {
        checkInMutateAsyncMock.mockRejectedValue(
            new ApiError({ status: 403, message: 'CHECK_IN_WORKPLACE_OUT_OF_SCOPE', data: {} }),
        );
        const user = userEvent.setup();
        const setShiftUserId = vi.fn();
        render(<ShiftCheckInOutModal {...baseProps} shiftUserId="5" isCheckIn setShiftUserId={setShiftUserId} />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(showErrorMock).toHaveBeenCalledWith('error|You do not have permission to manage this workplace');
        expect(showSuccessMock).not.toHaveBeenCalled();
        expect(setShiftUserId).not.toHaveBeenCalled();
    });

    it('tells the manager to resolve the pending check-out edit request before checking out on behalf', async () => {
        checkOutMutateAsyncMock.mockRejectedValue(
            new ApiError({ status: 409, message: 'WORK_HISTORY_EDIT_REQUEST_PENDING_CHECK_OUT_CHANGE', data: {} }),
        );
        const user = userEvent.setup();
        const setShiftUserId = vi.fn();
        render(
            <ShiftCheckInOutModal {...baseProps} shiftUserId="5" isCheckIn={false} setShiftUserId={setShiftUserId} />,
        );

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(showErrorMock).toHaveBeenCalledWith(
            'error|This check-in has a pending edit request that changes its check-out time. Approve or reject it first',
        );
        expect(setShiftUserId).not.toHaveBeenCalled();
    });

    it('falls back to a generic check-out message for an unrecognised error', async () => {
        checkOutMutateAsyncMock.mockRejectedValue(new Error('failed'));
        const user = userEvent.setup();
        const setShiftUserId = vi.fn();
        render(
            <ShiftCheckInOutModal {...baseProps} shiftUserId="5" isCheckIn={false} setShiftUserId={setShiftUserId} />,
        );

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(showErrorMock).toHaveBeenCalledWith('error|Could not check out');
        expect(setShiftUserId).not.toHaveBeenCalled();
    });

    it('resets the form and clears shiftUserId when Cancel is clicked', async () => {
        const user = userEvent.setup();
        const setShiftUserId = vi.fn();
        render(<ShiftCheckInOutModal {...baseProps} shiftUserId="5" isCheckIn setShiftUserId={setShiftUserId} />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(formResetMock).toHaveBeenCalled();
        expect(setShiftUserId).toHaveBeenCalledWith(null);
    });

    it('disables Save while a mutation is pending', () => {
        useCheckInMutationMock.mockReturnValue({ mutateAsync: checkInMutateAsyncMock, isPending: true });
        render(<ShiftCheckInOutModal {...baseProps} shiftUserId="5" isCheckIn />);

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });
});
