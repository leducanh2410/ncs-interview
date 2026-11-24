import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AdminUserIndexPage from '@/pages/admin/user/index.page';

const { useGetUsersQueryMock, useGetGroupsQueryMock, usePermissionsMock, searchBoxSpy, dataTableSpy, routerMock } =
    vi.hoisted(() => ({
        useGetUsersQueryMock: vi.fn(),
        useGetGroupsQueryMock: vi.fn(),
        usePermissionsMock: vi.fn(),
        searchBoxSpy: vi.fn(),
        dataTableSpy: vi.fn(),
        routerMock: { query: {}, push: vi.fn() },
    }));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({ useRouter: () => routerMock }));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children }: any) => React.createElement('div', { 'data-testid': 'alert' }, children),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, disabled, onClick, component, href }: any) =>
        component === undefined
            ? React.createElement('button', { type: 'button', disabled, onClick }, children)
            : React.createElement('a', { href, disabled }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h2', null, children),
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (initial = false) => {
        const [isOpen, setIsOpen] = React.useState(initial);
        return [isOpen, { open: () => setIsOpen(true), close: () => setIsOpen(false) }];
    },
}));

vi.mock('jotai', () => ({
    useAtom: () => [{ workplaces: [], roles: [] }, vi.fn()],
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: null,
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/configs/route.config', () => ({
    default: {
        HOME: { path: '/' },
        ADMIN_USER_CREATE: { path: '/admin/user/create' },
    },
}));

vi.mock('@/helpers/workplace.helper', () => ({
    default: { toSelectOptions: () => [] },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        toSelectArray: () => [],
        getText: (_e: any, value: string) => value,
    },
}));

vi.mock('@/enums/user_paper_status.enum', () => ({
    UserPaperStatusEnum: {},
}));

vi.mock('@/utils/group_display_name', () => ({
    getGroupDisplayName: (group: any) => group.name,
}));

