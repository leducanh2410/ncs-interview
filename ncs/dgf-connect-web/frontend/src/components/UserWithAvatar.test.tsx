import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import UserWithAvatar from '@/components/UserWithAvatar';

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href, 'component': Component, 'aria-label': ariaLabel }: any) =>
        Component
            ? React.createElement(
                  Component,
                  { href },
                  React.createElement('span', { 'aria-label': ariaLabel }, children),
              )
            : React.createElement('a', { href }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Tooltip: ({ children }: any) => React.createElement('div', null, children),
}));

vi.mock('@/components/DAvatar', () => ({
    default: ({ name }: { name: string }) => React.createElement('span', { 'data-testid': 'avatar' }, name.charAt(0)),
}));

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: (_name: string, params: { id: number }) => `/admin/user/${params.id}` },
}));

const user = { id: 7, name: 'Nguyen An', staff_no: '1001', profile_picture: null };

describe('UserWithAvatar', () => {
    it('links only the name by default', () => {
        render(<UserWithAvatar user={user} />);

        expect(screen.getByRole('link', { name: 'Nguyen An (1001)' })).toHaveAttribute('href', '/admin/user/7');
        expect(screen.getByTestId('avatar').closest('a')).toBeNull();
    });

    it('links the avatar too when isAvatarLink is set', () => {
        render(<UserWithAvatar user={user} isAvatarLink />);

        expect(screen.getByTestId('avatar').closest('a')).toHaveAttribute('href', '/admin/user/7');
        expect(screen.getByRole('link', { name: 'Nguyen An (1001)' })).toHaveAttribute('href', '/admin/user/7');
    });

    it('does not link the avatar when links are disabled', () => {
        render(<UserWithAvatar user={user} isLink={false} isAvatarLink />);

        expect(screen.getByTestId('avatar').closest('a')).toBeNull();
        expect(screen.queryAllByRole('link')).toHaveLength(0);
    });
});
