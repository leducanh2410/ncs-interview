import React from 'react';
import { MantineProvider } from '@mantine/core';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { UserUpcomingShift } from '@/types/models/user_upcoming_shift';
import PaperCompletionRequestModal from './paper_completion_request_modal';

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
];

const renderModal = (props: Partial<React.ComponentProps<typeof PaperCompletionRequestModal>> = {}) =>
    render(
        <MantineProvider>
            <PaperCompletionRequestModal
                opened={true}
                shifts={shifts}
                onConfirm={vi.fn()}
                onCancel={vi.fn()}
                {...props}
            />
        </MantineProvider>,
    );

describe('PaperCompletionRequestModal', () => {
    it('names the consequence before anything is decided', () => {
        renderModal();

        expect(
            screen.getByText(
                'The employee will not be able to check in or register for shifts until they file the missing documents.',
            ),
        ).toBeInTheDocument();
    });

    it('shows the upcoming shifts so the admin can judge the timing', () => {
        renderModal();

        expect(screen.getByText('Shifts in the next {{days}} days ({{count}}):1')).toBeInTheDocument();
        expect(screen.getByText('2026-06-27')).toBeInTheDocument();
        expect(screen.getByText('09:00 - 18:00').closest('a')).toHaveAttribute('href', '/manage/shift/101');
    });

    it('says so plainly when there is no shift to weigh', () => {
        renderModal({ shifts: [] });

        expect(screen.getByText('No shifts in the next {{days}} days.')).toBeInTheDocument();
        expect(screen.queryByText('2026-06-27')).not.toBeInTheDocument();
    });

    it('does not claim an empty roster while the shifts are still loading', () => {
        renderModal({ shifts: [], shiftsLoading: true });

        expect(screen.queryByText('No shifts in the next {{days}} days.')).not.toBeInTheDocument();
        expect(screen.queryByText('The upcoming shifts could not be loaded.')).not.toBeInTheDocument();
    });

    it('says the roster is unknown when the shift query failed', () => {
        renderModal({ shifts: [], shiftsFailed: true });

        expect(screen.getByText('The upcoming shifts could not be loaded.')).toBeInTheDocument();
        expect(screen.queryByText('No shifts in the next {{days}} days.')).not.toBeInTheDocument();
    });

    it('leaves the decision with the admin when the shift query failed', async () => {
        const onConfirm = vi.fn();
        const user = userEvent.setup();
        renderModal({ shifts: [], shiftsFailed: true, onConfirm });

        await user.click(screen.getByRole('button', { name: 'Request completion' }));
        expect(onConfirm).toHaveBeenCalledTimes(1);
    });

    it('calls onConfirm and onCancel from the footer buttons', async () => {
        const onConfirm = vi.fn();
        const onCancel = vi.fn();
        const user = userEvent.setup();
        renderModal({ onConfirm, onCancel });

        await user.click(screen.getByRole('button', { name: 'Request completion' }));
        expect(onConfirm).toHaveBeenCalledTimes(1);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(onCancel).toHaveBeenCalledTimes(1);
    });

    it('blocks a second submit while the request is in flight', async () => {
        const onConfirm = vi.fn();
        const user = userEvent.setup();
        renderModal({ onConfirm, loading: true });

        await user.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    });

    it('renders nothing when closed', () => {
        renderModal({ opened: false });

        expect(screen.queryByText('2026-06-27')).not.toBeInTheDocument();
    });
});
