import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SalaryProcessIndexPage from '@/pages/manage/salary/process/index.page';

const { deleteMutateMock, useGetSalaryProcessesQueryMock, usePermissionsMock, notificationsShowMock, routerMock } =
    vi.hoisted(() => ({
        deleteMutateMock: vi.fn(),
        useGetSalaryProcessesQueryMock: vi.fn(),
        usePermissionsMock: vi.fn(),
        notificationsShowMock: vi.fn(),
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

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

vi.mock('react-icons/lu', () => ({
    LuPencilLine: () => React.createElement('span', null, 'edit-icon'),
    LuPlus: () => React.createElement('span', null, 'plus-icon'),
    LuTrash2: () => React.createElement('span', null, 'delete-icon'),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled, color }: any) =>
        React.createElement('button', { onClick, disabled, 'data-color': color }, children),
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

vi.mock('@mantine/notifications', () => ({
    notifications: { show: (...args: unknown[]) => notificationsShowMock(...args) },
}));

vi.mock('jotai', () => ({
    useAtom: () => [{ workplaces: [{ id: 3, name: 'Workplace A' }] }, vi.fn()],
    atom: vi.fn(),
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: {},
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/manage/salary/process/api', () => ({
    useGetSalaryProcessesQuery: (...args: unknown[]) => useGetSalaryProcessesQueryMock(...args),
    useDeleteSalaryProcessMutation: () => ({ mutateAsync: deleteMutateMock }),
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
}));

vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, title, onConfirm, onCancel }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'confirm-modal' },
                  React.createElement('span', null, title),
                  React.createElement('button', { onClick: onConfirm }, 'confirm-delete'),
                  React.createElement('button', { onClick: onCancel }, 'cancel-delete'),
              )
            : null,
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        const records: any[] = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);
        return React.createElement(
            'table',
            { 'data-fetching': String(!!props.isFetching) },
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

vi.mock('@/pages/manage/salary/process/partials/salary_process_create_update_modal', () => ({
    default: (props: any) => {
        return props.opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'process-modal' },
                  `editing:${props.salaryProcess?.id ?? 'none'}`,
              )
            : null;
    },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parseAndFormat: (value: string | null) => (value ? `fmt(${value})` : ''),
    },
    DATE_STORE_FORMAT_MONTH: 'YYYY-MM',
    DATE_STORE_FORMAT_WITH_TIME: 'YYYY-MM-DD HH:mm:ss',
}));

vi.mock('@/configs/route.config', () => ({
    default: { HOME: { path: '/' } },
}));

const ALL_PERMISSIONS = {
    SALARY_PROCESS_LIST: true,
    SALARY_PROCESS_CREATE: true,
    SALARY_PROCESS_EDIT: true,
    SALARY_PROCESS_DELETE: true,
};

const processRow = (overrides: Record<string, any> = {}) => ({
    id: 12,
    month: '2026-04',
    scheduled_at: '2026-06-01 03:00:00',
    workplace: { id: 3, name: 'Workplace A' },
    status: 'pending',
    priority: 7,
    ...overrides,
});

const withRows = (rows: any[] = [processRow()], isLoading = false) =>
    useGetSalaryProcessesQueryMock.mockReturnValue({
        data: { data: { data: rows, total: rows.length } },
        isLoading,
    });

const editButton = () => screen.getAllByRole('button', { name: 'edit-icon' })[0];
const deleteButton = () => screen.getAllByRole('button', { name: 'delete-icon' })[0];

