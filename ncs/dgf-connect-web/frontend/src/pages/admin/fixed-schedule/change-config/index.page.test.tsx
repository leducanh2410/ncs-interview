import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/admin/fixed-schedule/change-config/index.page';

const {
    dataTableSpy,
    commonSearchBoxSpy,
    useGetFixedScheduleChangeConfigsQueryMock,
    useUpdateFixedScheduleChangeConfigMutationMock,
    useBulkUpdateFixedScheduleChangeConfigMutationMock,
    usePermissionsMock,
    notificationsMock,
    routerMock,
} = vi.hoisted(() => ({
    dataTableSpy: vi.fn(),
    commonSearchBoxSpy: vi.fn(),
    useGetFixedScheduleChangeConfigsQueryMock: vi.fn(),
    useUpdateFixedScheduleChangeConfigMutationMock: vi.fn(),
    useBulkUpdateFixedScheduleChangeConfigMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    notificationsMock: { show: vi.fn() },
    routerMock: { query: {} as Record<string, any> },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, opts?: any) => (opts?.count !== undefined ? `${key}:${opts.count}` : key),
    }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', { 'data-testid': 'badge' }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick }: any) => React.createElement('button', { onClick }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    List: Object.assign(({ children }: any) => React.createElement('ul', null, children), {
        Item: ({ children }: any) => React.createElement('li', null, children),
    }),
    Modal: ({ children, title }: any) => React.createElement('div', { 'data-testid': 'modal' }, [title, children]),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Switch: ({ checked, onChange, disabled }: any) =>
        React.createElement('input', { 'type': 'checkbox', checked, onChange, disabled, 'data-testid': 'switch' }),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
    Tooltip: ({ children }: any) => children,
}));

vi.mock('@mantine/dates', () => ({
    DatePickerInput: ({ value, onChange, disabled }: any) =>
        React.createElement('input', {
            'data-testid': 'date-input',
            'value': value ?? '',
            disabled,
            'onChange': () => onChange?.('2026-08-15'),
        }),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: notificationsMock,
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/fixed-schedule/change-config/api', () => ({
    useGetFixedScheduleChangeConfigsQuery: (...args: unknown[]) => useGetFixedScheduleChangeConfigsQueryMock(...args),
    useUpdateFixedScheduleChangeConfigMutation: () => useUpdateFixedScheduleChangeConfigMutationMock(),
    useBulkUpdateFixedScheduleChangeConfigMutation: () => useBulkUpdateFixedScheduleChangeConfigMutationMock(),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) => {
        commonSearchBoxSpy(props);
        return React.createElement(
            'div',
            { 'data-testid': 'search-box' },
            props.elements.map((el: any) => React.createElement('span', { key: el.id }, el.label)),
        );
    },
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        dataTableSpy(props);
        const records = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);
        return React.createElement(
            'div',
            null,
            React.createElement('button', {
                'data-testid': 'select-all',
                'onClick': () => props.onSelectedRecordsChange?.(records),
            }),
            records.map((record: any) =>
                React.createElement(
                    'div',
                    { key: record.id },
                    props.columns.map((col: any) =>
                        React.createElement('div', { key: col.key }, col.render ? col.render(record) : record[col.key]),
                    ),
                ),
            ),
        );
    },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parseAndFormat: () => '2026-08-15',
        parse: (v: string) => ({ toDate: () => new Date(v) }),
        now: () => ({
            format: () => '2026-07-16',
            add: () => ({ format: () => '2026-07-17' }),
        }),
    },
    DATE_STORE_FORMAT: 'YYYY-MM-DD',
}));

const makeConfig = (overrides: Record<string, any> = {}) => ({
    id: 1,
    workplace_id: 10,
    workplace: { id: 10, name: 'Tokyo HQ' },
    is_enabled: true,
    deadline: '2026-07-20',
    is_window_open: true,
    is_editable: true,
    updated_at: '2026-07-01T00:00:00Z',
    ...overrides,
});

const allPerms = {
    FIXED_SCHEDULE_CHANGE_CONFIG_LIST: true,
    FIXED_SCHEDULE_CHANGE_CONFIG_EDIT: true,
};

