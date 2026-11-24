import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';
import ResetPasswordForm from './reset_password_form';

const { resetPasswordMock, closeMock, showSuccessMock, showErrorMock, setFormErrorMock } = vi.hoisted(() => ({
    resetPasswordMock: vi.fn(),
    closeMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
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
            setValues: (v: any) => Object.assign(values, v),
        };
    },
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, type, onClick, loading, variant }: any) =>
        React.createElement('button', { type: type ?? 'button', onClick, disabled: loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    PasswordInput: ({ label }: any) => React.createElement('input', { 'type': 'password', 'aria-label': label }),
    Text: ({ children, size, c }: any) => React.createElement('span', null, children),
}));

vi.mock('@/helpers/api.helper', () => ({
    ApiError: class ApiError extends Error {
        data: unknown;
        constructor({ message, data }: { status: number; message: string; data: unknown }) {
            super(message);
            this.data = data;
        }
    },
    ApiValidationError: class ApiValidationError extends Error {
        data: Record<string, string[]>;
        constructor({ data }: { status: number; message: string; data: Record<string, string[]> }) {
            super('Validation Error');
            this.data = data;
        }
    },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/helpers/form.helper', () => ({
    default: {
        toParams: (v: any) => v,
        setFormError: (...args: unknown[]) => setFormErrorMock(...args),
    },
}));

vi.mock('@/validator', () => {
    const chain: any = {};
    ['required', 'needConfirmation'].forEach((m) => {
        chain[m] = () => chain;
    });
    return { Validator: { string: () => chain }, validatorResolver: () => () => ({}) };
});

vi.mock('@/components/DVisibilityToggleIcon', () => ({ default: () => null }));

vi.mock('@/utils/password.utils', () => ({
    generateRandomPassword: () => 'Rand0m!XYZ',
}));

vi.mock('react-icons/lu', () => ({
    LuRefreshCw: () => React.createElement('span', null, '↺'),
}));

vi.mock('@/pages/admin/user/api', () => ({
    useResetPasswordMutation: () => ({ mutateAsync: resetPasswordMock, isPending: false }),
}));

describe('ResetPasswordForm', () => {
    beforeEach(() => vi.clearAllMocks());

    it('renders Password and Confirm password inputs', () => {
        render(<ResetPasswordForm id="1" close={closeMock} />);

        expect(screen.getByRole('button', { name: 'Reset password' })).toBeInTheDocument();
        const passwordInputs = document.querySelectorAll('[type="password"]');
        expect(passwordInputs).toHaveLength(2);
        expect(passwordInputs[0]).toHaveAttribute('aria-label', 'Password');
        expect(passwordInputs[1]).toHaveAttribute('aria-label', 'Confirm password');
    });

    it('renders the Generate button', () => {
        render(<ResetPasswordForm id="1" close={closeMock} />);

        expect(screen.getByRole('button', { name: 'Generate' })).toBeInTheDocument();
    });

    it('submits and calls resetPassword, shows success, closes', async () => {
        const user = userEvent.setup();
        resetPasswordMock.mockResolvedValue({});

        render(<ResetPasswordForm id="42" close={closeMock} />);

        await user.click(screen.getByRole('button', { name: 'Reset password' }));

        await waitFor(() => {
            expect(resetPasswordMock).toHaveBeenCalled();
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully reset password');
        expect(closeMock).toHaveBeenCalled();
    });

    it('calls setFormError and shows error on ApiError with string data', async () => {
        const user = userEvent.setup();
        resetPasswordMock.mockRejectedValue(
            new ApiError({ status: 400, message: 'Bad request', data: 'The current password is invalid' } as any),
        );

        render(<ResetPasswordForm id="42" close={closeMock} />);

        await user.click(screen.getByRole('button', { name: 'Reset password' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith(
                'error|Could not reset password: The current password is invalid',
            );
        });
    });

    it('calls setFormError but NOT showError for ApiValidationError', async () => {
        const user = userEvent.setup();
        resetPasswordMock.mockRejectedValue(
            new ApiValidationError({ status: 422, message: 'Validation failed', data: { password: ['Too short'] } }),
        );

        render(<ResetPasswordForm id="42" close={closeMock} />);

        await user.click(screen.getByRole('button', { name: 'Reset password' }));

        await waitFor(() => {
            expect(setFormErrorMock).toHaveBeenCalled();
        });
        expect(showErrorMock).not.toHaveBeenCalled();
    });

    it('Cancel button calls close()', async () => {
        const user = userEvent.setup();

        render(<ResetPasswordForm id="42" close={closeMock} />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(closeMock).toHaveBeenCalled();
    });
});
