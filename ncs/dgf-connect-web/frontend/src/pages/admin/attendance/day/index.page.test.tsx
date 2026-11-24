import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AdminAttendanceDayPage from '@/pages/admin/attendance/day/index.page';

const { dayMock, routerMock } = vi.hoisted(() => ({
    dayMock: vi.fn(),
    routerMock: { query: { user_id: '7', date: '2026-07-03' } as Record<string, unknown>, back: vi.fn() },
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

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, href }: any) => React.createElement('a', { href }, children),
    Alert: ({ children }: any) => React.createElement('div', { 'data-testid': 'alert' }, children),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', { 'data-testid': 'badge' }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children }: any) => React.createElement('button', null, children),
    Card: ({ children }: any) => React.createElement('section', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Table: Object.assign(({ children }: any) => React.createElement('table', null, children), {
        Thead: ({ children }: any) => React.createElement('thead', null, children),
        Tbody: ({ children }: any) => React.createElement('tbody', null, children),
        Tr: ({ children }: any) => React.createElement('tr', null, children),
        Th: ({ children }: any) => React.createElement('th', null, children),
        Td: ({ children }: any) => React.createElement('td', null, children),
    }),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@/pages/admin/attendance/api', () => ({
    useGetAttendanceDayQuery: (...args: unknown[]) => dayMock(...args),
}));

vi.mock('@/components/TableEmptyState', () => ({
    default: () => React.createElement('div', null, 'No data'),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (v: string) => v ?? '-' },
    DATE_STORE_FORMAT_WITH_TIME: 'YYYY-MM-DD HH:mm:ss',
}));

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: (_route: string, params: any) => `/workplace/${params?.id ?? ''}` },
}));

vi.mock('@/configs/route.config', () => ({
    default: { HOME: { path: '/' }, ADMIN_ATTENDANCE_LIST: { path: '/admin/attendance' } },
}));

const makeSession = (overrides: Record<string, any> = {}) => ({
    id: 12,
    user_id: 7,
    workplace_id: 1,
    work_date: '2026-07-03',
    first_check_in_at: '2026-07-03 09:00:00',
    last_check_out_at: '2026-07-03 11:00:00',
    work_minutes: 120,
    work_hours: '2.00',
    has_missing_checkout: false,
    invalidated_reason: null,
    user: { id: 7, name: 'Admin A', staff_no: '1001' },
    workplace: { id: 1, name: 'WP A' },
    intervals: [
        { id: 1, session_id: 12, started_at: '2026-07-03 09:00:00', ended_at: '2026-07-03 11:00:00', minutes: 120 },
    ],
    events: [
        { id: 1, type: 'check_in', event_time: '2026-07-03 09:00:00', coordinate: '35.0,139.0' },
        { id: 2, type: 'check_out', event_time: '2026-07-03 11:00:00', coordinate: '35.0,139.0' },
    ],
    ...overrides,
});

