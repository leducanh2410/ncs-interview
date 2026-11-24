import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserUnlockForm from './user_unlock_form';

const { enableUserMock, closeUnlockMock, notificationsShowMock, setFormErrorMock } = vi.hoisted(() => ({
    enableUserMock: vi.fn(),
    closeUnlockMock: vi.fn(),
    notificationsShowMock: vi.fn(),
    setFormErrorMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/form', () => ({
    useForm: ({ initialValues }: any) => {
        const values = { ...initialValues, reason: 'Appeal accepted' };
        return {
            values,
            onSubmit: (fn: any) => (e: any) => {
                e?.preventDefault?.();
                return fn(values);
            },
            getInputProps: (_: string) => ({}),
            reset: vi.fn(),
        };
    },
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, type, onClick, loading }: any) =>
        React.createElement('button', { type: type ?? 'button', onClick, disabled: loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Textarea: ({ label, ...rest }: any) => React.createElement('textarea', { 'aria-label': label, ...rest }),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: { show: (...args: unknown[]) => notificationsShowMock(...args) },
}));

vi.mock('@/validator', () => {
    const chain: any = {};
    ['required', 'maxLength'].forEach((m) => {
        chain[m] = () => chain;
    });
    return { Validator: { string: () => chain }, validatorResolver: () => () => ({}) };
});

vi.mock('@/helpers/api.helper', () => {
    class ApiError extends Error {}
    class ApiValidationError extends ApiError {
        data: Record<string, string[]>;
        constructor(data: Record<string, string[]>) {
            super('validation');
            this.data = data;
        }
    }
    return { ApiError, ApiValidationError };
});

vi.mock('@/helpers/form.helper', () => ({
    default: { toParams: (v: any) => v, setFormError: (...args: unknown[]) => setFormErrorMock(...args) },
}));

vi.mock('@/pages/admin/user/api', () => ({
    isUserLockStateChangedError: (e: unknown) => e instanceof Error && e.message === 'USER_ALREADY_ENABLED',
    useEnableUserMutation: () => ({ mutateAsync: enableUserMock, isPending: false }),
}));

describe('UserUnlockForm', () => {
    beforeEach(() => vi.clearAllMocks());

    it('caps the reason at 255 characters', () => {
        render(<UserUnlockForm id="42" closeUnlock={closeUnlockMock} />);

        expect(screen.getByRole('textbox', { name: 'Unlock reason' })).toHaveAttribute('maxlength', '255');
    });

    it('renders the unlock reason textarea', () => {
        render(<UserUnlockForm id="42" closeUnlock={closeUnlockMock} />);

        expect(screen.getByRole('textbox', { name: 'Unlock reason' })).toBeInTheDocument();
    });

    it('submit sends the reason to enableUser, shows success notification, then closes', async () => {
        const user = userEvent.setup();
        enableUserMock.mockResolvedValue({});

        render(<UserUnlockForm id="42" closeUnlock={closeUnlockMock} />);

        await user.click(screen.getByRole('button', { name: 'Unlock account' }));

        await waitFor(() => {
            expect(enableUserMock).toHaveBeenCalledWith(expect.objectContaining({ reason: 'Appeal accepted' }));
        });
        expect(notificationsShowMock).toHaveBeenCalledWith(
            expect.objectContaining({ color: 'teal', message: 'Successfully unlocked account' }),
        );
        expect(closeUnlockMock).toHaveBeenCalled();
    });

    it('shows error notification and keeps the modal open when enableUser fails', async () => {
        const user = userEvent.setup();
        enableUserMock.mockRejectedValue(new Error('Server error'));

        render(<UserUnlockForm id="42" closeUnlock={closeUnlockMock} />);

        await user.click(screen.getByRole('button', { name: 'Unlock account' }));

        await waitFor(() => {
            expect(notificationsShowMock).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not unlock account' }),
            );
        });
        expect(closeUnlockMock).not.toHaveBeenCalled();
    });

    it('keeps the modal open and shows the field error when the server rejects the reason', async () => {
        const user = userEvent.setup();
        const { ApiValidationError } = await import('@/helpers/api.helper');
        const validationError = new (ApiValidationError as any)({
            reason: ['The reason may not be greater than 255.'],
        });
        enableUserMock.mockRejectedValue(validationError);

        render(<UserUnlockForm id="42" closeUnlock={closeUnlockMock} />);

        await user.click(screen.getByRole('button', { name: 'Unlock account' }));

        await waitFor(() => {
            expect(setFormErrorMock).toHaveBeenCalledWith(expect.anything(), validationError);
        });
        expect(notificationsShowMock).not.toHaveBeenCalled();
        expect(closeUnlockMock).not.toHaveBeenCalled();
    });

    it('tells the user the account state already changed and closes instead of retrying', async () => {
        const user = userEvent.setup();
        enableUserMock.mockRejectedValue(new Error('USER_ALREADY_ENABLED'));

        render(<UserUnlockForm id="42" closeUnlock={closeUnlockMock} />);

        await user.click(screen.getByRole('button', { name: 'Unlock account' }));

        await waitFor(() => {
            expect(notificationsShowMock).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'yellow', message: 'This account has already been unlocked' }),
            );
        });
        expect(setFormErrorMock).not.toHaveBeenCalled();
        expect(closeUnlockMock).toHaveBeenCalled();
    });

    it('Cancel button calls closeUnlock()', async () => {
        const user = userEvent.setup();

        render(<UserUnlockForm id="42" closeUnlock={closeUnlockMock} />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(closeUnlockMock).toHaveBeenCalled();
        expect(enableUserMock).not.toHaveBeenCalled();
    });
});
