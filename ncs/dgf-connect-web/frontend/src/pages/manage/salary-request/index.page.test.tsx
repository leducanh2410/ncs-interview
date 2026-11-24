import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SalaryRequestIndexPage from '@/pages/manage/salary-request/index.page';

const { exportSalaryRequestMock, useGetSalaryRequestQueryMock, usePermissionsMock, showErrorMock, routerMock } =
    vi.hoisted(() => ({
        exportSalaryRequestMock: vi.fn(),
        useGetSalaryRequestQueryMock: vi.fn(),
        usePermissionsMock: vi.fn(),
        showErrorMock: vi.fn(),
        routerMock: { query: {} as Record<string, string> },
    }));

vi.mock('next/link', () => ({
    default: ({ children, href }: any) => React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

// EnumHelper is used for real here, so the status labels come from the enum itself.
vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

vi.mock('react-icons/lu', () => ({
    LuFileSpreadsheet: () => React.createElement('span', null, 'export-icon'),
    LuPlus: () => React.createElement('span', null, 'plus-icon'),
    LuUpload: () => React.createElement('span', null, 'upload-icon'),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled }: any) => React.createElement('button', { onClick, disabled }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (initial: boolean) => {
        const [opened, setOpened] = React.useState(initial);
        return [opened, { open: () => setOpened(true), close: () => setOpened(false) }];
    },
}));

vi.mock('dayjs', () => ({
    default: (value: string) => ({ tz: () => `tz(${value})` }),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/manage/salary-request/api', () => ({
    useGetSalaryRequestQuery: (...args: unknown[]) => useGetSalaryRequestQueryMock(...args),
    exportSalaryRequest: (...args: unknown[]) => exportSalaryRequestMock(...args),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) =>
        React.createElement(
            'div',
            { 'data-testid': 'search-box' },
            props.elements.map((el: any) =>
                React.createElement(
                    'span',
                    { 'key': el.id, 'data-testid': `filter-${el.id}`, 'data-options': (el.options || []).length },
                    el.label,
                ),
            ),
        ),
    userRenderItem: vi.fn(),
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        const records: any[] = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);
        return React.createElement(
            'table',
            { 'data-no-permission': String(!!props.noPermission), 'data-fetching': String(!!props.isFetching) },
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
    default: ({ user }: any) => React.createElement('span', null, user?.name),
}));

vi.mock('@/pages/manage/salary-request/partials/salary_create_modal', () => ({
    default: ({ opened }: any) => (opened ? React.createElement('div', null, 'create-modal') : null),
}));

vi.mock('@/pages/manage/salary-request/partials/salary_transfer_import_modal', () => ({
    default: ({ opened }: any) => (opened ? React.createElement('div', null, 'transfer-modal') : null),
}));

vi.mock('@/helpers/common.helper', () => ({
    default: { formatNumber: (value: number) => new Intl.NumberFormat('en-US').format(value) },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { format: (value: unknown) => String(value) },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showError: (...args: unknown[]) => showErrorMock(...args) },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: (_route: string, params: any) => `/manage/salary-request/${params.id}` },
}));

vi.mock('@/configs/route.config', () => ({
    default: { HOME: { path: '/' } },
}));

const ALL_PERMISSIONS = {
    SALARY_REQUEST_LIST: true,
    SALARY_REQUEST_CREATE: true,
    SALARY_REQUEST_EXPORT: true,
    SALARY_IMPORT_TRANSFER: true,
};

const requestRow = (overrides: Record<string, any> = {}) => ({
    id: 42,
    created_at: '2026-05-10 12:00:00',
    user: { id: 7, name: 'YAMADA TARO' },
    amount: 51000,
    status: 'wait_for_payment',
    ...overrides,
});

const withRows = (rows: any[] = [requestRow()], isLoading = false) =>
    useGetSalaryRequestQueryMock.mockReturnValue({
        data: { data: { data: rows, total: rows.length } },
        isLoading,
    });

