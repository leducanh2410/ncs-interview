import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import UserWorkplaces from './user_workplaces';

const {
    useGetUserWorkplacesQueryMock,
    deleteWorkplaceMock,
    addToWorkplacesMock,
    usePermissionsMock,
    showSuccessMock,
    showErrorMock,
} = vi.hoisted(() => ({
    useGetUserWorkplacesQueryMock: vi.fn(),
    deleteWorkplaceMock: vi.fn(),
    addToWorkplacesMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: any) => React.createElement('a', { href }, children),
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
            getInputProps: (_: string) => ({}),
            reset: vi.fn(),
        };
    },
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled }, children),
    Anchor: ({ children, href, component }: any) => React.createElement('a', { href }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, type, onClick, disabled, loading }: any) =>
        React.createElement('button', { type: type ?? 'button', onClick, disabled: disabled || loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title, onClose }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, children) : null,
    MultiSelect: ({ placeholder }: any) =>
        React.createElement('select', { 'aria-label': placeholder, 'multiple': true }),
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

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: () => '/admin/workplace/1' },
}));

vi.mock('@/helpers/workplace.helper', () => ({
    default: { toSelectOptions: () => [], formatLabel: (wp: any) => wp.name },
}));

vi.mock('@/validator', () => {
    const chain: any = {};
    ['required', 'minLength', 'label'].forEach((m) => {
        chain[m] = () => chain;
    });
    return { Validator: { array: () => chain }, validatorResolver: () => () => ({}) };
});

vi.mock('@/enums/error_code.enum', () => ({
    ErrorCodeEnum: {
        WORKPLACE_CANNOT_REMOVE_USER_WITH_SHIFT: { value: 'WORKPLACE_CANNOT_REMOVE_USER_WITH_SHIFT' },
        WORKPLACE_TEST_MEMBERSHIP_MISMATCH: { value: 'WORKPLACE_TEST_MEMBERSHIP_MISMATCH' },
    },
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
    useAtom: () => [{ workplaces: [], roles: [] }, vi.fn()],
}));

vi.mock('@/pages/global.state', () => ({ masterAtom: null }));

vi.mock('@/pages/admin/user/api', () => ({
    useGetUserWorkplacesQuery: (...args: unknown[]) => useGetUserWorkplacesQueryMock(...args),
    useDeleteWorkplaceMutation: () => ({ mutateAsync: deleteWorkplaceMock }),
    useAddWorkplacesMutation: () => ({ mutateAsync: addToWorkplacesMock, isPending: false }),
}));

vi.mock('react-icons/lu', () => ({
    LuPlus: () => React.createElement('span', null, '+'),
    LuSave: () => React.createElement('span', null, '💾'),
    LuTrash2: () => React.createElement('span', null, '🗑'),
}));

const fakeWorkplaces = [
    { id: 1, name: 'HQ Tokyo', address: '1-1 Tokyo' },
    { id: 2, name: 'Osaka Office', address: '2-2 Osaka' },
];

describe('UserWorkplaces', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue({
            USER_WORKPLACE_LIST: true,
            USER_WORKPLACE_ADD: true,
            USER_WORKPLACE_REMOVE: true,
        });
        useGetUserWorkplacesQueryMock.mockReturnValue({ data: { data: fakeWorkplaces }, isPending: false });
    });

    it('renders Workplaces title', () => {
        render(<UserWorkplaces id="42" />);

        expect(screen.getByText('Workplaces')).toBeInTheDocument();
    });

    it('renders workplace names in the table', () => {
        render(<UserWorkplaces id="42" />);

        expect(screen.getByText('HQ Tokyo')).toBeInTheDocument();
        expect(screen.getByText('Osaka Office')).toBeInTheDocument();
    });

    it('Add button is disabled without USER_WORKPLACE_ADD permission', () => {
        usePermissionsMock.mockReturnValue({
            USER_WORKPLACE_LIST: true,
            USER_WORKPLACE_ADD: false,
            USER_WORKPLACE_REMOVE: true,
        });

        render(<UserWorkplaces id="42" />);

        expect(screen.getByRole('button', { name: 'Add' })).toBeDisabled();
    });

    it('clicking Add button opens modal', async () => {
        const user = userEvent.setup();

        render(<UserWorkplaces id="42" />);

        await user.click(screen.getByRole('button', { name: 'Add' }));

        expect(screen.getByTestId('modal')).toBeInTheDocument();
    });

    it('submit in modal calls addToWorkplaces and shows success', async () => {
        const user = userEvent.setup();
        addToWorkplacesMock.mockResolvedValue({});

        render(<UserWorkplaces id="42" />);

        await user.click(screen.getByRole('button', { name: 'Add' }));
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(addToWorkplacesMock).toHaveBeenCalled();
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully added user to workplace');
    });

    it('clicking delete calls deleteWorkplace and shows success', async () => {
        const user = userEvent.setup();
        deleteWorkplaceMock.mockResolvedValue({});

        render(<UserWorkplaces id="42" />);

        await user.click(screen.getAllByRole('button', { name: '🗑' })[0]);

        await waitFor(() => {
            expect(deleteWorkplaceMock).toHaveBeenCalledWith('1');
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully removed user from workplace');
    });

    it('shows specific error for WORKPLACE_CANNOT_REMOVE_USER_WITH_SHIFT', async () => {
        const user = userEvent.setup();
        deleteWorkplaceMock.mockRejectedValue(
            new ApiError({ status: 400, message: 'WORKPLACE_CANNOT_REMOVE_USER_WITH_SHIFT', data: {} }),
        );

        render(<UserWorkplaces id="42" />);

        await user.click(screen.getAllByRole('button', { name: '🗑' })[0]);

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith('error|Could not remove user from workplace: User has shifts');
        });
    });
});
