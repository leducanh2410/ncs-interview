import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CreateManagerModal from './create_manager_modal';

const { createMutateAsyncMock, onCloseMock, showSuccessMock, setFormErrorMock } = vi.hoisted(() => ({
    createMutateAsyncMock: vi.fn(),
    onCloseMock: vi.fn(),
    showSuccessMock: vi.fn(),
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
    Button: ({ children, type, onClick, loading, color }: any) =>
        React.createElement('button', { type: type ?? 'button', onClick, disabled: loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal', 'data-title': title }, children) : null,
    PasswordInput: ({ label }: any) => React.createElement('input', { 'type': 'password', 'aria-label': label }),
    Select: ({ label }: any) => React.createElement('select', { 'aria-label': label }),
    SimpleGrid: ({ children }: any) => React.createElement('div', null, children),
    TextInput: ({ label }: any) => React.createElement('input', { 'type': 'text', 'aria-label': label }),
}));

vi.mock('@/validator', () => {
    const chain: any = {};
    ['required', 'isSelect'].forEach((m) => {
        chain[m] = () => chain;
    });
    return { Validator: { string: () => chain }, validatorResolver: () => () => ({}) };
});

vi.mock('@/helpers/notification.helper', () => ({
    default: { showSuccess: (...args: unknown[]) => showSuccessMock(...args) },
}));

vi.mock('@/helpers/form.helper', () => ({
    default: {
        toParams: (v: any) => v,
        setFormError: (...args: unknown[]) => setFormErrorMock(...args),
    },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: { toSelectArray: () => [] },
}));

vi.mock('@/enums/role_name.enum', () => ({
    RoleNameEnum: {},
    getManagerRoles: () => [],
}));

vi.mock('@/components/DVisibilityToggleIcon', () => ({ default: () => null }));

vi.mock('react-icons/lu', () => ({
    LuSave: () => React.createElement('span', null, '💾'),
}));

vi.mock('@/pages/admin/user/api', () => ({
    useCreateManagerMutation: () => ({ mutateAsync: createMutateAsyncMock, isPending: false }),
}));

describe('CreateManagerModal', () => {
    beforeEach(() => vi.clearAllMocks());

    it('renders nothing when closed', () => {
        render(<CreateManagerModal isOpened={false} onClose={onCloseMock} />);

        expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
    });

    it('renders modal title when opened', () => {
        render(<CreateManagerModal isOpened={true} onClose={onCloseMock} />);

        expect(screen.getByTestId('modal')).toHaveAttribute('data-title', 'Create manager');
    });

    it('renders the email input inside the modal', () => {
        render(<CreateManagerModal isOpened={true} onClose={onCloseMock} />);

        expect(screen.getByRole('textbox', { name: 'Email' })).toBeInTheDocument();
    });

    it('calls createMutation, shows success, and closes on submit', async () => {
        const user = userEvent.setup();
        createMutateAsyncMock.mockResolvedValue({ data: { id: 5 } });

        render(<CreateManagerModal isOpened={true} onClose={onCloseMock} />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(createMutateAsyncMock).toHaveBeenCalled();
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully created manager');
        expect(onCloseMock).toHaveBeenCalled();
    });

    it('calls setFormError when mutation fails', async () => {
        const user = userEvent.setup();
        createMutateAsyncMock.mockRejectedValue(new Error('Server error'));

        render(<CreateManagerModal isOpened={true} onClose={onCloseMock} />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(setFormErrorMock).toHaveBeenCalled();
        });
        expect(onCloseMock).not.toHaveBeenCalled();
    });

    it('Cancel button calls onClose()', async () => {
        const user = userEvent.setup();

        render(<CreateManagerModal isOpened={true} onClose={onCloseMock} />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onCloseMock).toHaveBeenCalled();
        expect(createMutateAsyncMock).not.toHaveBeenCalled();
    });
});
