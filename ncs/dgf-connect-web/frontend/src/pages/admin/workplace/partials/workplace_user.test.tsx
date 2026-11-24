import React from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WorkplaceUserList from './workplace_user';

const {
    useGetWorkplaceUsersQueryMock,
    useAddUsersToWorkplaceMutationMock,
    useRemoveUserFromWorkplaceMutationMock,
    usePermissionsMock,
    useDisclosureMock,
    notificationsMock,
    dataTableSpy,
} = vi.hoisted(() => ({
    useGetWorkplaceUsersQueryMock: vi.fn(),
    useAddUsersToWorkplaceMutationMock: vi.fn(),
    useRemoveUserFromWorkplaceMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    useDisclosureMock: vi.fn(),
    notificationsMock: { show: vi.fn() },
    dataTableSpy: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

vi.mock('@mantine/notifications', () => ({
    notifications: notificationsMock,
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (...args: unknown[]) => useDisclosureMock(...args),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { onClick, disabled, 'data-testid': 'action-icon' }, children),
    Button: ({ children, onClick, disabled, loading, type }: any) =>
        React.createElement('button', { onClick, type: type || 'button', disabled: disabled || loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'add-modal' },
                  React.createElement('h2', null, title),
                  children,
              )
            : null,
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Title: ({ children }: any) => React.createElement('h3', null, children),
}));

vi.mock('@mantine/form', () => ({
    useForm: ({ initialValues, validate }: any) => {
        const [values, setValues] = React.useState(initialValues);
        const [errors, setErrors] = React.useState<Record<string, string>>({});

        return {
            values,
            errors,
            setFieldError: (field: string, msg: string) => setErrors((prev) => ({ ...prev, [field]: msg })),
            reset: () => {
                setValues(initialValues);
                setErrors({});
            },
            getInputProps: (field: string) => ({
                value: values[field] ?? '',
                onChange: (v: any) => setValues((prev: any) => ({ ...prev, [field]: v })),
                error: errors[field],
            }),
            onSubmit: (handler: (v: any) => void) => (e: any) => {
                e.preventDefault();
                let validationErrors: Record<string, string> = {};
                if (typeof validate === 'function') {
                    validationErrors = validate(values);
                } else if (validate) {
                    for (const field of Object.keys(validate)) {
                        const message = validate[field](values[field], values, field);
                        if (message) {
                            validationErrors[field] = message;
                        }
                    }
                }
                if (Object.keys(validationErrors).length > 0) {
                    setErrors(validationErrors);
                    return;
                }
                handler(values);
            },
        };
    },
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/workplace/api', () => ({
    useGetWorkplaceUsersQuery: (...args: unknown[]) => useGetWorkplaceUsersQueryMock(...args),
    useAddUsersToWorkplaceMutation: () => useAddUsersToWorkplaceMutationMock(),
    useRemoveUserFromWorkplaceMutation: () => useRemoveUserFromWorkplaceMutationMock(),
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => React.createElement('span', null, user.name),
}));

vi.mock('@/components/DUserSelectRemote', () => ({
    DUserSelectRemoteMultiple: ({ value, onChange, placeholder, error }: any) =>
        React.createElement('div', null, [
            React.createElement('input', {
                'key': 'input',
                'aria-label': placeholder,
                'value': (value ?? []).join(','),
                'onChange': (e: any) => onChange?.(e.target.value ? e.target.value.split(',') : []),
            }),
            error ? React.createElement('span', { key: 'err', role: 'alert' }, error) : null,
        ]),
}));

vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, onConfirm, onCancel }: any) => {
        if (!opened) return null;
        return React.createElement(
            'div',
            { 'data-testid': 'confirm-modal' },
            React.createElement('button', { 'onClick': onConfirm, 'data-testid': 'confirm-btn' }, 'Confirm'),
            React.createElement('button', { 'onClick': onCancel, 'data-testid': 'cancel-btn' }, 'Cancel'),
        );
    },
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        dataTableSpy(props);
        const records = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);
        return React.createElement(
            'table',
            null,
            React.createElement(
                'tbody',
                null,
                records.map((record: any) =>
                    React.createElement(
                        'tr',
                        { key: record.id },
                        props.columns.map((col: any) =>
                            React.createElement(
                                'td',
                                { key: col.key },
                                col.render ? col.render(record) : record[col.key],
                            ),
                        ),
                    ),
                ),
            ),
        );
    },
}));

