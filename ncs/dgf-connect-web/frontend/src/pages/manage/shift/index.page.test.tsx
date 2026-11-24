import React from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/manage/shift/index.page';

const { commonSearchBoxSpy, dataTableSpy, useGetShiftsQueryMock, usePermissionsMock, routerMock, getStatusMock } =
    vi.hoisted(() => ({
        commonSearchBoxSpy: vi.fn(),
        dataTableSpy: vi.fn(),
        useGetShiftsQueryMock: vi.fn(),
        usePermissionsMock: vi.fn(),
        routerMock: { query: {} as Record<string, unknown> },
        getStatusMock: vi.fn(),
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

            return Object.entries(options).reduce(
                (result, [placeholder, value]) => result.replace(`{{${placeholder}}}`, String(value)),
                key,
            );
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick }: any) => React.createElement('button', { type: 'button', onClick }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (initial = false) => {
        const [opened, setOpened] = React.useState(initial);
        return [opened, { open: () => setOpened(true), close: () => setOpened(false) }];
    },
}));

vi.mock('jotai', () => ({
    atom: (initial: unknown) => ({ init: initial }),
    useAtom: () => [{ workplaces: [{ id: 1, name: 'Tokyo' }], workplace_access: {} }],
}));

vi.mock('@/helpers/shift.helper', () => ({
    default: {
        getStatus: (...args: unknown[]) => getStatusMock(...args),
    },
}));

vi.mock('@/helpers/date.helper', () => ({
    DATE_STORE_FORMAT: 'YYYY-MM-DD',
    default: {
        now: () => ({ format: () => '2026-06-22', toDate: () => new Date('2026-06-22T00:00:00Z') }),
        formatMinuteToHour: (minute: number) => `FORMATTED_MINUTE_${minute}`,
    },
}));

vi.mock('@/helpers/workplace.helper', () => ({
    default: {
        filterWorkplacesByAccess: (workplaces: any[]) => workplaces,
    },
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/manage/shift/api', () => ({
    useGetShiftsQuery: (...args: unknown[]) => useGetShiftsQueryMock(...args),
}));

vi.mock('@/pages/manage/shift/partials/shift_create_modal', () => ({
    default: ({ isOpened }: any) => (isOpened ? React.createElement('div', { 'data-testid': 'create-modal' }) : null),
}));

vi.mock('@/pages/manage/shift/partials/import_order_modal', () => ({
    default: ({ isShow }: any) => (isShow ? React.createElement('div', { 'data-testid': 'import-order-modal' }) : null),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) => {
        commonSearchBoxSpy(props);

        return React.createElement(
            'div',
            { 'data-testid': 'shift-search-box' },
            props.elements.map((element: any) =>
                React.createElement('div', { key: element.id }, React.createElement('span', null, element.label)),
            ),
        );
    },
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        dataTableSpy(props);

        const records = Array.isArray(props.data) ? props.data : props.data.data;

        return React.createElement(
            'table',
            { 'data-no-permission': props.noPermission ? 'true' : 'false' },
            React.createElement(
                'tbody',
                null,
                records.map((record: any) =>
                    React.createElement(
                        'tr',
                        { key: record.id },
                        props.columns.map((column: any) =>
                            React.createElement(
                                'td',
                                { key: column.key },
                                column.render ? column.render(record) : record[column.key],
                            ),
                        ),
                    ),
                ),
            ),
        );
    },
}));

const buildShift = (overrides: Record<string, unknown> = {}) => ({
    id: 1,
    workplace: { name: 'Tokyo' },
    workplace_shift: { start_time: '09:00', end_time: '17:00' },
    fixed_count: 2,
    fixed_total: 3,
    fixed_pending_count: 1,
    temp_count: 1,
    temp_total: 2,
    temp_pending_count: 0,
    fixed_confirmed: 1,
    temp_confirmed: 0,
    fixed_check_in: 1,
    temp_check_in: 0,
    break_minutes: 90,
    ...overrides,
});

