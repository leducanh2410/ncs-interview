import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SalaryIndexPage from '@/pages/manage/salary/index.page';

const { exportSalaryMock, useGetSalariesQueryMock, usePermissionsMock, showErrorMock, routerMock } = vi.hoisted(() => ({
    exportSalaryMock: vi.fn(),
    useGetSalariesQueryMock: vi.fn(),
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
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('react-icons/lu', () => ({
    LuCalendarHeart: () => React.createElement('span', null, 'schedule-icon'),
    LuFileSpreadsheet: () => React.createElement('span', null, 'export-icon'),
    LuUpload: () => React.createElement('span', null, 'upload-icon'),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled, href }: any) =>
        React.createElement('button', { onClick, disabled, 'data-href': href }, children),
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

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/manage/salary/api', () => ({
    useGetSalariesQuery: (...args: unknown[]) => useGetSalariesQueryMock(...args),
    exportSalary: (...args: unknown[]) => exportSalaryMock(...args),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) =>
        React.createElement(
            'div',
            { 'data-testid': 'search-box' },
            props.elements.map((el: any) =>
                React.createElement('span', { 'key': el.id, 'data-testid': `filter-${el.id}` }, el.label),
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

vi.mock('@/pages/manage/salary/partials/salary_deduction_import_modal', () => ({
    default: ({ opened }: any) => (opened ? React.createElement('div', null, 'deduction-modal') : null),
}));

vi.mock('@/pages/manage/salary/partials/salary_remaining_import_modal', () => ({
    default: ({ opened }: any) => (opened ? React.createElement('div', null, 'remaining-modal') : null),
}));

vi.mock('@/helpers/common.helper', () => ({
    default: { formatNumber: (value: number) => new Intl.NumberFormat('en-US').format(value) },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { now: () => ({ toDate: () => new Date('2026-05-10T00:00:00Z') }) },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showError: (...args: unknown[]) => showErrorMock(...args) },
}));

vi.mock('@/configs/route.config', () => ({
    default: { HOME: { path: '/' }, MANAGE_SALARY_PROCESS: { path: '/manage/salary/process' } },
}));

const ALL_PERMISSIONS = {
    SALARY_LIST: true,
    SALARY_IMPORT_DEDUCTION: true,
    SALARY_IMPORT_REMAINING: true,
    SALARY_EXPORT: true,
    SALARY_PROCESS_LIST: true,
};

const salaryRow = (overrides: Record<string, any> = {}) => ({
    id: 1,
    month: '2026-03',
    user: { id: 7, name: 'YAMADA TARO' },
    total_amount: 50000,
    total_deductions: 1000,
    total_fee: 330,
    work_days: 12,
    total_minutes: 3000,
    ...overrides,
});

const withRows = (rows: any[] = [salaryRow()], isLoading = false) =>
    useGetSalariesQueryMock.mockReturnValue({
        data: { data: { data: rows, total: rows.length } },
        isLoading,
    });

describe('SalaryIndexPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = { month: '2026-03' };
        usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS });
        withRows();
    });

    it('asks for the salary list with the filters in the URL', () => {
        render(<SalaryIndexPage />);

        expect(useGetSalariesQueryMock).toHaveBeenCalledWith({ month: '2026-03' }, true);
    });

    it('renders a salary row with money formatted and minutes shown as hours', () => {
        render(<SalaryIndexPage />);

        expect(screen.getByText('2026-03')).toBeInTheDocument();
        expect(screen.getByText('YAMADA TARO')).toBeInTheDocument();
        expect(screen.getByText('50,000')).toBeInTheDocument();
        expect(screen.getByText('1,000')).toBeInTheDocument();
        expect(screen.getByText('330')).toBeInTheDocument();
        expect(screen.getByText('12')).toBeInTheDocument();
        // 3000 minutes is 50 hours.
        expect(screen.getByText('50')).toBeInTheDocument();
    });

    it('offers a month and an employee filter', () => {
        render(<SalaryIndexPage />);

        expect(screen.getByTestId('filter-month')).toBeInTheDocument();
        expect(screen.getByTestId('filter-user_id')).toBeInTheDocument();
    });

    it('tells the table it may not show anything without the list permission', () => {
        usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS, SALARY_LIST: false });
        render(<SalaryIndexPage />);

        expect(useGetSalariesQueryMock).toHaveBeenCalledWith({ month: '2026-03' }, false);
        expect(screen.getByRole('table')).toHaveAttribute('data-no-permission', 'true');
    });

    it.each([
        ['Salary payment schedule', 'SALARY_PROCESS_LIST'],
        ['Import salary deduction', 'SALARY_IMPORT_DEDUCTION'],
        ['Import remaining salary', 'SALARY_IMPORT_REMAINING'],
        ['Export excel', 'SALARY_EXPORT'],
    ])('disables %s without its permission', (label, permission) => {
        usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS, [permission]: false });
        render(<SalaryIndexPage />);

        expect(screen.getByRole('button', { name: label })).toBeDisabled();
    });

    it('opens the deduction import modal', async () => {
        render(<SalaryIndexPage />);

        await userEvent.click(screen.getByRole('button', { name: 'Import salary deduction' }));

        expect(screen.getByText('deduction-modal')).toBeInTheDocument();
        expect(screen.queryByText('remaining-modal')).not.toBeInTheDocument();
    });

    it('opens the remaining-salary import modal', async () => {
        render(<SalaryIndexPage />);

        await userEvent.click(screen.getByRole('button', { name: 'Import remaining salary' }));

        expect(screen.getByText('remaining-modal')).toBeInTheDocument();
    });

    it('exports the list with the filters currently applied', async () => {
        render(<SalaryIndexPage />);

        await userEvent.click(screen.getByRole('button', { name: 'Export excel' }));

        await waitFor(() => expect(exportSalaryMock).toHaveBeenCalledWith({ month: '2026-03' }));
    });

    it('cannot export an empty month', () => {
        withRows([]);
        render(<SalaryIndexPage />);

        expect(screen.getByRole('button', { name: 'Export excel' })).toBeDisabled();
    });

    it('does not attempt an export when the page came back empty despite a non-zero total', async () => {
        useGetSalariesQueryMock.mockReturnValue({ data: { data: { data: [], total: 3 } }, isLoading: false });
        render(<SalaryIndexPage />);

        await userEvent.click(screen.getByRole('button', { name: 'Export excel' }));

        expect(exportSalaryMock).not.toHaveBeenCalled();
    });

    it('reports a failed export instead of leaving the admin guessing', async () => {
        exportSalaryMock.mockRejectedValue(new Error('network'));
        render(<SalaryIndexPage />);

        await userEvent.click(screen.getByRole('button', { name: 'Export excel' }));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|Could not export salary'));
    });

    it('passes the loading state through to the table', () => {
        withRows([], true);
        render(<SalaryIndexPage />);

        expect(screen.getByRole('table')).toHaveAttribute('data-fetching', 'true');
    });
});