describe('Admin attendance day detail page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        dayMock.mockReturnValue({ data: { data: [makeSession()] }, isLoading: false });
    });

    it('renders each workplace session with its event log', () => {
        render(<AdminAttendanceDayPage />);

        expect(screen.getByRole('link', { name: 'WP A' })).toHaveAttribute('href', '/workplace/1');
        // check-in + check-out event rows
        expect(screen.getAllByText('Check-in').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Check-out').length).toBeGreaterThan(0);
    });

    it('renders the list of counted intervals for a closed session', () => {
        render(<AdminAttendanceDayPage />);

        expect(screen.getByText('Counted intervals')).toBeInTheDocument();
    });

    it('shows a missing-checkout warning when a session has an open, unresolved check-in', () => {
        dayMock.mockReturnValue({
            data: {
                data: [
                    makeSession({
                        last_check_out_at: null,
                        work_minutes: null,
                        work_hours: '',
                        has_missing_checkout: true,
                        intervals: [],
                        events: [
                            { id: 1, type: 'check_in', event_time: '2026-07-03 09:00:00', coordinate: '35.0,139.0' },
                        ],
                    }),
                ],
            },
            isLoading: false,
        });

        render(<AdminAttendanceDayPage />);

        expect(screen.getByTestId('alert')).toBeInTheDocument();
        const badges = screen.getAllByTestId('badge');
        expect(badges.some((badge) => badge.textContent === 'Missing check-out')).toBe(true);
    });

    it('shows an in-progress badge (no warning) for a still-open session that is the current focus', () => {
        dayMock.mockReturnValue({
            data: {
                data: [
                    makeSession({
                        last_check_out_at: null,
                        work_minutes: null,
                        work_hours: '',
                        has_missing_checkout: false,
                        intervals: [],
                        events: [
                            { id: 1, type: 'check_in', event_time: '2026-07-03 09:00:00', coordinate: '35.0,139.0' },
                        ],
                    }),
                ],
            },
            isLoading: false,
        });

        render(<AdminAttendanceDayPage />);

        expect(screen.queryByTestId('alert')).not.toBeInTheDocument();
        const badges = screen.getAllByTestId('badge');
        expect(badges.some((badge) => badge.textContent === 'Still checked in')).toBe(true);
    });

    it('shows the invalidated badge and explanatory note instead of the missing-checkout warning', () => {
        dayMock.mockReturnValue({
            data: {
                data: [
                    makeSession({
                        last_check_out_at: null,
                        work_minutes: null,
                        work_hours: '',
                        has_missing_checkout: true,
                        invalidated_reason: 'late_checkout',
                        intervals: [],
                        events: [
                            { id: 1, type: 'check_in', event_time: '2026-07-02 21:00:00', coordinate: '35.0,139.0' },
                        ],
                    }),
                ],
            },
            isLoading: false,
        });

        render(<AdminAttendanceDayPage />);

        const badges = screen.getAllByTestId('badge');
        expect(badges.some((badge) => badge.textContent === 'Passed deadline')).toBe(true);
        expect(badges.some((badge) => badge.textContent === 'Missing check-out')).toBe(false);
        expect(screen.getByTestId('alert')).toHaveTextContent(
            'This check-in passed its check-out deadline, so its hours were not counted. The log below is retained for reference.',
        );
    });

    it('clarifies that already-credited hours remain valid when an invalidated session still has counted intervals', () => {
        dayMock.mockReturnValue({
            data: {
                data: [
                    makeSession({
                        last_check_out_at: null,
                        has_missing_checkout: true,
                        invalidated_reason: 'late_checkout',
                        events: [
                            { id: 1, type: 'check_in', event_time: '2026-07-03 09:00:00', coordinate: '35.0,139.0' },
                            { id: 2, type: 'check_out', event_time: '2026-07-03 11:00:00', coordinate: '35.0,139.0' },
                            { id: 3, type: 'check_in', event_time: '2026-07-03 22:00:00', coordinate: '35.0,139.0' },
                        ],
                    }),
                ],
            },
            isLoading: false,
        });

        const { container } = render(<AdminAttendanceDayPage />);

        // The label and the value sit in separate nodes, so match on the summary line as a whole.
        const summaryLines = Array.from(container.querySelectorAll('span')).map((line) => line.textContent);
        expect(summaryLines).toContain('Total hours: 2.00');
        expect(screen.getByTestId('alert')).toHaveTextContent(
            "This session's last check-in passed its check-out deadline, so only the time since that check-in was not counted — the hours shown above are still valid. The log below is retained for reference.",
        );
    });

    it('shows an audit-only note when a check-out was recorded but credited zero minutes', () => {
        dayMock.mockReturnValue({
            data: {
                data: [
                    makeSession({
                        work_minutes: null,
                        work_hours: '',
                        has_missing_checkout: false,
                        intervals: [],
                        events: [
                            { id: 1, type: 'check_in', event_time: '2026-07-03 09:00:00', coordinate: '35.0,139.0' },
                            { id: 2, type: 'check_out', event_time: '2026-07-03 18:00:00', coordinate: '35.0,139.0' },
                        ],
                    }),
                ],
            },
            isLoading: false,
        });

        render(<AdminAttendanceDayPage />);

        expect(screen.getByTestId('alert')).toBeInTheDocument();
        expect(screen.queryByText('Counted intervals')).not.toBeInTheDocument();
    });
});
