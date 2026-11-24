import React from 'react';
import { MantineProvider } from '@mantine/core';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserLockForm from './user_lock_form';
import UserUnlockForm from './user_unlock_form';

const { disableUserMock, enableUserMock, notificationsShowMock } = vi.hoisted(() => ({
    disableUserMock: vi.fn(),
    enableUserMock: vi.fn(),
    notificationsShowMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: { show: (...args: unknown[]) => notificationsShowMock(...args) },
}));

vi.mock('@/pages/admin/user/api', () => ({
    isUserLockStateChangedError: () => false,
    useDisableUserMutation: () => ({ mutateAsync: disableUserMock, isPending: false }),
    useEnableUserMutation: () => ({ mutateAsync: enableUserMock, isPending: false }),
}));

const closeMock = vi.fn();

const renderLock = () =>
    render(
        <MantineProvider>
            <UserLockForm id="42" closeLock={closeMock} />
        </MantineProvider>,
    );

const renderUnlock = () =>
    render(
        <MantineProvider>
            <UserUnlockForm id="42" closeUnlock={closeMock} />
        </MantineProvider>,
    );

describe('lock and unlock forms, wired to the real Mantine form and validator', () => {
    beforeEach(() => vi.clearAllMocks());

    it('submits the typed lock reason instead of complaining it is empty', async () => {
        const user = userEvent.setup();
        disableUserMock.mockResolvedValue({});

        renderLock();

        await user.type(screen.getByRole('textbox', { name: /Lock reason/ }), 'Nghi viec');
        await user.click(screen.getByRole('button', { name: 'Lock account' }));

        await waitFor(() => {
            expect(disableUserMock).toHaveBeenCalledWith({ reason: 'Nghi viec' });
        });
        expect(screen.queryByText('Please enter reason')).not.toBeInTheDocument();
        expect(closeMock).toHaveBeenCalled();
    });

    it('submits the typed unlock reason instead of complaining it is empty', async () => {
        const user = userEvent.setup();
        enableUserMock.mockResolvedValue({});

        renderUnlock();

        await user.type(screen.getByRole('textbox', { name: /Unlock reason/ }), 'Quay lai lam viec');
        await user.click(screen.getByRole('button', { name: 'Unlock account' }));

        await waitFor(() => {
            expect(enableUserMock).toHaveBeenCalledWith({ reason: 'Quay lai lam viec' });
        });
        expect(closeMock).toHaveBeenCalled();
    });

    it('still blocks an empty lock reason', async () => {
        const user = userEvent.setup();

        renderLock();

        await user.click(screen.getByRole('button', { name: 'Lock account' }));

        await waitFor(() => {
            expect(screen.getByText('Please enter reason')).toBeInTheDocument();
        });
        expect(disableUserMock).not.toHaveBeenCalled();
    });

    it('still blocks a whitespace-only unlock reason', async () => {
        const user = userEvent.setup();

        renderUnlock();

        await user.type(screen.getByRole('textbox', { name: /Unlock reason/ }), '   ');
        await user.click(screen.getByRole('button', { name: 'Unlock account' }));

        await waitFor(() => {
            expect(screen.getByText('Please enter reason')).toBeInTheDocument();
        });
        expect(enableUserMock).not.toHaveBeenCalled();
    });

    it('caps the lock reason at 255 characters so the server rule cannot be tripped', async () => {
        const user = userEvent.setup();
        disableUserMock.mockResolvedValue({});

        renderLock();

        const textarea = screen.getByRole('textbox', { name: /Lock reason/ });
        await user.type(textarea, 'a'.repeat(300));

        expect((textarea as HTMLTextAreaElement).value).toHaveLength(255);

        await user.click(screen.getByRole('button', { name: 'Lock account' }));

        await waitFor(() => {
            expect(disableUserMock).toHaveBeenCalledWith({ reason: 'a'.repeat(255) });
        });
    });
});
