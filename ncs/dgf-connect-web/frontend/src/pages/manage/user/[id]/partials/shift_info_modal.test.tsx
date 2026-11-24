import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CalendarCard } from '@/types/shift';
import { ApiError } from '@/helpers/api.helper';
import ShiftInfoModal from '@/pages/manage/user/[id]/partials/shift_info_modal';

const { toggleAbsentMutateMock, useToggleAbsentMutationMock, notificationsShowMock } = vi.hoisted(() => ({
    toggleAbsentMutateMock: vi.fn(),
    useToggleAbsentMutationMock: vi.fn(),
    notificationsShowMock: vi.fn(),
}));

vi.mock('react-i18next', async (importOriginal) => ({
    ...(await importOriginal<typeof import('react-i18next')>()),
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('next/link', () => ({
    default: ({ children }: any) => React.createElement('a', null, children),
}));

vi.mock('react-icons/lu', () => ({
    LuTriangleAlert: () => React.createElement('span', null, 'warn-icon'),
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children }: any) => React.createElement('div', { 'data-testid': 'alert' }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Button: ({ children, onClick, color }: any) =>
        React.createElement('button', { 'type': 'button', onClick, 'data-color': color ?? '' }, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Switch: ({ label, checked, onChange }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', { type: 'checkbox', role: 'switch', checked, onChange }),
        ),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: { show: notificationsShowMock },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: () => '/shift/1' },
}));

vi.mock('@/pages/manage/user/[id]/api', () => ({
    useToggleAbsentMutation: (...args: unknown[]) => useToggleAbsentMutationMock(...args),
}));

const buildCard = (overrides: Partial<CalendarCard> = {}): CalendarCard => ({
    id: 11,
    shiftId: 3,
    workplaceName: 'Test Workplace',
    startTime: '09:00',
    endTime: '18:00',
    position: 'Staff',
    workHours: null,
    hasCheckIn: false,
    status: 'approved',
    isAbsent: false,
    ...overrides,
});

describe('ShiftInfoModal absent confirmation', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        useToggleAbsentMutationMock.mockReturnValue({ mutate: toggleAbsentMutateMock, isPending: false });
    });

    const openAbsentConfirmation = async (card: CalendarCard) => {
        const user = userEvent.setup();
        render(<ShiftInfoModal card={card} onClose={vi.fn()} />);
        await user.click(screen.getByRole('switch'));
    };

    it('asks for a plain confirmation when the shift has no attendance yet', async () => {
        await openAbsentConfirmation(buildCard());

        expect(screen.getByText('Are you sure you want to mark this shift as absent?')).toBeInTheDocument();
        expect(screen.queryByTestId('alert')).not.toBeInTheDocument();
    });

    it.each([
        ['checked out', '8.00'],
        ['still checked in', null],
    ])('warns the admin when the shift already has a check-in (%s)', async (_label, workHours) => {
        await openAbsentConfirmation(buildCard({ hasCheckIn: true, workHours }));

        expect(screen.getByText('Warning: this shift already has attendance')).toBeInTheDocument();
        expect(screen.getByTestId('alert')).toHaveTextContent(
            'This employee already has a recorded check-in for this shift.',
        );
        expect(
            screen.getByText('Please double check that this is not a mistake before confirming.'),
        ).toBeInTheDocument();
    });

    it.each([
        [
            'with attendance on an open month',
            true,
            false,
            'Shift marked as absent The work time report and salary will update in a few seconds.',
        ],
        [
            'with attendance on a settled month',
            true,
            true,
            'Shift marked as absent The work time report will update in a few seconds. The settled salary for this month stays unchanged.',
        ],
        ['without attendance', false, false, 'Shift marked as absent'],
    ])('reports what the recalculation will touch %s', async (_label, hasCheckIn, isSalarySettled, expectedMessage) => {
        toggleAbsentMutateMock.mockImplementation((_vars: unknown, opts: any) =>
            opts.onSuccess({ data: { is_salary_settled: isSalarySettled } }),
        );
        const user = userEvent.setup();
        await openAbsentConfirmation(buildCard({ hasCheckIn }));

        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        expect(notificationsShowMock).toHaveBeenCalledWith(expect.objectContaining({ message: expectedMessage }));
    });

    it('marks the work hours as not counted once the shift is absent', () => {
        render(
            <ShiftInfoModal
                card={buildCard({ hasCheckIn: true, workHours: '8.00', isAbsent: true })}
                onClose={vi.fn()}
            />,
        );

        expect(screen.getByText('Not counted towards the work time report')).toBeInTheDocument();
    });

    it('shows plain work hours while the shift still counts', () => {
        render(<ShiftInfoModal card={buildCard({ hasCheckIn: true, workHours: '8.00' })} onClose={vi.fn()} />);

        expect(screen.queryByText('Not counted towards the work time report')).not.toBeInTheDocument();
    });

    it('still allows confirming the absence after the warning', async () => {
        const user = userEvent.setup();
        await openAbsentConfirmation(buildCard({ hasCheckIn: true, workHours: '8.00' }));

        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        expect(toggleAbsentMutateMock).toHaveBeenCalled();
    });

    it.each([
        [
            'SHIFT_USER_ABSENT_SALARY_PAID',
            'Salary for this month has already been paid in part, so this shift cannot be marked as absent. Ask accounting to settle the month first.',
        ],
        [
            'SHIFT_USER_ABSENT_SALARY_REQUESTED',
            'This employee has a payment request in progress for this month, so this shift cannot be marked as absent. Settle or cancel that request first.',
        ],
        ['SHIFT_USER_INVALID_STATUS', 'Failed to update shift status'],
    ])('reports the %s error from the API', async (errorCode, expectedMessage) => {
        toggleAbsentMutateMock.mockImplementation((_vars: unknown, opts: any) =>
            opts.onError(new ApiError({ status: 400, message: errorCode, data: {} })),
        );
        const user = userEvent.setup();
        await openAbsentConfirmation(buildCard({ hasCheckIn: true, workHours: '8.00' }));

        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        expect(notificationsShowMock).toHaveBeenCalledWith(expect.objectContaining({ message: expectedMessage }));
    });

    it('explains that marking the shift as present would exceed the weekly hour limit', async () => {
        toggleAbsentMutateMock.mockImplementation((_vars: unknown, opts: any) =>
            opts.onError(
                new ApiError({ status: 400, message: 'SCHEDULE_MAX_WORK_HOURS_EXCEEDED', data: { over_hours: 8 } }),
            ),
        );
        const user = userEvent.setup();
        await openAbsentConfirmation(buildCard({ isAbsent: true }));

        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        expect(notificationsShowMock).toHaveBeenCalledWith(
            expect.objectContaining({
                message:
                    'This shift cannot be counted again because it would put the employee over their weekly work hour limit by 8h.',
                autoClose: false,
            }),
        );
    });

    it('does not warn when un-marking an absent shift that has a check-in', async () => {
        await openAbsentConfirmation(buildCard({ hasCheckIn: true, isAbsent: true }));

        expect(screen.getByText('Are you sure you want to mark this shift as present?')).toBeInTheDocument();
        expect(screen.queryByTestId('alert')).not.toBeInTheDocument();
    });
});
