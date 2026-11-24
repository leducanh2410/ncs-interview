import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import NotificationHelper from '@/helpers/notification.helper';
import IndexPage from '@/pages/manage/absent-notice/index.page';

const {
    commonSearchBoxSpy,
    dataTableSpy,
    useGetAbsentNoticesQueryMock,
    useUpdateAbsentNoticeStatusMutationMock,
    usePermissionsMock,
    routerMock,
} = vi.hoisted(() => ({
    commonSearchBoxSpy: vi.fn(),
    dataTableSpy: vi.fn(),
    useGetAbsentNoticesQueryMock: vi.fn(),
    useUpdateAbsentNoticeStatusMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    routerMock: { query: {} as Record<string, unknown> },
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
        t: (key: string, options?: Record<string, unknown>) => {
            if (!options) return key;
            return Object.entries(options).reduce((acc, [k, v]) => acc.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children, color }: any) => React.createElement('span', { 'data-color': color }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Box: ({ children }: any) => React.createElement('span', null, children),
    CheckIcon: () => React.createElement('span', null, '✓'),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Select: ({ value, onChange, data, disabled }: any) =>
        React.createElement(
            'select',
            {
                value: value ?? '',
                disabled,
                onChange: (event: any) => onChange(event.target.value),
            },
            data.map((item: any) => React.createElement('option', { key: item.value, value: item.value }, item.label)),
        ),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('jotai', () => ({
    atom: (initial: unknown) => ({ init: initial }),
    useAtom: () => [
        {
            workplaces: [
                { id: 1, name: 'Warehouse A' },
                { id: 2, name: 'Warehouse B' },
            ],
            workplace_access: {},
        },
    ],
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        now: () => ({
            toDate: () => new Date('2026-06-26T00:00:00Z'),
            add: (_n: number, _unit: string) => ({
                toDate: () => new Date('2026-07-03T00:00:00Z'),
            }),
        }),
        parseAndFormat: (value: string | null, _tz?: unknown, _fmt?: string) => (value ? `FORMATTED(${value})` : '-'),
    },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        toSelectArray: (enumObj: any) => Object.values(enumObj).map((e: any) => ({ value: e.value, label: e.text })),
        getText: (_enumObj: any, value: string) => value,
        getColor: (_enumObj: any, _value: string) => 'gray',
    },
}));

vi.mock('@/helpers/workplace.helper', () => ({
    default: {
        filterWorkplacesByAccess: (workplaces: any[]) => workplaces,
    },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: vi.fn(),
        showError: vi.fn(),
    },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: {
        getPath: (_name: string, params: Record<string, string>) => `/manage/shift/${params.id}`,
    },
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/manage/absent-notice/api', () => ({
    useGetAbsentNoticesQuery: (...args: unknown[]) => useGetAbsentNoticesQueryMock(...args),
    useUpdateAbsentNoticeStatusMutation: (...args: unknown[]) => useUpdateAbsentNoticeStatusMutationMock(...args),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) => {
        commonSearchBoxSpy(props);

        return React.createElement(
            'div',
            { 'data-testid': 'search-box' },
            props.elements.map((el: any) =>
                React.createElement(
                    'div',
                    { key: el.id },
                    React.createElement('span', null, el.label),
                    el.options
                        ? React.createElement(
                              'ul',
                              null,
                              el.options.map((opt: any) => React.createElement('li', { key: opt.value }, opt.label)),
                          )
                        : null,
                ),
            ),
        );
    },
}));

vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, title, message, onConfirm, onCancel }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'confirm-modal' },
                  React.createElement('h2', null, title),
                  React.createElement('p', null, message),
                  React.createElement('button', { onClick: onConfirm }, 'do-confirm'),
                  React.createElement('button', { onClick: onCancel }, 'do-cancel'),
              )
            : null,
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        dataTableSpy(props);

        const records = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);

        return React.createElement(
            'table',
            { 'data-no-permission': props.noPermission ? 'true' : 'false' },
            React.createElement(
                'thead',
                null,
                React.createElement(
                    'tr',
                    null,
                    props.columns.map((col: any) => React.createElement('th', { key: col.key }, col.name)),
                ),
            ),
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

const buildAbsentNotice = (overrides: Record<string, unknown> = {}) => ({
    id: 1,
    shift_id: 10,
    shift_date: '2026-06-27',
    workplace_name: 'Warehouse A',
    shift_time: '09:00 - 18:00',
    user_name: 'YAMADA TARO',
    position_name: 'Loader',
    requested_at: '2026-06-26T08:00:00+09:00',
    status: 'pending',
    processed_by_name: null,
    processed_at: null,
    ...overrides,
});

describe('Absent notice index page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = {};

        usePermissionsMock.mockReturnValue({ SHIFT_LIST: true, SHIFT_EDIT: true });
        useGetAbsentNoticesQueryMock.mockReturnValue({ data: { data: [buildAbsentNotice()] }, isPending: false });
        useUpdateAbsentNoticeStatusMutationMock.mockReturnValue({ mutate: vi.fn(), isPending: false });
    });

    it('renders the page title', () => {
        render(<IndexPage />);

        expect(screen.getByRole('heading', { name: 'Absent Notices' })).toBeInTheDocument();
    });

    it('configures search box with date range, workplace, and status filters', () => {
        render(<IndexPage />);

        const searchBox = screen.getByTestId('search-box');
        expect(searchBox).toBeInTheDocument();

        expect(commonSearchBoxSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                elements: expect.arrayContaining([
                    expect.objectContaining({ id: 'dates', type: 'date-range' }),
                    expect.objectContaining({ id: 'workplace_id', type: 'select' }),
                    expect.objectContaining({ id: 'status', type: 'select' }),
                ]),
            }),
        );
    });

    it('populates workplace dropdown with accessible workplaces', () => {
        render(<IndexPage />);

        const searchBox = screen.getByTestId('search-box');
        expect(searchBox).toHaveTextContent('Warehouse A');
        expect(searchBox).toHaveTextContent('Warehouse B');
    });

    it('renders all table column headers via the data table config', () => {
        render(<IndexPage />);

        const columnKeys = dataTableSpy.mock.calls[0][0].columns.map((c: any) => c.key);
        expect(columnKeys).toEqual(
            expect.arrayContaining([
                'shift_date',
                'workplace_name',
                'shift_time',
                'user_name',
                'position_name',
                'requested_at',
                'status',
                'processed_by_name',
                'processed_at',
            ]),
        );
    });

    it('renders a row with shift date and user name', () => {
        render(<IndexPage />);

        expect(screen.getByText('FORMATTED(2026-06-27)')).toBeInTheDocument();
        expect(screen.getByText('YAMADA TARO')).toBeInTheDocument();
    });

    it('shift time links to the shift detail page', () => {
        render(<IndexPage />);

        const link = screen.getByRole('link', { name: '09:00 - 18:00' });
        expect(link).toHaveAttribute('href', '/manage/shift/10');
    });

    it('shows dash for processed_by_name and processed_at when null', () => {
        render(<IndexPage />);

        const cells = screen.getAllByText('-');
        expect(cells.length).toBeGreaterThanOrEqual(2);
    });

    it('renders a status dropdown when user has SHIFT_EDIT permission', () => {
        usePermissionsMock.mockReturnValue({ SHIFT_LIST: true, SHIFT_EDIT: true });

        render(<IndexPage />);

        const select = screen.getByRole('combobox');
        expect(select).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Rejected' })).toBeInTheDocument();
    });

    it('renders a badge instead of the dropdown when user lacks SHIFT_EDIT permission', () => {
        usePermissionsMock.mockReturnValue({ SHIFT_LIST: true, SHIFT_EDIT: false });

        render(<IndexPage />);

        expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
        expect(screen.getByText('pending')).toBeInTheDocument();
    });

    it('passes noPermission true to data table when user lacks SHIFT_LIST', () => {
        usePermissionsMock.mockReturnValue({ SHIFT_LIST: false, SHIFT_EDIT: false });
        useGetAbsentNoticesQueryMock.mockReturnValue({ data: null, isPending: false });

        render(<IndexPage />);

        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ noPermission: true }));
    });

    it('passes noPermission false to data table when user has SHIFT_LIST', () => {
        render(<IndexPage />);

        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ noPermission: false }));
    });

    it('calls useGetAbsentNoticesQuery with router query and permission flag', () => {
        routerMock.query = { status: 'pending' };

        render(<IndexPage />);

        expect(useGetAbsentNoticesQueryMock).toHaveBeenCalledWith({ status: 'pending' }, true);
    });

    it('marks the current status as selected in the dropdown', () => {
        useGetAbsentNoticesQueryMock.mockReturnValue({
            data: { data: [buildAbsentNotice({ status: 'processed' })] },
            isPending: false,
        });

        render(<IndexPage />);

        expect((screen.getByRole('combobox') as HTMLSelectElement).value).toBe('processed');
    });

    it('asks for confirmation before applying a status change', () => {
        const mutate = vi.fn();
        useUpdateAbsentNoticeStatusMutationMock.mockReturnValue({ mutate, isPending: false });

        render(<IndexPage />);
        fireEvent.change(screen.getByRole('combobox'), { target: { value: 'rejected' } });

        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();
        expect(mutate).not.toHaveBeenCalled();
    });

    it('sends the chosen status once the change is confirmed', () => {
        const mutate = vi.fn();
        useUpdateAbsentNoticeStatusMutationMock.mockReturnValue({ mutate, isPending: false });

        render(<IndexPage />);
        fireEvent.change(screen.getByRole('combobox'), { target: { value: 'processed' } });
        fireEvent.click(screen.getByText('do-confirm'));

        expect(mutate).toHaveBeenCalledWith({ id: 1, status: 'processed' }, expect.anything());
    });

    it('cancelling the confirmation leaves the status unchanged', () => {
        const mutate = vi.fn();
        useUpdateAbsentNoticeStatusMutationMock.mockReturnValue({ mutate, isPending: false });

        render(<IndexPage />);
        fireEvent.change(screen.getByRole('combobox'), { target: { value: 'rejected' } });
        fireEvent.click(screen.getByText('do-cancel'));

        expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();
        expect(mutate).not.toHaveBeenCalled();
    });

    it('warns in the confirmation that a started or checked-in shift is kept as is', () => {
        render(<IndexPage />);
        fireEvent.change(screen.getByRole('combobox'), { target: { value: 'processed' } });

        expect(screen.getByTestId('confirm-modal')).toHaveTextContent('use the absent flag on the shift screen');
    });

    it.each([
        [{ shift_outcome: 'shift_rejected', notified: true }, 'The employee was removed from the shift and notified.'],
        [
            { shift_outcome: 'shift_rejected', notified: false },
            'The employee was removed from the shift. No notification was sent because a manager assigned this shift.',
        ],
        [
            { shift_outcome: 'shift_kept_already_started', notified: false },
            'The request was closed. The shift had already started, so the employee stays on it - use the absent flag on the shift screen to record a no-show.',
        ],
        [
            { shift_outcome: 'shift_kept_checked_in', notified: false },
            'The request was closed. The employee has already checked in, so the shift and the check-in were kept - use the absent flag on the shift screen to record a no-show.',
        ],
        [{ shift_outcome: 'shift_unchanged', notified: true }, 'Status updated'],
    ])('reports what actually happened to the shift: %o', (result, expected) => {
        const mutate = vi.fn((_vars, options) => options.onSuccess({ data: null, ...result }));
        useUpdateAbsentNoticeStatusMutationMock.mockReturnValue({ mutate, isPending: false });

        render(<IndexPage />);
        fireEvent.change(screen.getByRole('combobox'), { target: { value: 'processed' } });
        fireEvent.click(screen.getByText('do-confirm'));

        expect(NotificationHelper.showSuccess).toHaveBeenCalledWith(expected);
    });

    it('explains the refusal when the employee is no longer on the shift', () => {
        const mutate = vi.fn((_vars, options) =>
            options.onError(new ApiError({ status: 409, message: 'ABSENT_NOTICE_SHIFT_NOT_APPROVED', data: {} })),
        );
        useUpdateAbsentNoticeStatusMutationMock.mockReturnValue({ mutate, isPending: false });

        render(<IndexPage />);
        fireEvent.change(screen.getByRole('combobox'), { target: { value: 'rejected' } });
        fireEvent.click(screen.getByText('do-confirm'));

        expect(NotificationHelper.showError).toHaveBeenCalledWith(
            'The employee is no longer on this shift, so the request cannot be refused.',
        );
    });

    it('falls back to the generic error message for any other failure', () => {
        const mutate = vi.fn((_vars, options) => options.onError(new Error('boom')));
        useUpdateAbsentNoticeStatusMutationMock.mockReturnValue({ mutate, isPending: false });

        render(<IndexPage />);
        fireEvent.change(screen.getByRole('combobox'), { target: { value: 'rejected' } });
        fireEvent.click(screen.getByText('do-confirm'));

        expect(NotificationHelper.showError).toHaveBeenCalledWith('Failed to update status');
    });

    it('shows processed_by_name when set', () => {
        useGetAbsentNoticesQueryMock.mockReturnValue({
            data: {
                data: [
                    buildAbsentNotice({
                        processed_by_name: 'Manager Tanaka',
                        processed_at: '2026-06-26T09:00:00+09:00',
                    }),
                ],
            },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByText('Manager Tanaka')).toBeInTheDocument();
        expect(screen.getByText('FORMATTED(2026-06-26T09:00:00+09:00)')).toBeInTheDocument();
    });
});