describe('SalaryRequestIndexPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = { status: 'all' };
        usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS });
        withRows();
    });

    it('asks for the request list with the filters in the URL', () => {
        render(<SalaryRequestIndexPage />);

        expect(useGetSalaryRequestQueryMock).toHaveBeenCalledWith({ status: 'all' }, true);
    });

    it('renders a request row with the amount formatted and the status spelled out', () => {
        render(<SalaryRequestIndexPage />);

        expect(screen.getByText('tz(2026-05-10 12:00:00)')).toBeInTheDocument();
        expect(screen.getByText('YAMADA TARO')).toBeInTheDocument();
        expect(screen.getByText('51,000')).toBeInTheDocument();
        expect(screen.getByText('enum|Wait for payment')).toBeInTheDocument();
    });

    it('links each row to its detail page', () => {
        render(<SalaryRequestIndexPage />);

        expect(screen.getByRole('link', { name: 'Detail' })).toHaveAttribute('href', '/manage/salary-request/42');
    });

    it('offers an employee filter and every request status', () => {
        render(<SalaryRequestIndexPage />);

        expect(screen.getByTestId('filter-user_id')).toBeInTheDocument();
        expect(screen.getByTestId('filter-status')).toHaveAttribute('data-options', '9');
    });

    it('tells the table it may not show anything without the list permission', () => {
        usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS, SALARY_REQUEST_LIST: false });
        render(<SalaryRequestIndexPage />);

        expect(useGetSalaryRequestQueryMock).toHaveBeenCalledWith({ status: 'all' }, false);
        expect(screen.getByRole('table')).toHaveAttribute('data-no-permission', 'true');
    });

    it.each([
        ['Import transfer info', 'SALARY_IMPORT_TRANSFER'],
        ['Create request for user', 'SALARY_REQUEST_CREATE'],
        ['Export excel', 'SALARY_REQUEST_EXPORT'],
    ])('disables %s without its permission', (label, permission) => {
        usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS, [permission]: false });
        render(<SalaryRequestIndexPage />);

        expect(screen.getByRole('button', { name: label })).toBeDisabled();
    });

    it('opens the create-on-behalf modal', async () => {
        render(<SalaryRequestIndexPage />);

        await userEvent.click(screen.getByRole('button', { name: 'Create request for user' }));

        expect(screen.getByText('create-modal')).toBeInTheDocument();
    });

    it('opens the transfer import modal', async () => {
        render(<SalaryRequestIndexPage />);

        await userEvent.click(screen.getByRole('button', { name: 'Import transfer info' }));

        expect(screen.getByText('transfer-modal')).toBeInTheDocument();
    });

    it('exports the list with the filters currently applied', async () => {
        render(<SalaryRequestIndexPage />);

        await userEvent.click(screen.getByRole('button', { name: 'Export excel' }));

        await waitFor(() => expect(exportSalaryRequestMock).toHaveBeenCalledWith({ status: 'all' }));
    });

    it('cannot export an empty list', () => {
        withRows([]);
        render(<SalaryRequestIndexPage />);

        expect(screen.getByRole('button', { name: 'Export excel' })).toBeDisabled();
    });

    it('does not attempt an export when the page came back empty despite a non-zero total', async () => {
        useGetSalaryRequestQueryMock.mockReturnValue({ data: { data: { data: [], total: 3 } }, isLoading: false });
        render(<SalaryRequestIndexPage />);

        await userEvent.click(screen.getByRole('button', { name: 'Export excel' }));

        expect(exportSalaryRequestMock).not.toHaveBeenCalled();
    });

    it('reports a failed export', async () => {
        exportSalaryRequestMock.mockRejectedValue(new Error('network'));
        render(<SalaryRequestIndexPage />);

        await userEvent.click(screen.getByRole('button', { name: 'Export excel' }));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|Could not export salary request'));
    });

    it('passes the loading state through to the table', () => {
        withRows([], true);
        render(<SalaryRequestIndexPage />);

        expect(screen.getByRole('table')).toHaveAttribute('data-fetching', 'true');
    });
});
