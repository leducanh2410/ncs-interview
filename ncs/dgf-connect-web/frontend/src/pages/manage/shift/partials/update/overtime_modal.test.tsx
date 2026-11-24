import React from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { UserWithAbsenceCount } from '@/types/models/user';
import OvertimeModal from '@/pages/manage/shift/partials/update/overtime_modal';
import { OvertimeEligibilityCandidate } from '@/pages/manage/shift/api';

const {
    useOvertimeEligibilityMutationMock,
    useBulkUpdateOvertimeMutationMock,
    eligibilityMutateAsyncMock,
    bulkMutateAsyncMock,
    showSuccessMock,
    showErrorMock,
} = vi.hoisted(() => ({
    useOvertimeEligibilityMutationMock: vi.fn(),
    useBulkUpdateOvertimeMutationMock: vi.fn(),
    eligibilityMutateAsyncMock: vi.fn(),
    bulkMutateAsyncMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children }: any) => React.createElement('div', { role: 'alert' }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled: disabled || loading }, children),
    Checkbox: ({ checked, disabled, onChange }: any) =>
        React.createElement('input', { type: 'checkbox', checked, disabled, onChange }),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    NumberInput: ({ label, value, onChange }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', {
                type: 'text',
                value: value ?? '',
                onChange: (e: React.ChangeEvent<HTMLInputElement>) => {
                    const raw = e.target.value;
                    onChange(raw === '' ? '' : Number(raw));
                },
            }),
        ),
    ScrollArea: { Autosize: ({ children }: any) => React.createElement('div', null, children) },
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Table: Object.assign(({ children }: any) => React.createElement('table', null, children), {
        Thead: ({ children }: any) => React.createElement('thead', null, children),
        Tbody: ({ children }: any) => React.createElement('tbody', null, children),
        Tr: ({ children }: any) => React.createElement('tr', null, children),
        Th: ({ children }: any) => React.createElement('th', null, children),
        Td: ({ children }: any) => React.createElement('td', null, children),
    }),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showSuccess: showSuccessMock, showError: showErrorMock },
}));

vi.mock('@/pages/manage/shift/api', () => ({
    useOvertimeEligibilityMutation: (...args: unknown[]) => useOvertimeEligibilityMutationMock(...args),
    useBulkUpdateOvertimeMutation: (...args: unknown[]) => useBulkUpdateOvertimeMutationMock(...args),
}));

const buildCandidate = (overrides: Partial<OvertimeEligibilityCandidate> = {}): OvertimeEligibilityCandidate =>
    ({
        shift_user_id: 1,
        user_id: 101,
        is_eligible: true,
        is_overtime_allowed: true,
        weekly_hours: 30,
        max_hour_per_week: 40,
        remaining_hours: 10,
        ...overrides,
    }) as OvertimeEligibilityCandidate;

const users: Record<string, UserWithAbsenceCount> = {
    '101': { name: 'Yamada Taro', staff_no: '001' } as UserWithAbsenceCount,
};

