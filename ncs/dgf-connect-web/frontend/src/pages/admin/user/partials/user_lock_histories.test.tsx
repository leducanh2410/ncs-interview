import React from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserLockHistories from './user_lock_histories';

const { useGetUserLockHistoriesQueryMock } = vi.hoisted(() => ({
    useGetUserLockHistoriesQueryMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Badge: ({ children, color }: any) => React.createElement('span', { 'data-color': color }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Title: ({ children }: any) => React.createElement('h3', null, children),
    SegmentedControl: ({ data, value, onChange }: any) =>
        React.createElement(
            'div',
            { role: 'radiogroup' },
            (data ?? []).map((opt: any) =>
                React.createElement(
                    'button',
                    {
                        'key': opt.value,
                        'type': 'button',
                        'role': 'radio',
                        'aria-checked': value === opt.value,
                        'onClick': () => onChange(opt.value),
                    },
                    opt.label,
                ),
            ),
        ),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (d: string) => d ?? '' },
}));

vi.mock('@/components/DDataTable', () => ({
    default: ({ columns, data }: any) =>
        React.createElement(
            'table',
            null,
            React.createElement(
                'thead',
                null,
                React.createElement(
                    'tr',
                    null,
                    (columns ?? []).map((col: any, ci: number) => React.createElement('th', { key: ci }, col.name)),
                ),
            ),
            React.createElement(
                'tbody',
                null,
                (data ?? []).map((row: any, ri: number) =>
                    React.createElement(
                        'tr',
                        { key: ri },
                        (columns ?? []).map((col: any, ci: number) =>
                            React.createElement('td', { key: ci }, col.render ? col.render(row) : row[col.key]),
                        ),
                    ),
                ),
            ),
        ),
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) =>
        React.createElement(
            'a',
            { 'href': `/admin/user/${user.id}`, 'data-avatar': user.profile_picture ?? 'initials' },
            user.name,
        ),
}));

vi.mock('@/pages/admin/user/api', () => ({
    useGetUserLockHistoriesQuery: (...args: unknown[]) => useGetUserLockHistoriesQueryMock(...args),
}));

const fakeHistories = [
    {
        id: 1,
        action_by: { id: 7, name: 'Admin Taro', staff_no: null, profile_picture: null },
        is_locked: true,
        reason: 'Misconduct',
        action_at: '2024-01-10T10:00:00Z',
    },
    {
        id: 2,
        action_by: { id: 8, name: 'Admin Hanako', staff_no: null, profile_picture: null },
        is_locked: false,
        reason: 'Appeal accepted',
        action_at: '2024-01-15T12:00:00Z',
    },
];

describe('UserLockHistories', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        useGetUserLockHistoriesQueryMock.mockReturnValue({ data: { data: fakeHistories }, isPending: false });
    });

    it('renders the lock and unlock history title', () => {
        render(<UserLockHistories id="42" />);

        expect(screen.getByText('Lock & Unlock History')).toBeInTheDocument();
    });

    it('renders action_by name for each row', () => {
        render(<UserLockHistories id="42" />);

        expect(screen.getByText('Admin Taro')).toBeInTheDocument();
        expect(screen.getByText('Admin Hanako')).toBeInTheDocument();
    });

    it('renders "Lock account" for is_locked=true rows', () => {
        render(<UserLockHistories id="42" />);

        expect(within(screen.getByRole('table')).getByText('Lock account')).toBeInTheDocument();
    });

    it('renders "Unlock account" for is_locked=false rows', () => {
        render(<UserLockHistories id="42" />);

        expect(within(screen.getByRole('table')).getByText('Unlock account')).toBeInTheDocument();
    });

    it('labels the reason column so it covers lock and unlock alike', () => {
        render(<UserLockHistories id="42" />);

        expect(screen.getByRole('columnheader', { name: 'Reason' })).toBeInTheDocument();
        expect(screen.queryByRole('columnheader', { name: 'Lock reason' })).not.toBeInTheDocument();
    });

    it('renders the reason of unlock rows, not only lock rows', () => {
        render(<UserLockHistories id="42" />);

        expect(screen.getByText('Misconduct')).toBeInTheDocument();
        expect(screen.getByText('Appeal accepted')).toBeInTheDocument();
    });

    it('colours the lock and unlock badges differently', () => {
        render(<UserLockHistories id="42" />);

        const table = within(screen.getByRole('table'));
        expect(table.getByText('Lock account')).toHaveAttribute('data-color', 'red');
        expect(table.getByText('Unlock account')).toHaveAttribute('data-color', 'teal');
    });

    it('shows both actions until a filter is picked', () => {
        render(<UserLockHistories id="42" />);

        expect(screen.getByText('Misconduct')).toBeInTheDocument();
        expect(screen.getByText('Appeal accepted')).toBeInTheDocument();
    });

    it('filtering by Lock account hides the unlock rows', async () => {
        const user = userEvent.setup();

        render(<UserLockHistories id="42" />);

        await user.click(screen.getByRole('radio', { name: 'Lock account' }));

        expect(screen.getByText('Misconduct')).toBeInTheDocument();
        expect(screen.queryByText('Appeal accepted')).not.toBeInTheDocument();
    });

    it('filtering by Unlock account hides the lock rows', async () => {
        const user = userEvent.setup();

        render(<UserLockHistories id="42" />);

        await user.click(screen.getByRole('radio', { name: 'Unlock account' }));

        expect(screen.queryByText('Misconduct')).not.toBeInTheDocument();
        expect(screen.getByText('Appeal accepted')).toBeInTheDocument();
    });

    it('going back to All restores every row', async () => {
        const user = userEvent.setup();

        render(<UserLockHistories id="42" />);

        await user.click(screen.getByRole('radio', { name: 'Unlock account' }));
        await user.click(screen.getByRole('radio', { name: 'All' }));

        expect(screen.getByText('Misconduct')).toBeInTheDocument();
        expect(screen.getByText('Appeal accepted')).toBeInTheDocument();
    });

    it('links the action_by name to that admin detail page', () => {
        render(<UserLockHistories id="42" />);

        expect(screen.getByRole('link', { name: 'Admin Taro' })).toHaveAttribute('href', '/admin/user/7');
        expect(screen.getByRole('link', { name: 'Admin Hanako' })).toHaveAttribute('href', '/admin/user/8');
    });

    it('renders a dash instead of crashing when action_by is missing', () => {
        useGetUserLockHistoriesQueryMock.mockReturnValue({
            data: {
                data: [
                    {
                        id: 9,
                        action_by: null,
                        is_locked: true,
                        reason: 'Orphaned actor',
                        action_at: '2024-02-01T09:00:00Z',
                    },
                ],
            },
            isPending: false,
        });

        render(<UserLockHistories id="42" />);

        expect(within(screen.getByRole('table')).getByText('—')).toBeInTheDocument();
        expect(screen.getByText('Orphaned actor')).toBeInTheDocument();
    });

    it('passes the user id to the query hook', () => {
        render(<UserLockHistories id="99" />);

        expect(useGetUserLockHistoriesQueryMock).toHaveBeenCalledWith('99');
    });
});