describe('Shift index page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = {};

        usePermissionsMock.mockReturnValue({
            SHIFT_LIST: true,
            SHIFT_CREATE: true,
            SHIFT_EDIT: true,
            SHIFT_EXPORT: true,
            SHIFT_IMPORT: true,
            SHIFT_IMPORT_ORDER: true,
        });
        getStatusMock.mockReturnValue('in_progress');
        useGetShiftsQueryMock.mockReturnValue({ data: { data: [buildShift()] }, isLoading: false });
    });

    it('configures the search box with date, workplace and status filters', () => {
        render(<IndexPage />);

        const searchBox = screen.getByTestId('shift-search-box');
        expect(within(searchBox).getByText('Date')).toBeInTheDocument();
        expect(within(searchBox).getByText('Workplace')).toBeInTheDocument();
        expect(within(searchBox).getByText('Status')).toBeInTheDocument();

        expect(commonSearchBoxSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                elements: expect.arrayContaining([
                    expect.objectContaining({ id: 'date', name: 'date' }),
                    expect.objectContaining({ id: 'workplace_id', name: 'workplace_id' }),
                    expect.objectContaining({ id: 'status', name: 'status' }),
                ]),
            }),
        );
    });

    it('opens the shift order import modal from the list header', async () => {
        render(<IndexPage />);

        expect(screen.queryByTestId('import-order-modal')).not.toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Import shift order' }));

        expect(screen.getByTestId('import-order-modal')).toBeInTheDocument();
    });

    it('hides the shift order import button without the permission', () => {
        usePermissionsMock.mockReturnValue({
            SHIFT_LIST: true,
            SHIFT_CREATE: true,
            SHIFT_EDIT: true,
            SHIFT_EXPORT: true,
            SHIFT_IMPORT: true,
            SHIFT_IMPORT_ORDER: false,
        });

        render(<IndexPage />);

        expect(screen.queryByRole('button', { name: 'Import shift order' })).not.toBeInTheDocument();
    });

    it('renders a shift row linking to its detail page', () => {
        render(<IndexPage />);

        expect(screen.getByRole('link', { name: '1' })).toHaveAttribute('href', '/manage/shift/1');
        expect(screen.getByText('09:00 ~ 17:00')).toBeInTheDocument();
        expect(screen.getByText('FORMATTED_MINUTE_90')).toBeInTheDocument();
    });

    it('renders a dash when there is no break time', () => {
        useGetShiftsQueryMock.mockReturnValue({
            data: { data: [buildShift({ break_minutes: null })] },
            isLoading: false,
        });

        render(<IndexPage />);

        expect(screen.getByText('-')).toBeInTheDocument();
    });

    it('filters rows by the status query param', () => {
        routerMock.query = { status: 'ended' };
        getStatusMock.mockImplementation((shift: any) => (shift.id === 2 ? 'ended' : 'in_progress'));
        useGetShiftsQueryMock.mockReturnValue({
            data: { data: [buildShift({ id: 1 }), buildShift({ id: 2 })] },
            isLoading: false,
        });

        render(<IndexPage />);

        // Only the shift whose status === 'ended' (id 2) should remain.
        expect(screen.getByRole('link', { name: '2' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: '1' })).not.toBeInTheDocument();
    });

    it('hides the create button without SHIFT_CREATE permission', () => {
        usePermissionsMock.mockReturnValue({ SHIFT_LIST: true, SHIFT_CREATE: false });

        render(<IndexPage />);

        expect(screen.queryByRole('button', { name: 'Create shift' })).not.toBeInTheDocument();
    });

    it('opens the create modal from the create button', async () => {
        const user = userEvent.setup();

        render(<IndexPage />);

        expect(screen.queryByTestId('create-modal')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Create shift' }));
        expect(screen.getByTestId('create-modal')).toBeInTheDocument();
    });

    it('passes the missing permission flag to the data table', () => {
        usePermissionsMock.mockReturnValue({ SHIFT_LIST: false, SHIFT_CREATE: false });

        render(<IndexPage />);

        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ noPermission: true }));
    });
});
