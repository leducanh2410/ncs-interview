import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import AdminUserCreatePage from '@/pages/admin/user/create.page';

const {
    initMutateAsyncMock,
    passwordMutateAsyncMock,
    registerMutateAsyncMock,
    showSuccessMock,
    showErrorMock,
    routerPushMock,
} = vi.hoisted(() => ({
    initMutateAsyncMock: vi.fn(),
    passwordMutateAsyncMock: vi.fn(),
    registerMutateAsyncMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
    routerPushMock: vi.fn(),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => ({ push: routerPushMock, query: {} }),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/form', () => ({
    useForm: ({ initialValues }: { initialValues: Record<string, unknown> }) => {
        const values = { ...initialValues };
        return {
            values,
            onSubmit: (fn: (values: Record<string, unknown>) => void) => (e: any) => {
                e?.preventDefault?.();
                return fn(values);
            },
            getInputProps: (_field: string) => ({}),
            reset: vi.fn(),
            setValues: (newVals: any) => Object.assign(values, newVals),
            resetDirty: vi.fn(),
            setFieldError: vi.fn(),
        };
    },
}));

vi.mock('@mantine/core', () => {
    const Stepper = ({ children }: any) => React.createElement('div', null, children);
    Stepper.Step = ({ label }: any) => React.createElement('div', null, label);

    const Grid = ({ children }: any) => React.createElement('div', null, children);
    Grid.Col = ({ children }: any) => React.createElement('div', null, children);

    return {
        Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
        Badge: ({ children }: any) => React.createElement('span', null, children),
        Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
        Button: ({ children, type, onClick, loading, disabled }: any) =>
            React.createElement('button', { type: type ?? 'button', onClick, disabled: disabled || loading }, children),
        Checkbox: ({ label }: any) => React.createElement('input', { 'type': 'checkbox', 'aria-label': label }),
        Flex: ({ children }: any) => React.createElement('div', null, children),
        Grid,
        Paper: ({ children }: any) => React.createElement('div', null, children),
        PasswordInput: ({ label }: any) => React.createElement('input', { 'type': 'password', 'aria-label': label }),
        Select: ({ label }: any) => React.createElement('select', { 'aria-label': label }),
        Stepper,
        Text: ({ children }: any) => React.createElement('span', null, children),
        TextInput: ({ label, rightSectionPointerEvents }: any) =>
            React.createElement('input', {
                'aria-label': label,
                'data-right-section-pointer-events': rightSectionPointerEvents ?? '',
            }),
        Title: ({ children }: any) => React.createElement('h2', null, children),
    };
});

vi.mock('@mantine/dates', () => ({
    DatePickerInput: ({ label }: any) => React.createElement('input', { 'aria-label': label }),
}));