describe('OvertimeModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        eligibilityMutateAsyncMock.mockResolvedValue({ data: { candidates: [] } });
        bulkMutateAsyncMock.mockResolvedValue(undefined);
        useOvertimeEligibilityMutationMock.mockReturnValue({
            mutateAsync: eligibilityMutateAsyncMock,
            isPending: false,
        });
        useBulkUpdateOvertimeMutationMock.mockReturnValue({ mutateAsync: bulkMutateAsyncMock, isPending: false });
    });

    const baseProps = { isOpen: true, onClose: vi.fn(), shiftId: '1', users };

    it('renders nothing when isOpen is false', () => {
        const { container } = render(<OvertimeModal {...baseProps} isOpen={false} />);

        expect(container.firstChild).toBeNull();
    });

    it('shows an error and does not call the mutation when overtime minutes is not entered', async () => {
        const user = userEvent.setup();
        render(<OvertimeModal {...baseProps} />);

        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));

        expect(showErrorMock).toHaveBeenCalledWith('Please enter the overtime minutes');
        expect(eligibilityMutateAsyncMock).not.toHaveBeenCalled();
    });

    it('calculates eligibility and selects all eligible employees when no cap is set', async () => {
        eligibilityMutateAsyncMock.mockResolvedValue({
            data: {
                candidates: [
                    buildCandidate({ shift_user_id: 1 }),
                    buildCandidate({ shift_user_id: 2, is_eligible: false }),
                ],
            },
        });
        const user = userEvent.setup();
        render(<OvertimeModal {...baseProps} />);

        await user.type(screen.getByLabelText('Overtime (minutes)'), '30');
        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));

        expect(eligibilityMutateAsyncMock).toHaveBeenCalledWith(30);
        expect(screen.getByText('Eligible: 1')).toBeInTheDocument();
        expect(screen.getByText('Selected: 1')).toBeInTheDocument();
    });

    it('shows an error notification when the eligibility calculation fails', async () => {
        eligibilityMutateAsyncMock.mockRejectedValue(new Error('boom'));
        const user = userEvent.setup();
        render(<OvertimeModal {...baseProps} />);

        await user.type(screen.getByLabelText('Overtime (minutes)'), '30');
        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));

        expect(showErrorMock).toHaveBeenCalledWith('error|Could not calculate overtime eligibility');
    });

    it('auto-selects all eligible when eligible count equals the requested cap', async () => {
        eligibilityMutateAsyncMock.mockResolvedValue({
            data: {
                candidates: [
                    buildCandidate({ shift_user_id: 1, is_overtime_allowed: false }),
                    buildCandidate({ shift_user_id: 2, is_overtime_allowed: false }),
                ],
            },
        });
        const user = userEvent.setup();
        render(<OvertimeModal {...baseProps} />);

        await user.type(screen.getByLabelText('Overtime (minutes)'), '30');
        await user.type(screen.getByLabelText('Number of employees needed'), '2');
        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));

        // Both are eligible and requested === eligible.length (2), so both get selected
        // even though is_overtime_allowed is false.
        expect(screen.getByText('Selected: 2 / 2')).toBeInTheDocument();
    });

    it('starts from overtime-allowed candidates trimmed to the cap when the cap differs from eligible count', async () => {
        eligibilityMutateAsyncMock.mockResolvedValue({
            data: {
                candidates: [
                    buildCandidate({ shift_user_id: 1, is_overtime_allowed: true }),
                    buildCandidate({ shift_user_id: 2, is_overtime_allowed: true }),
                    buildCandidate({ shift_user_id: 3, is_overtime_allowed: false }),
                ],
            },
        });
        const user = userEvent.setup();
        render(<OvertimeModal {...baseProps} />);

        await user.type(screen.getByLabelText('Overtime (minutes)'), '30');
        await user.type(screen.getByLabelText('Number of employees needed'), '1');
        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));

        // 3 eligible != requested (1) -> start from overtime-allowed (2 of them), trimmed to cap 1.
        expect(screen.getByText('Selected: 1 / 1')).toBeInTheDocument();
    });

    it('shows a warning alert when eligible count is below the requested cap', async () => {
        eligibilityMutateAsyncMock.mockResolvedValue({
            data: { candidates: [buildCandidate({ shift_user_id: 1 })] },
        });
        const user = userEvent.setup();
        render(<OvertimeModal {...baseProps} />);

        await user.type(screen.getByLabelText('Overtime (minutes)'), '30');
        await user.type(screen.getByLabelText('Number of employees needed'), '5');
        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));

        expect(screen.getByRole('alert')).toHaveTextContent('Only 1 eligible employees, fewer than requested');
    });

    it('shows an empty-state message when there are no candidates', async () => {
        const user = userEvent.setup();
        render(<OvertimeModal {...baseProps} />);

        await user.type(screen.getByLabelText('Overtime (minutes)'), '30');
        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));

        expect(screen.getByText('No employees in this shift')).toBeInTheDocument();
    });

    it('renders employee name, staff no, and formatted weekly/remaining hours', async () => {
        eligibilityMutateAsyncMock.mockResolvedValue({
            data: {
                candidates: [
                    buildCandidate({ shift_user_id: 1, user_id: 101, weekly_hours: 30.25, remaining_hours: 9.5 }),
                ],
            },
        });
        const user = userEvent.setup();
        render(<OvertimeModal {...baseProps} />);

        await user.type(screen.getByLabelText('Overtime (minutes)'), '30');
        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));

        expect(screen.getByText('Yamada Taro')).toBeInTheDocument();
        expect(screen.getByText('001')).toBeInTheDocument();
        expect(screen.getByText('30.3 / 40 h')).toBeInTheDocument();
        expect(screen.getByText('9.5 h')).toBeInTheDocument();
    });

    it('falls back to #userId when the user is not found in the users map', async () => {
        eligibilityMutateAsyncMock.mockResolvedValue({
            data: { candidates: [buildCandidate({ shift_user_id: 1, user_id: 999 })] },
        });
        const user = userEvent.setup();
        render(<OvertimeModal {...baseProps} />);

        await user.type(screen.getByLabelText('Overtime (minutes)'), '30');
        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));

        expect(screen.getByText('#999')).toBeInTheDocument();
    });

    it('shows the "Exceeds weekly limit" badge for ineligible candidates', async () => {
        eligibilityMutateAsyncMock.mockResolvedValue({
            data: { candidates: [buildCandidate({ shift_user_id: 1, is_eligible: false })] },
        });
        const user = userEvent.setup();
        render(<OvertimeModal {...baseProps} />);

        await user.type(screen.getByLabelText('Overtime (minutes)'), '30');
        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));

        expect(screen.getByText('Exceeds weekly limit')).toBeInTheDocument();
    });

    it('toggling a checkbox updates the selected count', async () => {
        eligibilityMutateAsyncMock.mockResolvedValue({
            data: { candidates: [buildCandidate({ shift_user_id: 1, user_id: 101 })] },
        });
        const user = userEvent.setup();
        render(<OvertimeModal {...baseProps} />);

        await user.type(screen.getByLabelText('Overtime (minutes)'), '30');
        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));
        expect(screen.getByText('Selected: 1')).toBeInTheDocument();

        const row = screen.getByText('Yamada Taro').closest('tr') as HTMLElement;
        await user.click(within(row).getByRole('checkbox'));

        expect(screen.getByText('Selected: 0')).toBeInTheDocument();
    });

    it('trims the selection, keeping the first-listed rows, when lowering the cap below the current selection', async () => {
        eligibilityMutateAsyncMock.mockResolvedValue({
            data: {
                candidates: [
                    buildCandidate({ shift_user_id: 1, user_id: 101 }),
                    buildCandidate({ shift_user_id: 2, user_id: 102 }),
                ],
            },
        });
        const user = userEvent.setup();
        render(
            <OvertimeModal {...baseProps} users={{ ...users, '102': { name: 'Suzuki' } as UserWithAbsenceCount }} />,
        );

        await user.type(screen.getByLabelText('Overtime (minutes)'), '30');
        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));
        expect(screen.getByText('Selected: 2')).toBeInTheDocument();

        await user.type(screen.getByLabelText('Number of employees needed'), '1');

        expect(screen.getByText('Selected: 1 / 1')).toBeInTheDocument();
        expect(screen.getByText('Yamada Taro').closest('tr')).toHaveTextContent('Yamada Taro');
        const yamadaCheckbox = within(screen.getByText('Yamada Taro').closest('tr') as HTMLElement).getByRole(
            'checkbox',
        );
        expect(yamadaCheckbox).toBeChecked();
    });

    it('disables Save overtime until candidates have been calculated', () => {
        render(<OvertimeModal {...baseProps} />);

        expect(screen.getByRole('button', { name: 'Save overtime' })).toBeDisabled();
    });

    it('confirms selected overtime users, shows success, calls onSaved and onClose', async () => {
        eligibilityMutateAsyncMock.mockResolvedValue({
            data: { candidates: [buildCandidate({ shift_user_id: 1, user_id: 101 })] },
        });
        const user = userEvent.setup();
        const onSaved = vi.fn();
        const onClose = vi.fn();
        render(<OvertimeModal {...baseProps} onSaved={onSaved} onClose={onClose} />);

        await user.type(screen.getByLabelText('Overtime (minutes)'), '30');
        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));
        await user.click(screen.getByRole('button', { name: 'Save overtime' }));

        expect(bulkMutateAsyncMock).toHaveBeenCalledWith([1]);
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully updated overtime status');
        expect(onSaved).toHaveBeenCalledTimes(1);
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('shows an error notification and does not close when saving overtime fails', async () => {
        eligibilityMutateAsyncMock.mockResolvedValue({
            data: { candidates: [buildCandidate({ shift_user_id: 1, user_id: 101 })] },
        });
        bulkMutateAsyncMock.mockRejectedValue(new Error('boom'));
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<OvertimeModal {...baseProps} onClose={onClose} />);

        await user.type(screen.getByLabelText('Overtime (minutes)'), '30');
        await user.click(screen.getByRole('button', { name: 'Find eligible employees' }));
        await user.click(screen.getByRole('button', { name: 'Save overtime' }));

        expect(showErrorMock).toHaveBeenCalledWith('error|Could not update overtime status');
        expect(onClose).not.toHaveBeenCalled();
    });

    it('calls onClose when Cancel is clicked', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<OvertimeModal {...baseProps} onClose={onClose} />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
