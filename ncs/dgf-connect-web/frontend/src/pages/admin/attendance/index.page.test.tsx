import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ATTENDANCE_ADMIN_PERMISSIONS } from '@/pages/admin/attendance/constants';
import AdminAttendanceIndexPage from '@/pages/admin/attendance/index.page';

const { dailyMock, routerMock, pushMock } = vi.hoisted(() => ({
    dailyMock: vi.fn(),
    routerMock: { query: {} as Record<string, unknown>, isReady: true },
    pushMock: vi.fn(),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: any) => React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => ({ ...routerMock, push: pushMock }),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick }: any) =>
        React.createElement('button', { 'onClick': onClick, 'data-testid': 'action-icon' }, children),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', { 'data-testid': 'badge' }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick }: any) =>
        React.createElement('button', { 'onClick': onClick, 'data-testid': 'export-button' }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@mantine/hooks', async () => {
    const react = await import('react');
    return {
        useDisclosure: (initial: boolean) => {
            const [opened, setOpened] = react.useState(initial);
            return [opened, { open: () => setOpened(true), close: () => setOpened(false) }];
        },
    };
});

vi.mock('@/pages/admin/attendance/partials/export_modal', () => ({
    default: ({ opened, defaultUserIds }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'export-modal' },
                  `users:${(defaultUserIds ?? []).join(',') || 'all'}`,
              )
            : null,
}));

vi.mock('@/pages/admin/attendance/api', () => ({
    useGetAttendanceDailyQuery: (...args: unknown[]) => dailyMock(...args),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) =>
        React.createElement(
            'div',
            { 'data-testid': 'search-box' },
            props.elements.map((el: any) =>
                React.createElement(
                    'span',
                    {
                        'key': el.id,
                        'data-testid': `filter-${el.id}`,
                        'data-params': JSON.stringify(el.extraParams ?? null),
                    },
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
            null,
            React.createElement(
                'tbody',
                null,
                records.map((record: any, index: number) =>
                    React.createElement(
                        'tr',
                        { key: index },
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
    default: ({ user }: any) => React.createElement('span', null, `${user?.name} (${user?.staff_no})`),
}));

const makeMockDate = (dateStr: string) => ({
    toDate: () => new Date(dateStr),
    format: () => dateStr,
});

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parseAndFormat: (v: string) => v ?? '-',
        now: () => ({
            ...makeMockDate('2026-07-03'),
            subtract: () => makeMockDate('2026-06-26'),
        }),
    },
}));

vi.mock('@/configs/api.config', () => ({
    ApiConfig: { ADMIN_MASTER_USER_SEARCH: '/api/users/search' },
}));

vi.mock('@/configs/route.config', () => ({
    default: { HOME: { path: '/' }, ADMIN_ATTENDANCE_DAY_DETAIL: { path: '/admin/attendance/day' } },
}));

const makePaginated = (items: any[]) => ({
    data: { data: items, current_page: 1, last_page: 1, per_page: 15, total: items.length },
});

const makeRow = (overrides: Record<string, any> = {}) => ({
    user_id: 7,
    work_date: '2026-07-03',
    user: { id: 7, name: 'Admin A', staff_no: '1001' },
    workplace_count: 3,
    total_hours: '6.50',
    has_missing_checkout: false,
    has_invalidated_session: false,
    ...overrides,
});

describe('Admin attendance daily page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = {};
        routerMock.isReady = true;
        dailyMock.mockReturnValue({ data: makePaginated([makeRow()]), isLoading: false });
    });

    it('renders the date range and admin filters', () => {
        render(<AdminAttendanceIndexPage />);

        expect(screen.getByTestId('filter-dates')).toBeInTheDocument();
        expect(screen.getByTestId('filter-user_id')).toBeInTheDocument();
    });

    it('limits the admin filter to users with the attendance admin permissions', () => {
        render(<AdminAttendanceIndexPage />);

        // Asserted against the shared constant, not a copy of its values: the point
        // here is that the filter is wired to the same list the export picker uses.
        // `export_modal.test.tsx` is what pins the permission names themselves.
        expect(screen.getByTestId('filter-user_id')).toHaveAttribute(
            'data-params',
            JSON.stringify({ permission: ATTENDANCE_ADMIN_PERMISSIONS }),
        );
    });

    it('defaults to the last 7 days when the URL has no date filter yet', () => {
        render(<AdminAttendanceIndexPage />);

        expect(dailyMock).toHaveBeenCalledWith({ dates: ['2026-06-26', '2026-07-03'] }, true);
    });

    it('does not override an explicit date filter already present in the URL', () => {
        routerMock.query = { dates: ['2026-01-01', '2026-01-31'] };

        render(<AdminAttendanceIndexPage />);

        expect(dailyMock).toHaveBeenCalledWith({ dates: ['2026-01-01', '2026-01-31'] }, true);
    });

    it('does not enable the query until the router is ready, so a not-yet-hydrated empty query cannot override URL filters', () => {
        routerMock.isReady = false;

        render(<AdminAttendanceIndexPage />);

        expect(dailyMock).toHaveBeenCalledWith({ dates: ['2026-06-26', '2026-07-03'] }, false);
    });

    it('renders one row per admin per day with workplace count and total hours', () => {
        render(<AdminAttendanceIndexPage />);

        expect(screen.getByText('Admin A (1001)')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
        expect(screen.getByText('6.50')).toBeInTheDocument();
    });

    it('navigates to the day detail page when the detail action is clicked', () => {
        render(<AdminAttendanceIndexPage />);

        fireEvent.click(screen.getByTestId('action-icon'));
        expect(pushMock).toHaveBeenCalledWith({
            pathname: '/admin/attendance/day',
            query: { user_id: 7, date: '2026-07-03' },
        });
    });

    it('opens the export modal from the export button, closed by default', () => {
        render(<AdminAttendanceIndexPage />);

        expect(screen.queryByTestId('export-modal')).not.toBeInTheDocument();

        fireEvent.click(screen.getByTestId('export-button'));

        expect(screen.getByTestId('export-modal')).toBeInTheDocument();
    });

    it('opens the export modal on the admin already selected in the page filter', () => {
        routerMock.query = { user_id: '7' };

        render(<AdminAttendanceIndexPage />);
        fireEvent.click(screen.getByTestId('export-button'));

        expect(screen.getByTestId('export-modal')).toHaveTextContent('users:7');
    });

    it('shows a warning badge when a day has a session without check-out', () => {
        dailyMock.mockReturnValue({
            data: makePaginated([makeRow({ has_missing_checkout: true })]),
            isLoading: false,
        });

        render(<AdminAttendanceIndexPage />);

        expect(screen.getByTestId('badge')).toHaveTextContent('Missing check-out');
    });

    it('shows the invalidated badge when a day has an invalidated session as its only anomaly', () => {
        dailyMock.mockReturnValue({
            data: makePaginated([makeRow({ has_invalidated_session: true })]),
            isLoading: false,
        });

        render(<AdminAttendanceIndexPage />);

        expect(screen.getByTestId('badge')).toHaveTextContent('Passed deadline');
    });

    it('shows both badges independently when a day has one of each anomaly', () => {
        dailyMock.mockReturnValue({
            data: makePaginated([makeRow({ has_missing_checkout: true, has_invalidated_session: true })]),
            isLoading: false,
        });

        render(<AdminAttendanceIndexPage />);

        const badges = screen.getAllByTestId('badge');
        expect(badges.map((badge) => badge.textContent)).toEqual(
            expect.arrayContaining(['Missing check-out', 'Passed deadline']),
        );
    });
});
