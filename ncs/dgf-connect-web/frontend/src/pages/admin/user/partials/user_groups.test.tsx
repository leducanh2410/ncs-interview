import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserGroups from './user_groups';

const { useGetUserGroupsQueryMock } = vi.hoisted(() => ({
    useGetUserGroupsQueryMock: vi.fn(),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: any) => React.createElement('a', { href }, children),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href, component, ...rest }: any) => React.createElement('a', { href }, children),
    Badge: ({ children, color }: any) => React.createElement('span', { 'data-color': color }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children, fw }: any) => React.createElement('span', { 'data-fw': fw }, children),
    Title: ({ children }: any) => React.createElement('h3', null, children),
}));

vi.mock('@/configs/route.config', () => ({
    default: { ADMIN_GROUP_DETAIL: { path: '/admin/group/[id]' } },
}));

vi.mock('@/utils/group_display_name', () => ({
    getGroupDisplayName: (group: any) => group?.name ?? '',
}));

vi.mock('@/pages/admin/group/api', () => ({
    useGetUserGroupsQuery: (...args: unknown[]) => useGetUserGroupsQueryMock(...args),
}));

const makeGroup = (id: number, name: string, kind: string) => ({ id, name, kind });

describe('UserGroups', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        useGetUserGroupsQueryMock.mockReturnValue({ data: undefined, isPending: false });
    });

    it('shows locked message when user is locked', () => {
        render(<UserGroups id="1" isLocked={true} />);

        expect(screen.getByText('Locked users are not part of any group')).toBeInTheDocument();
    });

    it('does not call query when user is locked', () => {
        render(<UserGroups id="1" isLocked={true} />);

        expect(useGetUserGroupsQueryMock).toHaveBeenCalledWith('1', false);
    });

    it('shows loader while fetching', () => {
        useGetUserGroupsQueryMock.mockReturnValue({ data: undefined, isPending: true });

        render(<UserGroups id="1" isLocked={false} />);

        expect(screen.getByTestId('loader')).toBeInTheDocument();
    });

    it('shows empty message when user has no groups', () => {
        useGetUserGroupsQueryMock.mockReturnValue({ data: { data: [] }, isPending: false });

        render(<UserGroups id="1" isLocked={false} />);

        expect(screen.getByText('This user is not a member of any group yet')).toBeInTheDocument();
    });

    it('renders group names and badges', () => {
        useGetUserGroupsQueryMock.mockReturnValue({
            data: { data: [makeGroup(10, 'Alpha Team', 'manual'), makeGroup(20, 'HQ Staff', 'workplace_current')] },
            isPending: false,
        });

        render(<UserGroups id="1" isLocked={false} />);

        expect(screen.getByText('Alpha Team')).toBeInTheDocument();
        expect(screen.getByText('HQ Staff')).toBeInTheDocument();
    });

    it('shows "System groups" section for system kinds', () => {
        useGetUserGroupsQueryMock.mockReturnValue({
            data: { data: [makeGroup(1, 'WP Group', 'workplace_current')] },
            isPending: false,
        });

        render(<UserGroups id="1" isLocked={false} />);

        expect(screen.getByText('System groups')).toBeInTheDocument();
    });

    it('shows "Manual groups" section for manual kinds', () => {
        useGetUserGroupsQueryMock.mockReturnValue({
            data: { data: [makeGroup(2, 'Manual Group', 'manual')] },
            isPending: false,
        });

        render(<UserGroups id="1" isLocked={false} />);

        expect(screen.getByText('Manual groups')).toBeInTheDocument();
    });

    it('shows "Rule-based groups" section for rule_based kinds', () => {
        useGetUserGroupsQueryMock.mockReturnValue({
            data: { data: [makeGroup(3, 'Rule Group', 'rule_based')] },
            isPending: false,
        });

        render(<UserGroups id="1" isLocked={false} />);

        expect(screen.getByText('Rule-based groups')).toBeInTheDocument();
    });

    it('links each group to its detail page', () => {
        useGetUserGroupsQueryMock.mockReturnValue({
            data: { data: [makeGroup(7, 'Team Bravo', 'manual')] },
            isPending: false,
        });

        render(<UserGroups id="1" isLocked={false} />);

        expect(screen.getByRole('link', { name: /Team Bravo/ })).toHaveAttribute('href', '/admin/group/7');
    });
});
