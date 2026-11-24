import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserLockForm from './user_lock_form';

const { disableUserMock, closeLockMock, notificationsShowMock, setFormErrorMock } = vi.hoisted(() => ({
    disableUserMock: vi.fn(),
    closeLockMock: vi.fn(),
    notificationsShowMock: vi.fn(),
    setFormErrorMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/form', () => ({
    useForm: ({ initialValues }: any) => {
        const values = { ...initialValues };
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
    isUserLockStateChangedError: (e: unknown) => e instanceof Error && e.message === 'USER_ALREADY_DISABLED',
    useDisableUserMutation: () => ({ mutateAsync: disableUserMock, isPending: false }),
}));

describe('UserLockForm', () => {
    beforeEach(() => vi.clearAllMocks());

    it('caps the reason at 255 characters', () => {
        render(<UserLockForm id="42" closeLock={closeLockMock} />);

        expect(screen.getByRole('textbox', { name: 'Lock reason' })).toHaveAttribute('maxlength', '255');
    });

    it('renders the lock reason textarea', () => {
        render(<UserLockForm id="42" closeLock={closeLockMock} />);

        expect(screen.getByRole('textbox', { name: 'Lock reason' })).toBeInTheDocument();
    });

    it('submit calls disableUser, shows success notification, then closes', async () => {
        const user = userEvent.setup();
        disableUserMock.mockResolvedValue({});

        render(<UserLockForm id="42" closeLock={closeLockMock} />);

        await user.click(screen.getByRole('button', { name: 'Lock account' }));

        await waitFor(() => {
            expect(disableUserMock).toHaveBeenCalled();
        });
        expect(notificationsShowMock).toHaveBeenCalledWith(
            expect.objectContaining({ color: 'teal', message: 'Successfully locked account' }),
        );
        expect(closeLockMock).toHaveBeenCalled();
    });

    it('shows error notification and keeps the modal open when disableUser fails', async () => {
        const user = userEvent.setup();
        disableUserMock.mockRejectedValue(new Error('Server error'));

        render(<UserLockForm id="42" closeLock={closeLockMock} />);

        await user.click(screen.getByRole('button', { name: 'Lock account' }));

        await waitFor(() => {
            expect(notificationsShowMock).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not lock account' }),
            );
        });
        expect(closeLockMock).not.toHaveBeenCalled();
    });

    it('keeps the modal open and shows the field error when the server rejects the reason', async () => {
        const user = userEvent.setup();
        const { ApiValidationError } = await import('@/helpers/api.helper');
        const validationError = new (ApiValidationError as any)({
            reason: ['The reason may not be greater than 255.'],
        });
        disableUserMock.mockRejectedValue(validationError);

        render(<UserLockForm id="42" closeLock={closeLockMock} />);

        await user.click(screen.getByRole('button', { name: 'Lock account' }));

        await waitFor(() => {
            expect(setFormErrorMock).toHaveBeenCalledWith(expect.anything(), validationError);
        });
        expect(notificationsShowMock).not.toHaveBeenCalled();
        expect(closeLockMock).not.toHaveBeenCalled();
    });

    it('tells the user the account state already changed and closes instead of retrying', async () => {
        const user = userEvent.setup();
        disableUserMock.mockRejectedValue(new Error('USER_ALREADY_DISABLED'));

        render(<UserLockForm id="42" closeLock={closeLockMock} />);

        await user.click(screen.getByRole('button', { name: 'Lock account' }));

        await waitFor(() => {
            expect(notificationsShowMock).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'yellow', message: 'This account has already been locked' }),
            );
        });
        expect(setFormErrorMock).not.toHaveBeenCalled();
        expect(closeLockMock).toHaveBeenCalled();
    });

    it('Cancel button calls closeLock()', async () => {
        const user = userEvent.setup();

        render(<UserLockForm id="42" closeLock={closeLockMock} />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(closeLockMock).toHaveBeenCalled();
        expect(disableUserMock).not.toHaveBeenCalled();
    });
});