describe('Fixed schedule change config page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = {};

        usePermissionsMock.mockReturnValue(allPerms);
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [makeConfig()] },
            isPending: false,
        });
        useUpdateFixedScheduleChangeConfigMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useBulkUpdateFixedScheduleChangeConfigMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
    });

    it('inline switch toggle calls the update mutation', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUpdateFixedScheduleChangeConfigMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        fireEvent.click(screen.getByTestId('switch'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith({
                workplaceId: 10,
                data: { is_enabled: false, deadline: '2026-07-20' },
            });
        });
    });

    it('bulk toolbar only appears once a row is selected', () => {
        render(<IndexPage />);

        expect(screen.queryByText(/Enable for/)).not.toBeInTheDocument();

        fireEvent.click(screen.getByTestId('select-all'));

        expect(screen.getByText(/Enable for/)).toBeInTheDocument();
        expect(screen.getByText(/Disable for/)).toBeInTheDocument();
    });

    it('bulk modal submits combined payload for all selected workplaces', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useBulkUpdateFixedScheduleChangeConfigMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: {
                data: [makeConfig(), makeConfig({ id: 2, workplace_id: 11, workplace: { id: 11, name: 'Osaka' } })],
            },
            isPending: false,
        });

        render(<IndexPage />);

        fireEvent.click(screen.getByTestId('select-all'));
        fireEvent.click(screen.getByText(/Enable for/));

        await waitFor(() => {
            expect(screen.getByText(/Confirm/)).toBeInTheDocument();
        });
        fireEvent.click(screen.getByText('Confirm'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(
                expect.objectContaining({ workplace_ids: [10, 11], is_enabled: true }),
            );
        });
    });

    it('shows an expired badge distinct from the disabled state', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [makeConfig({ is_enabled: true, is_window_open: false })] },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByTestId('badge')).toHaveTextContent('Expired');
    });

    it('does not show the expired badge when disabled', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [makeConfig({ is_enabled: false, is_window_open: false })] },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.queryByTestId('badge')).not.toBeInTheDocument();
    });

    it('only renders the workplaces returned by the (server-scoped) list query', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [makeConfig({ workplace: { id: 10, name: 'Managed Workplace Only' } })] },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByText('Managed Workplace Only')).toBeInTheDocument();
        expect(screen.queryByText('Unmanaged Workplace')).not.toBeInTheDocument();
    });

    it('disables the switch when no deadline is set yet', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [makeConfig({ deadline: null, is_enabled: false })] },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByTestId('switch')).toBeDisabled();
    });

    it('enables the switch once a deadline is set', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [makeConfig({ deadline: '2026-07-20', is_enabled: false })] },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByTestId('switch')).not.toBeDisabled();
    });

    it('does not disable the switch when the deadline is today', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [makeConfig({ deadline: '2026-07-16', is_enabled: false })] },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByTestId('switch')).not.toBeDisabled();
    });

    it('disables the switch when the deadline is in the past and it is currently off', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [makeConfig({ deadline: '2026-07-15', is_enabled: false })] },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByTestId('switch')).toBeDisabled();
    });

    it('still allows turning off an already-enabled row even if its deadline has expired', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [makeConfig({ deadline: '2026-07-01', is_enabled: true, is_window_open: false })] },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByTestId('switch')).not.toBeDisabled();
    });

    it('filters the table by the selected workplace from the search box', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: {
                data: [makeConfig(), makeConfig({ id: 2, workplace_id: 11, workplace: { id: 11, name: 'Osaka' } })],
            },
            isPending: false,
        });
        routerMock.query = { workplace_id: '11' };

        render(<IndexPage />);

        expect(screen.getByText('Osaka')).toBeInTheDocument();
        expect(screen.queryByText('Tokyo HQ')).not.toBeInTheDocument();
    });

    it('passes all workplaces from the list query as search box options', () => {
        routerMock.query = {};
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: {
                data: [makeConfig(), makeConfig({ id: 2, workplace_id: 11, workplace: { id: 11, name: 'Osaka' } })],
            },
            isPending: false,
        });

        render(<IndexPage />);

        expect(commonSearchBoxSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                elements: expect.arrayContaining([
                    expect.objectContaining({
                        name: 'workplace_id',
                        options: [
                            { value: '10', label: 'Tokyo HQ' },
                            { value: '11', label: 'Osaka' },
                        ],
                    }),
                ]),
            }),
        );
    });

    it('disables the switch and deadline picker on a non-editable row despite holding edit permission elsewhere', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [makeConfig({ is_editable: false })] },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByTestId('switch')).toBeDisabled();
        expect(screen.getByTestId('date-input')).toBeDisabled();
    });

    it('excludes non-editable rows from bulk selection', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: {
                data: [
                    makeConfig(),
                    makeConfig({ id: 2, workplace_id: 11, workplace: { id: 11, name: 'Osaka' }, is_editable: false }),
                ],
            },
            isPending: false,
        });

        render(<IndexPage />);

        const { isRecordSelectable } = dataTableSpy.mock.calls[0][0];
        expect(isRecordSelectable({ is_editable: true })).toBe(true);
        expect(isRecordSelectable({ is_editable: false })).toBe(false);
    });

    it('passes FIXED_SCHEDULE_CHANGE_CONFIG_LIST permission to the query', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, FIXED_SCHEDULE_CHANGE_CONFIG_LIST: false });

        render(<IndexPage />);

        expect(useGetFixedScheduleChangeConfigsQueryMock).toHaveBeenCalledWith(false);
        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ noPermission: true }));
    });
});
