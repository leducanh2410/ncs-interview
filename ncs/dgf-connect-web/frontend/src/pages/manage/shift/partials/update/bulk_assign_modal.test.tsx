import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { WorkplacePosition } from '@/types/models/workplace_position';
import BulkAssignModal, { BulkAssignRequest } from '@/pages/manage/shift/partials/update/bulk_assign_modal';
import { ShiftWeeklyWorkLoad } from '@/pages/manage/shift/api';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick }: any) => React.createElement('button', { type: 'button', onClick }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    List: Object.assign(({ children }: any) => React.createElement('ul', null, children), {
        Item: ({ children }: any) => React.createElement('li', null, children),
    }),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    Text: ({ children }: any) => React.createElement('p', null, children),
}));

vi.mock('@/pages/manage/shift/partials/weekly_work_load', () => ({
    formatHours: (hours: number) => `${hours}h`,
    WeeklyWorkLoadProjection: ({ workLoad }: any) =>
        workLoad ? React.createElement('span', null, `projected ${workLoad.projected_hours}`) : null,
    isAssignmentBlocked: (workLoad: any) => Boolean(workLoad?.is_blocked && !workLoad?.is_shift_counted),
}));

const positions: WorkplacePosition[] = [{ id: 1, name: 'Leader' } as WorkplacePosition];

const buildRequest = (overrides: Partial<BulkAssignRequest> = {}): BulkAssignRequest => ({
    userIds: ['1', '2'],
    users: { '1': { name: 'Yamada' } as any, '2': { name: 'Tanaka' } as any },
    fromGroupId: 'pending',
    toGroupId: '1',
    ...overrides,
});

describe('BulkAssignModal', () => {
    it('renders nothing when request is null', () => {
        const { container } = render(
            <BulkAssignModal request={null} positions={positions} onConfirm={vi.fn()} onCancel={vi.fn()} />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('renders the move message with resolved from/to labels and the user list', () => {
        render(
            <BulkAssignModal request={buildRequest()} positions={positions} onConfirm={vi.fn()} onCancel={vi.fn()} />,
        );

        expect(screen.getByText('Move 2 user(s) from Register list to Leader:')).toBeInTheDocument();
        expect(screen.getByText('Yamada')).toBeInTheDocument();
        expect(screen.getByText('Tanaka')).toBeInTheDocument();
    });

    it('falls back to the group id when a position is not found', () => {
        render(
            <BulkAssignModal
                request={buildRequest({ toGroupId: '999' })}
                positions={positions}
                onConfirm={vi.fn()}
                onCancel={vi.fn()}
            />,
        );

        expect(screen.getByText('Move 2 user(s) from Register list to 999:')).toBeInTheDocument();
    });

    it('labels the rejected list group', () => {
        render(
            <BulkAssignModal
                request={buildRequest({ fromGroupId: 'rejected' })}
                positions={positions}
                onConfirm={vi.fn()}
                onCancel={vi.fn()}
            />,
        );

        expect(screen.getByText('Move 2 user(s) from Rejected list to Leader:')).toBeInTheDocument();
    });

    it('falls back to the user id when the user is not in the users map', () => {
        render(
            <BulkAssignModal
                request={buildRequest({ userIds: ['3'], users: {} })}
                positions={positions}
                onConfirm={vi.fn()}
                onCancel={vi.fn()}
            />,
        );

        expect(screen.getByText('3')).toBeInTheDocument();
    });

    it('calls onConfirm and onCancel when their buttons are clicked', async () => {
        const user = userEvent.setup();
        const onConfirm = vi.fn();
        const onCancel = vi.fn();
        render(
            <BulkAssignModal
                request={buildRequest()}
                positions={positions}
                onConfirm={onConfirm}
                onCancel={onCancel}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Cancel' }));
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        expect(onCancel).toHaveBeenCalledTimes(1);
        expect(onConfirm).toHaveBeenCalledTimes(1);
    });

    it('shows the shift hours and each user projected weekly hours when assigning to a position', () => {
        render(
            <BulkAssignModal
                request={buildRequest()}
                positions={positions}
                shiftHours={8}
                weeklyWorkLoads={{ '1': { projected_hours: 36 } as ShiftWeeklyWorkLoad }}
                onConfirm={vi.fn()}
                onCancel={vi.fn()}
            />,
        );

        expect(
            screen.getByText('This shift: 8h. Weekly hours are shown as current → after adding this shift / limit.'),
        ).toBeInTheDocument();
        expect(screen.getByText('projected 36')).toBeInTheDocument();
    });

    it('hides work hours when moving users back to the register or rejected list', () => {
        render(
            <BulkAssignModal
                request={buildRequest({ fromGroupId: '1', toGroupId: 'rejected' })}
                positions={positions}
                shiftHours={8}
                weeklyWorkLoads={{ '1': { projected_hours: 36 } as ShiftWeeklyWorkLoad }}
                onConfirm={vi.fn()}
                onCancel={vi.fn()}
            />,
        );

        expect(screen.queryByText(/This shift/)).not.toBeInTheDocument();
        expect(screen.queryByText('projected 36')).not.toBeInTheDocument();
    });

    it('marks users over the weekly limit as not assignable', () => {
        render(
            <BulkAssignModal
                request={buildRequest()}
                positions={positions}
                shiftHours={8}
                weeklyWorkLoads={{
                    '1': { projected_hours: 36, is_blocked: true, is_shift_counted: false } as ShiftWeeklyWorkLoad,
                    '2': { projected_hours: 16, is_blocked: false, is_shift_counted: false } as ShiftWeeklyWorkLoad,
                }}
                onConfirm={vi.fn()}
                onCancel={vi.fn()}
            />,
        );

        expect(screen.getAllByText('Cannot assign')).toHaveLength(1);
        expect(screen.getByText('Users over the weekly work-hour limit will not be assigned.')).toBeInTheDocument();
    });
});