describe('SalaryProcessIndexPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = { month: '2026-04' };
        usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS });
        deleteMutateMock.mockResolvedValue({ data: true });
        withRows();
    });

    it('asks for the schedule list with the filters in the URL', () => {
        render(<SalaryProcessIndexPage />);

        expect(useGetSalaryProcessesQueryMock).toHaveBeenCalledWith({ month: '2026-04' }, true);
    });

    it('renders a schedule row with both dates formatted for display', () => {
        render(<SalaryProcessIndexPage />);

        expect(screen.getByText('fmt(2026-04)')).toBeInTheDocument();
        expect(screen.getByText('fmt(2026-06-01 03:00:00)')).toBeInTheDocument();
        expect(screen.getByText('Workplace A')).toBeInTheDocument();
        expect(screen.getByText('pending')).toBeInTheDocument();
        expect(screen.getByText('7')).toBeInTheDocument();
    });

    it('offers a month filter and the workplaces from the master data', () => {
        render(<SalaryProcessIndexPage />);

        expect(screen.getByTestId('filter-month')).toBeInTheDocument();
        expect(screen.getByTestId('filter-workplace_id')).toHaveAttribute('data-options', '1');
    });

    it('disables creating without the create permission', () => {
        usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS, SALARY_PROCESS_CREATE: false });
        render(<SalaryProcessIndexPage />);

        expect(screen.getByRole('button', { name: 'Create' })).toBeDisabled();
    });

    it('opens a blank modal from the create button', async () => {
        render(<SalaryProcessIndexPage />);

        await userEvent.click(screen.getByRole('button', { name: 'Create' }));

        expect(screen.getByTestId('process-modal')).toHaveTextContent('editing:none');
    });

    it('opens the modal on the row being edited', async () => {
        render(<SalaryProcessIndexPage />);

        await userEvent.click(editButton());

        expect(screen.getByTestId('process-modal')).toHaveTextContent('editing:12');
    });

    it.each([
        ['processing', 'the job is already running it'],
        ['completed', 'the job has finished with it'],
    ])('locks both row actions once the schedule is %s', (status) => {
        withRows([processRow({ status })]);
        render(<SalaryProcessIndexPage />);

        expect(editButton()).toBeDisabled();
        expect(deleteButton()).toBeDisabled();
    });

    it('locks editing without the edit permission but leaves deleting available', () => {
        usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS, SALARY_PROCESS_EDIT: false });
        render(<SalaryProcessIndexPage />);

        expect(editButton()).toBeDisabled();
        expect(deleteButton()).toBeEnabled();
    });

    it('locks deleting without the delete permission', () => {
        usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS, SALARY_PROCESS_DELETE: false });
        render(<SalaryProcessIndexPage />);

        expect(deleteButton()).toBeDisabled();
    });

    it('asks for confirmation before deleting', async () => {
        render(<SalaryProcessIndexPage />);

        await userEvent.click(deleteButton());

        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();
        expect(deleteMutateMock).not.toHaveBeenCalled();
    });

    it('deletes the confirmed schedule and reports it', async () => {
        render(<SalaryProcessIndexPage />);
        await userEvent.click(deleteButton());

        await userEvent.click(screen.getByRole('button', { name: 'confirm-delete' }));

        await waitFor(() => expect(deleteMutateMock).toHaveBeenCalledWith(12));
        expect(notificationsShowMock).toHaveBeenCalledWith({
            color: 'teal',
            message: 'Successfully deleted salary payment schedule',
        });
        await waitFor(() => expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument());
    });

    it('reports a refused deletion and still closes the confirmation', async () => {
        deleteMutateMock.mockRejectedValue(new Error('already running'));
        render(<SalaryProcessIndexPage />);
        await userEvent.click(deleteButton());

        await userEvent.click(screen.getByRole('button', { name: 'confirm-delete' }));

        await waitFor(() =>
            expect(notificationsShowMock).toHaveBeenCalledWith({
                color: 'red',
                message: 'error|Could not delete salary payment schedule',
            }),
        );
        await waitFor(() => expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument());
    });

    it('deletes nothing when the confirmation is dismissed', async () => {
        render(<SalaryProcessIndexPage />);
        await userEvent.click(deleteButton());

        await userEvent.click(screen.getByRole('button', { name: 'cancel-delete' }));

        expect(deleteMutateMock).not.toHaveBeenCalled();
        expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();
    });

    it('passes the loading state through to the table', () => {
        withRows([], true);
        render(<SalaryProcessIndexPage />);

        expect(screen.getByRole('table')).toHaveAttribute('data-fetching', 'true');
    });
});
