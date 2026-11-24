import React from 'react';
import { MantineProvider } from '@mantine/core';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { UserUpcomingShift } from '@/types/models/user_upcoming_shift';
import UserLockWarningModal from './user_lock_warning_modal';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, options?: Record<string, unknown>) =>
            options?.count === undefined ? key : `${key}:${options.count as number}`,
    }),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (value: string) => value },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: any) => React.createElement('a', { href }, children),
}));

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: (_name: string, params: Record<string, string | number>) => `/manage/shift/${params.id}` },
}));

const shifts: UserUpcomingShift[] = [
    {
        id: 1,
        shift_id: 101,
        date: '2026-06-27',
        status: 'approved',
        is_fixed: false,
        workplace_name: 'Test Warehouse',
        start_time: '09:00',
        end_time: '18:00',
    },
    {
        id: 2,
        shift_id: 102,
        date: '2026-06-28',
        status: 'pending',
        is_fixed: true,
        workplace_name: 'Test Warehouse',
        start_time: '22:00',
        end_time: '06:00',
    },
];

const renderModal = (props: Partial<React.ComponentProps<typeof UserLockWarningModal>> = {}) =>
    render(
        <MantineProvider>
            <UserLockWarningModal opened={true} shifts={shifts} onConfirm={vi.fn()} onCancel={vi.fn()} {...props} />
        </MantineProvider>,
    );

describe('UserLockWarningModal', () => {
    it('lists every upcoming shift with its status', () => {
        renderModal();

        expect(screen.getByText('2026-06-27')).toBeInTheDocument();
        expect(screen.getByText('09:00 - 18:00')).toBeInTheDocument();
        expect(screen.getByText('Approved')).toBeInTheDocument();
        expect(screen.getByText('2026-06-28')).toBeInTheDocument();
        expect(screen.getByText('Pending')).toBeInTheDocument();
    });

    it('links each shift time to its shift detail page', () => {
        renderModal();

        expect(screen.getByText('09:00 - 18:00').closest('a')).toHaveAttribute('href', '/manage/shift/101');
        expect(screen.getByText('22:00 - 06:00').closest('a')).toHaveAttribute('href', '/manage/shift/102');
    });

    it('renders nothing when closed', () => {
        renderModal({ opened: false });

        expect(screen.queryByText('2026-06-27')).not.toBeInTheDocument();
    });

    it('calls onConfirm and onCancel from the footer buttons', async () => {
        const onConfirm = vi.fn();
        const onCancel = vi.fn();
        const user = userEvent.setup();
        renderModal({ onConfirm, onCancel });

        await user.click(screen.getByRole('button', { name: 'Continue to lock' }));
        expect(onConfirm).toHaveBeenCalledTimes(1);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(onCancel).toHaveBeenCalledTimes(1);
    });
});