vi.mock('react-icons/lu', () => ({
    LuPlus: () => React.createElement('span', null, 'plus'),
    LuSave: () => React.createElement('span', null, 'save'),
    LuTrash2: () => React.createElement('span', null, 'trash'),
}));

const makeUser = (overrides: Record<string, any> = {}) =>
    ({
        id: 1,
        staff_no: null,
        name: 'Alice',
        email: 'alice@example.com',
        phone: '090-0000-0000',
        ...overrides,
    }) as any;

describe('WorkplaceUserList', () => {
    let openAdd: ReturnType<typeof vi.fn>;
    let closeAdd: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({
            WORKPLACE_USER_LIST: true,
            WORKPLACE_USER_ADD: true,
            WORKPLACE_USER_REMOVE: true,
        });
        useAddUsersToWorkplaceMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useRemoveUserFromWorkplaceMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useGetWorkplaceUsersQueryMock.mockReturnValue({ data: { data: [makeUser()] }, isLoading: false });

        openAdd = vi.fn();
        closeAdd = vi.fn();
        useDisclosureMock.mockReturnValue([false, { open: openAdd, close: closeAdd }]);
    });

    it('passes the workplaceId, page and WORKPLACE_USER_LIST permission to the query', () => {
        render(<WorkplaceUserList workplaceId="1" />);

        expect(useGetWorkplaceUsersQueryMock).toHaveBeenCalledWith('1', 1, true);
    });

    it('renders a dash for staff_no when it is missing', () => {
        render(<WorkplaceUserList workplaceId="1" />);

        expect(screen.getByText('-')).toBeInTheDocument();
        expect(screen.getByText('Alice')).toBeInTheDocument();
    });

    it('disables the Add button when WORKPLACE_USER_ADD is false', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_USER_LIST: true,
            WORKPLACE_USER_ADD: false,
            WORKPLACE_USER_REMOVE: true,
        });

        render(<WorkplaceUserList workplaceId="1" />);

        expect(screen.getByRole('button', { name: 'Add' })).toBeDisabled();
    });

    it('opens the add-user modal when Add is clicked', () => {
        render(<WorkplaceUserList workplaceId="1" />);

        fireEvent.click(screen.getByRole('button', { name: 'Add' }));

        expect(openAdd).toHaveBeenCalled();
    });

    it('disables the remove icon when WORKPLACE_USER_REMOVE is false', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_USER_LIST: true,
            WORKPLACE_USER_ADD: true,
            WORKPLACE_USER_REMOVE: false,
        });

        render(<WorkplaceUserList workplaceId="1" />);

        expect(screen.getByTestId('action-icon')).toBeDisabled();
    });

    it('blocks add-user submit and does not call the mutation when no users are selected', async () => {
        const mutateAsync = vi.fn();
        useAddUsersToWorkplaceMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useDisclosureMock.mockReturnValue([true, { open: openAdd, close: closeAdd }]);

        render(<WorkplaceUserList workplaceId="1" />);

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByText('validation|Please select at least {{min}} items')).toBeInTheDocument();
        });
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    it('adds selected users and shows a success notification', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useAddUsersToWorkplaceMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useDisclosureMock.mockReturnValue([true, { open: openAdd, close: closeAdd }]);

        render(<WorkplaceUserList workplaceId="1" />);

        fireEvent.change(screen.getByRole('textbox', { name: 'Select user' }), { target: { value: '5' } });
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(['5']);
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'teal', message: 'Successfully added user to workplace' }),
            );
            expect(closeAdd).toHaveBeenCalled();
        });
    });

    it('shows a membership mismatch error without closing the modal', async () => {
        const { ApiError } = await import('@/helpers/api.helper');
        const error = new ApiError({ status: 400, message: 'WORKPLACE_TEST_MEMBERSHIP_MISMATCH', data: {} });
        useAddUsersToWorkplaceMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(error),
            isPending: false,
        });
        useDisclosureMock.mockReturnValue([true, { open: openAdd, close: closeAdd }]);

        render(<WorkplaceUserList workplaceId="1" />);

        fireEvent.change(screen.getByRole('textbox', { name: 'Select user' }), { target: { value: '5' } });
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({
                    color: 'red',
                    message:
                        'error|Only test employees can join test workplaces, and normal employees only normal ones',
                }),
            );
        });
        expect(closeAdd).not.toHaveBeenCalled();
    });

    it('sets a field error from an API validation error response', async () => {
        const { ApiValidationError } = await import('@/helpers/api.helper');
        const error = new ApiValidationError({
            status: 422,
            message: 'Validation error',
            data: { user_ids: ['Selected user is invalid.'] },
        });
        useAddUsersToWorkplaceMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(error),
            isPending: false,
        });
        useDisclosureMock.mockReturnValue([true, { open: openAdd, close: closeAdd }]);

        render(<WorkplaceUserList workplaceId="1" />);

        fireEvent.change(screen.getByRole('textbox', { name: 'Select user' }), { target: { value: '5' } });
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByText('validation|Selected user is invalid.')).toBeInTheDocument();
        });
    });

    it('shows a generic error notification for other add failures', async () => {
        useAddUsersToWorkplaceMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(new Error('boom')),
            isPending: false,
        });
        useDisclosureMock.mockReturnValue([true, { open: openAdd, close: closeAdd }]);

        render(<WorkplaceUserList workplaceId="1" />);

        fireEvent.change(screen.getByRole('textbox', { name: 'Select user' }), { target: { value: '5' } });
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not add user to workplace' }),
            );
        });
    });

    it('removes the user and shows a success notification on confirm', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useRemoveUserFromWorkplaceMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetWorkplaceUsersQueryMock.mockReturnValue({ data: { data: [makeUser({ id: 7 })] }, isLoading: false });

        render(<WorkplaceUserList workplaceId="1" />);

        fireEvent.click(screen.getByTestId('action-icon'));
        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith('7');
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'teal', message: 'Successfully removed user from workplace' }),
            );
        });
    });

    it('shows a specific error message when the user has future shifts', async () => {
        const { ApiError } = await import('@/helpers/api.helper');
        const error = new ApiError({ status: 400, message: 'WORKPLACE_CANNOT_REMOVE_USER_WITH_SHIFT', data: {} });
        useRemoveUserFromWorkplaceMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(error),
            isPending: false,
        });

        render(<WorkplaceUserList workplaceId="1" />);

        fireEvent.click(screen.getByTestId('action-icon'));
        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({
                    color: 'red',
                    message: 'error|Could not remove user from workplace: User has shifts',
                }),
            );
        });
    });

    it('closes the remove confirmation modal on cancel', () => {
        render(<WorkplaceUserList workplaceId="1" />);

        fireEvent.click(screen.getByTestId('action-icon'));
        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('cancel-btn'));
        expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();
    });

    it('updates the page passed to the query when onPageChanged is triggered', () => {
        render(<WorkplaceUserList workplaceId="1" />);

        const onPageChanged = dataTableSpy.mock.calls[dataTableSpy.mock.calls.length - 1][0].onPageChanged;
        act(() => onPageChanged(2));

        expect(useGetWorkplaceUsersQueryMock).toHaveBeenLastCalledWith('1', 2, true);
    });
});
