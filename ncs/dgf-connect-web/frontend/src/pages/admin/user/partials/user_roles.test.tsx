import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import UserRoles from './user_roles';

const { useGetUserRolesQueryMock, assignRoleMock, removeRoleMock, usePermissionsMock, showSuccessMock, showErrorMock } =
    vi.hoisted(() => ({
        useGetUserRolesQueryMock: vi.fn(),
        assignRoleMock: vi.fn(),
        removeRoleMock: vi.fn(),
        usePermissionsMock: vi.fn(),
        showSuccessMock: vi.fn(),
        showErrorMock: vi.fn(),
    }));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (initial = false) => {
        const [value, setValue] = React.useState(initial);
        return [value, { open: () => setValue(true), close: () => setValue(false) }];
    },
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
            getInputProps: (field: string, opts?: any) => {
                if (opts?.type === 'checkbox') return { checked: values[field] };
                return {};
            },
            reset: vi.fn(),
        };
    },
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled }, children),
    Alert: ({ children }: any) => React.createElement('div', { 'data-testid': 'role-alert' }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, type, onClick, disabled, loading }: any) =>
        React.createElement('button', { type: type ?? 'button', onClick, disabled: disabled || loading }, children),
    Checkbox: ({ label, checked, onChange }: any) =>
        React.createElement('input', { 'type': 'checkbox', 'aria-label': label, checked, onChange }),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, children) : null,
    Select: ({ label }: any) => React.createElement('select', { 'aria-label': label }),
    Title: ({ children }: any) => React.createElement('h3', null, children),
}));

vi.mock('@/helpers/api.helper', () => ({
    ApiError: class ApiError extends Error {
        constructor({ message }: { status: number; message: string; data: any }) {
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

vi.mock('@/helpers/workplace.helper', () => ({
    default: { toSelectOptions: () => [], formatLabel: (wp: any) => wp.name },
}));

vi.mock('@/validator', () => {
    const chain: any = {};
    ['required', 'isSelect', 'requiredIf', 'label'].forEach((m) => {
        chain[m] = () => chain;
    });
    return { Validator: { string: () => chain }, validatorResolver: () => () => ({}) };
});

vi.mock('@/enums/error_code.enum', () => ({
    ErrorCodeEnum: {
        USER_CANNOT_ASSIGN_ROLE_SELF: { value: 'USER_CANNOT_ASSIGN_ROLE_SELF' },
        USER_CANNOT_ASSIGN_ROLE_GLOBAL_ADMIN: { value: 'USER_CANNOT_ASSIGN_ROLE_GLOBAL_ADMIN' },
        ROLE_USER_CANNOT_HAVE_OTHER_ROLES: { value: 'ROLE_USER_CANNOT_HAVE_OTHER_ROLES' },
        ROLE_CANNOT_ADD_USER_WITH_OTHER_ROLES: { value: 'ROLE_CANNOT_ADD_USER_WITH_OTHER_ROLES' },
    },
}));

vi.mock('@/enums/role_name.enum', () => ({
    RoleNameEnum: { USER: { value: 'user' } },
}));

vi.mock('@/components/DDataTable', () => ({
    default: ({ columns, data }: any) =>
        React.createElement(
            'table',
            null,
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

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('jotai/index', () => ({
    useAtom: () => [{ workplaces: [], roles: [{ name: 'manager', display_name: 'Manager' }] }, vi.fn()],
}));

vi.mock('@/pages/global.state', () => ({ masterAtom: null }));

vi.mock('@/pages/admin/user/api', () => ({
    useGetUserRolesQuery: (...args: unknown[]) => useGetUserRolesQueryMock(...args),
    useAssignRoleMutation: () => ({ mutateAsync: assignRoleMock, isPending: false }),
    useRemoveRoleMutation: () => ({ mutateAsync: removeRoleMock }),
}));

vi.mock('react-icons/lu', () => ({
    LuPlus: () => React.createElement('span', null, '+'),
    LuSave: () => React.createElement('span', null, '💾'),
    LuTrash2: () => React.createElement('span', null, '🗑'),
}));

const fakeRoles = [
    { id: 1, name: 'manager', team_id: null },
    { id: 2, name: 'employee', team_id: 5 },
];

describe('UserRoles', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue({ USER_LIST: true, USER_ASSIGN_ROLE: true });
        useGetUserRolesQueryMock.mockReturnValue({ data: { data: fakeRoles }, isPending: false });
    });

    it('renders roles in the table', () => {
        render(<UserRoles isApproved={true} id="42" />);

        // role display_name for 'manager' is 'Manager' from mock masterData
        expect(screen.getByText('Manager')).toBeInTheDocument();
    });

    it('shows "All workplaces" for roles with null team_id', () => {
        render(<UserRoles isApproved={true} id="42" />);

        expect(screen.getByText('All workplaces')).toBeInTheDocument();
    });

    it('shows alert when user has the User role', () => {
        useGetUserRolesQueryMock.mockReturnValue({
            data: { data: [{ id: 1, name: 'user', team_id: null }] },
            isPending: false,
        });

        render(<UserRoles isApproved={true} id="42" />);

        expect(screen.getByTestId('role-alert')).toBeInTheDocument();
    });

    it('Add Role button disabled when user has the User role', () => {
        useGetUserRolesQueryMock.mockReturnValue({
            data: { data: [{ id: 1, name: 'user', team_id: null }] },
            isPending: false,
        });

        render(<UserRoles isApproved={true} id="42" />);

        expect(screen.getByRole('button', { name: 'Add Role' })).toBeDisabled();
    });

    it('clicking Add Role opens modal', async () => {
        const user = userEvent.setup();

        render(<UserRoles isApproved={true} id="42" />);

        await user.click(screen.getByRole('button', { name: 'Add Role' }));

        expect(screen.getByTestId('modal')).toBeInTheDocument();
    });

    it('submit in modal calls assignRole and shows success', async () => {
        const user = userEvent.setup();
        assignRoleMock.mockResolvedValue({});

        render(<UserRoles isApproved={true} id="42" />);

        await user.click(screen.getByRole('button', { name: 'Add Role' }));
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(assignRoleMock).toHaveBeenCalled();
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully assigned role');
    });

    it('shows "Cannot assign role to yourself" for USER_CANNOT_ASSIGN_ROLE_SELF error', async () => {
        const user = userEvent.setup();
        assignRoleMock.mockRejectedValue(
            new ApiError({ status: 400, message: 'USER_CANNOT_ASSIGN_ROLE_SELF', data: {} }),
        );

        render(<UserRoles isApproved={true} id="42" />);

        await user.click(screen.getByRole('button', { name: 'Add Role' }));
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith('error|Cannot assign role to yourself');
        });
    });

    it('clicking remove calls removeRole and shows success', async () => {
        const user = userEvent.setup();
        removeRoleMock.mockResolvedValue({});

        render(<UserRoles isApproved={true} id="42" />);

        await user.click(screen.getAllByRole('button', { name: '🗑' })[0]);

        await waitFor(() => {
            expect(removeRoleMock).toHaveBeenCalledWith({ role_name: 'manager', workplace_id: null });
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully removed role');
    });
});