vi.mock('react-icons/lu', () => ({
    LuCheck: () => React.createElement('span', null, '✓'),
    LuFileText: () => React.createElement('span', null, '📄'),
    LuPlus: () => React.createElement('span', null, '+'),
    LuX: () => React.createElement('span', null, '✗'),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) => {
        searchBoxSpy(props);
        return React.createElement(
            'div',
            { 'data-testid': 'search-box' },
            props.elements.map((el: any) =>
                React.createElement('span', { 'key': el.id, 'data-element-id': el.id }, el.label),
            ),
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

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => React.createElement('span', null, user?.name ?? '-'),
}));

vi.mock('@/pages/admin/user/partials/create_manager_modal', () => ({
    default: () => null,
}));

vi.mock('@/pages/admin/user/partials/export_pdf_modal', () => ({
    default: () => null,
}));

vi.mock('@/pages/admin/user/partials/export_csv_modal', () => ({
    default: () => null,
}));

vi.mock('@/pages/admin/user/partials/user_custom_configs_cell', () => ({
    default: ({ configs }: any) =>
        React.createElement('span', { 'data-testid': 'custom-configs-cell' }, (configs ?? []).length),
}));

vi.mock('@/pages/admin/user/api', () => ({
    useGetUsersQuery: (...args: unknown[]) => useGetUsersQueryMock(...args),
}));

vi.mock('@/pages/admin/group/api', () => ({
    useGetGroupsQuery: (...args: unknown[]) => useGetGroupsQueryMock(...args),
}));

const fakeUser = {
    id: 10,
    name: 'Jane Smith',
    email: 'jane@example.com',
    staff_no: 'E-010',
    is_test: false,
    is_disabled: false,
    face_registered: true,
    info: { phone: '090-0000-0000' },
    workplaces: [{ id: 1, name: 'Tokyo HQ' }],
    roleNames: 'manager',
};

const allPerms = {
    USER_LIST: true,
    USER_CREATE: true,
    USER_CREATE_EMPLOYEE: true,
    PDF_TEMPLATE_EXPORT: true,
    USER_EXPORT_CSV: true,
    GROUP_VIEW: true,
    USER_CONFIG_LIST: true,
};

describe('AdminUserIndexPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = {};

        usePermissionsMock.mockReturnValue(allPerms);

        useGetUsersQueryMock.mockReturnValue({
            data: { data: { data: [fakeUser], current_page: 1, last_page: 1, per_page: 15, total: 1 } },
            isPending: false,
        });

        useGetGroupsQueryMock.mockReturnValue({
            data: { data: { data: [], total: 0 } },
        });
    });

    it('renders all core search filter fields', () => {
        render(<AdminUserIndexPage />);

        expect(screen.getByTestId('search-box')).toBeInTheDocument();
        expect(screen.getByText('Employee ID')).toBeInTheDocument();
        expect(screen.getByText('Name')).toBeInTheDocument();
        expect(screen.getByText('Email')).toBeInTheDocument();
        expect(screen.getByText('Workplace')).toBeInTheDocument();
        expect(screen.getByText('Active status')).toBeInTheDocument();
        expect(screen.getByText('Role')).toBeInTheDocument();
        expect(screen.getByText('Face registered')).toBeInTheDocument();
    });

    it('includes the groups filter when GROUP_VIEW permission is granted', () => {
        render(<AdminUserIndexPage />);

        const passedProps = searchBoxSpy.mock.calls[0][0];
        const elementIds = passedProps.elements.map((el: any) => el.id);
        expect(elementIds).toContain('groups');
    });

    it('omits the groups filter when GROUP_VIEW permission is absent', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, GROUP_VIEW: false });

        render(<AdminUserIndexPage />);

        const passedProps = searchBoxSpy.mock.calls[0][0];
        const elementIds = passedProps.elements.map((el: any) => el.id);
        expect(elementIds).not.toContain('groups');
    });

    it('includes the custom configuration filter when USER_CONFIG_LIST permission is granted', () => {
        render(<AdminUserIndexPage />);

        const passedProps = searchBoxSpy.mock.calls[0][0];
        const element = passedProps.elements.find((el: any) => el.id === 'custom_config');
        expect(element.options.map((option: any) => option.value)).toEqual(['all', '1']);
        expect(passedProps.initialValues.custom_config).toBe('all');
    });

    it('omits the custom configuration filter and column without USER_CONFIG_LIST permission', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_CONFIG_LIST: false });
        routerMock.query = { custom_config: '1' };

        render(<AdminUserIndexPage />);

        const searchProps = searchBoxSpy.mock.calls[0][0];
        expect(searchProps.elements.map((el: any) => el.id)).not.toContain('custom_config');
        expect(searchProps.initialValues).not.toHaveProperty('custom_config');
        const tableProps = dataTableSpy.mock.calls[0][0];
        expect(tableProps.columns.map((col: any) => col.key)).not.toContain('custom_configs');
    });

    it('hides the custom configuration column until the different-from-default filter is chosen', () => {
        render(<AdminUserIndexPage />);

        const tableProps = dataTableSpy.mock.calls[0][0];
        expect(tableProps.columns.map((col: any) => col.key)).not.toContain('custom_configs');
    });

    it('renders the custom configuration of each user in the table', () => {
        routerMock.query = { custom_config: '1' };
        useGetUsersQueryMock.mockReturnValue({
            data: {
                data: {
                    data: [
                        {
                            ...fakeUser,
                            custom_configs: [
                                {
                                    key: 'max_hour_per_week',
                                    value: 30,
                                    default_value: 28,
                                    start_date: null,
                                    expiry_date: null,
                                },
                            ],
                        },
                    ],
                    current_page: 1,
                    last_page: 1,
                    per_page: 15,
                    total: 1,
                },
            },
            isPending: false,
        });

        render(<AdminUserIndexPage />);

        expect(screen.getByTestId('custom-configs-cell')).toHaveTextContent('1');
    });

    it('renders user rows in the table', () => {
        render(<AdminUserIndexPage />);

        expect(screen.getByText('Jane Smith')).toBeInTheDocument();
        expect(screen.getByText('jane@example.com')).toBeInTheDocument();
    });

    it('always shows the create manager button', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_CREATE: false });

        render(<AdminUserIndexPage />);

        expect(screen.getByRole('button', { name: 'Create manager' })).toBeInTheDocument();
    });

    it('disables the create manager button without USER_CREATE permission', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_CREATE: false });

        render(<AdminUserIndexPage />);

        expect(screen.getByRole('button', { name: 'Create manager' })).toBeDisabled();
    });

    it('shows the create employee link when USER_CREATE_EMPLOYEE is granted', () => {
        render(<AdminUserIndexPage />);

        expect(screen.getByRole('link', { name: 'Create employee' })).toBeInTheDocument();
    });

    it('hides the create employee link without USER_CREATE_EMPLOYEE permission', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_CREATE_EMPLOYEE: false });

        render(<AdminUserIndexPage />);

        expect(screen.queryByRole('link', { name: 'Create employee' })).not.toBeInTheDocument();
    });

    it('passes noPermission flag to table when USER_LIST is absent', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_LIST: false });

        render(<AdminUserIndexPage />);

        const tableProps = dataTableSpy.mock.calls[0][0];
        expect(tableProps.noPermission).toBe(true);
    });
});