vi.mock('@/helpers/api.helper', () => ({
    ApiError: class ApiError extends Error {
        constructor({ message }: { status: number; message: string; data: Record<string, any> }) {
            super(message);
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
        toParams: (values: any) => values,
        setFormError: vi.fn(),
    },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: { toSelectArray: () => [] },
}));

vi.mock('@/configs/route.config', () => ({
    default: {
        HOME: { path: '/' },
        ADMIN_USER_LIST: { path: '/admin/user' },
    },
}));

vi.mock('@/enums/error_code.enum', () => ({
    ErrorCodeEnum: {
        REGISTER_INVALID_SESSION: { value: 'REGISTER_INVALID_SESSION' },
        REGISTER_TEMP_USER_NOT_FOUND: { value: 'REGISTER_TEMP_USER_NOT_FOUND' },
        REGISTER_RESIDENCE_NUMBER_ALREADY_EXISTS: { value: 'REGISTER_RESIDENCE_NUMBER_ALREADY_EXISTS' },
        REGISTER_PASSPORT_NUMBER_ALREADY_EXISTS: { value: 'REGISTER_PASSPORT_NUMBER_ALREADY_EXISTS' },
        REGISTER_STAFF_NO_ALREADY_EXISTS: { value: 'REGISTER_STAFF_NO_ALREADY_EXISTS' },
    },
}));

vi.mock('@/enums/gender.enum', () => ({ GenderEnum: {} }));
vi.mock('@/enums/nationality.enum', () => ({ NationalityEnum: {} }));
vi.mock('@/enums/japanese_level.enum', () => ({ JapaneseLevelEnum: {} }));
vi.mock('@/enums/residence_status.enum', () => ({ ResidenceStatusEnum: {} }));

vi.mock('@/components/DVisibilityToggleIcon', () => ({ default: () => null }));

vi.mock('@/utils/password.utils', () => ({
    generateRandomPassword: () => 'Rand0m!XYZ',
}));

vi.mock('japan-postal-code', () => ({ default: { get: vi.fn() } }));

// Covered by its own test; the page only has to mount it
vi.mock('@/pages/admin/user/partials/postal_code_from_address_button', () => ({ default: () => null }));

vi.mock('react-icons/lu', () => ({
    LuCalendar: () => null,
    LuRefreshCw: () => React.createElement('span', null, 'generate'),
}));

vi.mock('@/validator', () => {
    const chain: any = {};
    ['required', 'email', 'needConfirmation'].forEach((m) => {
        chain[m] = () => chain;
    });
    return {
        Validator: { string: () => chain },
        validatorResolver: () => () => ({}),
    };
});

vi.mock('@/pages/admin/user/api', () => ({
    useInitEmployeeMutation: () => ({ mutateAsync: initMutateAsyncMock, isPending: false }),
    useSetEmployeePasswordMutation: () => ({ mutateAsync: passwordMutateAsyncMock, isPending: false }),
    useRegisterEmployeeMutation: () => ({ mutateAsync: registerMutateAsyncMock, isPending: false }),
}));

const mockSession = {
    key: 'session-key-abc',
    email: 'worker@example.com',
    user: null,
};

describe('AdminUserCreatePage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows the email form on step 0', () => {
        render(<AdminUserCreatePage />);

        expect(screen.getByText('Employee email')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeInTheDocument();
    });

    it('submits email form and advances to step 1', async () => {
        const user = userEvent.setup();
        initMutateAsyncMock.mockResolvedValue({ data: mockSession });

        render(<AdminUserCreatePage />);

        await user.click(screen.getByRole('button', { name: 'Continue' }));

        await waitFor(() => {
            expect(screen.getByText('Set employee password')).toBeInTheDocument();
        });
        expect(initMutateAsyncMock).toHaveBeenCalled();
        expect(screen.getByText('worker@example.com')).toBeInTheDocument();
    });

    it('shows error notification when email step fails', async () => {
        const user = userEvent.setup();
        initMutateAsyncMock.mockRejectedValue(new ApiError({ status: 400, message: 'SOME_ERROR', data: {} }));

        render(<AdminUserCreatePage />);

        await user.click(screen.getByRole('button', { name: 'Continue' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith('error|Could not start employee creation');
        });
        expect(screen.getByText('Employee email')).toBeInTheDocument();
    });

    it('Back button on step 1 returns to step 0', async () => {
        const user = userEvent.setup();
        initMutateAsyncMock.mockResolvedValue({ data: mockSession });

        render(<AdminUserCreatePage />);

        await user.click(screen.getByRole('button', { name: 'Continue' }));
        await waitFor(() => expect(screen.getByText('Set employee password')).toBeInTheDocument());

        await user.click(screen.getByRole('button', { name: 'Back' }));

        expect(screen.getByText('Employee email')).toBeInTheDocument();
        expect(screen.queryByText('Set employee password')).not.toBeInTheDocument();
    });

    it('submits password form and advances to step 2', async () => {
        const user = userEvent.setup();
        initMutateAsyncMock.mockResolvedValue({ data: mockSession });
        passwordMutateAsyncMock.mockResolvedValue({});

        render(<AdminUserCreatePage />);

        await user.click(screen.getByRole('button', { name: 'Continue' }));
        await waitFor(() => expect(screen.getByText('Set employee password')).toBeInTheDocument());

        await user.click(screen.getByRole('button', { name: 'Continue' }));

        await waitFor(() => {
            expect(screen.getByText('Basic information')).toBeInTheDocument();
        });
        expect(passwordMutateAsyncMock).toHaveBeenCalledWith(expect.objectContaining({ key: 'session-key-abc' }));
    });

    it('resets to step 0 when password step gets REGISTER_INVALID_SESSION', async () => {
        const user = userEvent.setup();
        initMutateAsyncMock.mockResolvedValue({ data: mockSession });
        passwordMutateAsyncMock.mockRejectedValue(
            new ApiError({ status: 400, message: 'REGISTER_INVALID_SESSION', data: {} }),
        );

        render(<AdminUserCreatePage />);

        await user.click(screen.getByRole('button', { name: 'Continue' }));
        await waitFor(() => expect(screen.getByText('Set employee password')).toBeInTheDocument());

        await user.click(screen.getByRole('button', { name: 'Continue' }));

        await waitFor(() => {
            expect(screen.getByText('Employee email')).toBeInTheDocument();
        });
        expect(showErrorMock).toHaveBeenCalledWith(
            'error|Your employee creation session has expired. Please start again',
        );
    });

    it('shows success notification and redirects after creating employee', async () => {
        const user = userEvent.setup();
        initMutateAsyncMock.mockResolvedValue({ data: mockSession });
        passwordMutateAsyncMock.mockResolvedValue({});
        registerMutateAsyncMock.mockResolvedValue({ data: { id: 99 } });

        render(<AdminUserCreatePage />);

        await user.click(screen.getByRole('button', { name: 'Continue' }));
        await waitFor(() => expect(screen.getByText('Set employee password')).toBeInTheDocument());

        await user.click(screen.getByRole('button', { name: 'Continue' }));
        await waitFor(() => expect(screen.getByText('Basic information')).toBeInTheDocument());
        // The reverse postal code lookup sits in the right section, which Mantine makes unclickable by default
        expect(screen.getByLabelText('Postal code')).toHaveAttribute('data-right-section-pointer-events', 'auto');

        await user.click(screen.getByRole('button', { name: 'Create employee' }));

        await waitFor(() => {
            expect(registerMutateAsyncMock).toHaveBeenCalledWith(expect.objectContaining({ key: 'session-key-abc' }));
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully created employee');
        expect(routerPushMock).toHaveBeenCalledWith('/admin/user');
    });

    it('resets to step 0 when profile step gets REGISTER_INVALID_SESSION', async () => {
        const user = userEvent.setup();
        initMutateAsyncMock.mockResolvedValue({ data: mockSession });
        passwordMutateAsyncMock.mockResolvedValue({});
        registerMutateAsyncMock.mockRejectedValue(
            new ApiError({ status: 400, message: 'REGISTER_INVALID_SESSION', data: {} }),
        );

        render(<AdminUserCreatePage />);

        await user.click(screen.getByRole('button', { name: 'Continue' }));
        await waitFor(() => expect(screen.getByText('Set employee password')).toBeInTheDocument());

        await user.click(screen.getByRole('button', { name: 'Continue' }));
        await waitFor(() => expect(screen.getByText('Basic information')).toBeInTheDocument());

        await user.click(screen.getByRole('button', { name: 'Create employee' }));

        await waitFor(() => {
            expect(screen.getByText('Employee email')).toBeInTheDocument();
        });
    });
});
