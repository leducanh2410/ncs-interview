import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import dayjs from 'dayjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import IndexPage from '@/pages/manage/shift/weekly-hours/index.page';

const {
    useGetWeeklyWorkHoursQueryMock,
    exportWeeklyWorkHoursMock,
    usePermissionsMock,
    commonSearchBoxSpy,
    userWithAvatarSpy,
    showErrorMock,
    routerState,
} = vi.hoisted(() => ({
    useGetWeeklyWorkHoursQueryMock: vi.fn(),
    exportWeeklyWorkHoursMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    commonSearchBoxSpy: vi.fn(),
    userWithAvatarSpy: vi.fn(),
    showErrorMock: vi.fn(),
    routerState: { query: {} as Record<string, unknown>, isReady: true },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => ({ query: routerState.query, push: vi.fn(), isReady: routerState.isReady }),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (key === 'FORMAT_DATE_SHORT') return 'M/D';
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { onClick, disabled, 'data-loading': String(Boolean(loading)) }, children),
    Divider: () => React.createElement('hr', null),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Progress: ({ value, color }: any) =>
        React.createElement('div', { 'data-testid': 'progress', 'data-value': value, 'data-color': color }),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
    Tooltip: ({ children, label }: any) =>
        React.createElement('div', null, children, React.createElement('div', { 'data-testid': 'tooltip' }, label)),
}));

vi.mock('jotai', () => ({
    useAtom: () => [{ workplaces: [{ id: 1, name: 'Tokyo' }], workplace_access: {} }],
}));

vi.mock('@/pages/global.state', () => ({ masterAtom: {} }));

vi.mock('@/helpers/workplace.helper', () => ({
    default: { filterWorkplacesByAccess: (workplaces: unknown[]) => workplaces },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        getText: (_enum: unknown, value: string) => `enum:${value}`,
        getColor: (enumObj: Record<string, { value: string; color?: string }>, value: string) =>
            Object.values(enumObj).find((item) => item.value === value)?.color,
        toSelectArray: (enumObj: Record<string, { value: string; text: string }>) =>
            Object.values(enumObj).map((item) => ({ value: item.value, label: item.text })),
    },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showError: (...args: unknown[]) => showErrorMock(...args), showSuccess: vi.fn() },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        now: () => dayjs('2026-09-15T10:00:00'),
        parse: (value: string) => dayjs(value),
    },
    DATE_STORE_FORMAT: 'YYYY-MM-DD',
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) => {
        commonSearchBoxSpy(props);
        return React.createElement('div', { 'data-testid': 'search-box' });
    },
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: (props: any) => {
        userWithAvatarSpy(props);
        return React.createElement('span', { 'data-testid': `avatar-${props.user.id}` }, props.user.name);
    },
}));

vi.mock('@/components/DDataTable', () => ({
    headerWrapClassName: 'header-wrap',
    default: ({ noPermission, columns, data, pinFirstColumn }: any) =>
        React.createElement(
            'div',
            {
                'data-testid': 'table',
                'data-no-permission': String(noPermission),
                'data-pin-first-column': String(Boolean(pinFirstColumn)),
            },
            (columns ?? []).map((column: { key: string; name: string }) =>
                React.createElement('span', { 'key': column.key, 'data-testid': `header-${column.key}` }, column.name),
            ),
            (data?.data ?? []).map((record: any) =>
                React.createElement(
                    'div',
                    { 'key': record.user.id, 'data-testid': `row-${record.user.id}` },
                    (columns ?? []).map((column: { key: string; render?: (row: any) => React.ReactNode }) =>
                        React.createElement(
                            'div',
                            { 'key': column.key, 'data-testid': `cell-${record.user.id}-${column.key}` },
                            column.render?.(record),
                        ),
                    ),
                ),
            ),
        ),
}));

vi.mock('@/pages/manage/shift/weekly-hours/api', () => ({
    useGetWeeklyWorkHoursQuery: (...args: unknown[]) => useGetWeeklyWorkHoursQueryMock(...args),
    exportWeeklyWorkHours: (...args: unknown[]) => exportWeeklyWorkHoursMock(...args),
}));

const buildDays = (start: string, hours: number[]) =>
    hours.map((value, index) => ({
        date: dayjs(start).add(index, 'day').format('YYYY-MM-DD'),
        hours: value,
        actual_hours: 0,
        scheduled_hours: value,
    }));

const buildSummary = (overrides: Record<string, unknown> = {}) => ({
    start: '2026-08-31',
    end: '2026-09-06',
    total_hours: 27,
    actual_hours: 9,
    scheduled_hours: 27,
    max_hour_per_week: 28,
    is_custom_limit: false,
    status: 'near',
    ...overrides,
});

const row = {
    user: { id: 7, name: 'Nguyen An', staff_no: '1001', profile_picture: null },
    residence_status: 'study_abroad',
    days: buildDays('2026-08-31', [9, 9, 9, 9, 0, 0, 0, 0]),
    weeks: [
        buildSummary(),
        buildSummary({
            start: '2026-09-01',
            end: '2026-09-07',
            total_hours: 36,
            actual_hours: 0,
            max_hour_per_week: 40,
            is_custom_limit: true,
            status: 'near',
        }),
    ],
    status: 'over',
};

const mockResponse = () =>
    useGetWeeklyWorkHoursQueryMock.mockReturnValue({
        isPending: false,
        data: {
            data: {
                weeks: [
                    { start: '2026-08-31', end: '2026-09-06' },
                    { start: '2026-09-01', end: '2026-09-07' },
                ],
                data: [row],
                current_page: 1,
                last_page: 1,
                per_page: 20,
                total: 1,
            },
        },
    });

describe('Weekly work hours page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerState.query = {};
        routerState.isReady = true;
        usePermissionsMock.mockReturnValue({ SHIFT_LIST: true, SHIFT_EXPORT: true, USER_LIST: true });
        exportWeeklyWorkHoursMock.mockResolvedValue(undefined);
        mockResponse();
    });

    it('queries the last 10 days up to today by default until the user searches', () => {
        render(<IndexPage />);

        expect(useGetWeeklyWorkHoursQueryMock).toHaveBeenCalledWith({ dates: ['2026-09-06', '2026-09-15'] }, true);
        const { initialValues } = commonSearchBoxSpy.mock.calls[0][0];
        expect(initialValues.dates.map((date: Date) => dayjs(date).format('YYYY-MM-DD'))).toEqual([
            '2026-09-06',
            '2026-09-15',
        ]);
    });

    it('keeps the filters from the URL once the user has searched', () => {
        routerState.query = { dates: ['2026-08-01', '2026-08-31'], staff_no: '1001' };

        render(<IndexPage />);

        expect(useGetWeeklyWorkHoursQueryMock).toHaveBeenCalledWith(
            { dates: ['2026-08-01', '2026-08-31'], staff_no: '1001' },
            true,
        );
    });

    it('does not query without the shift list permission and flags the table', () => {
        usePermissionsMock.mockReturnValue({ SHIFT_LIST: false, SHIFT_EXPORT: false, USER_LIST: false });

        render(<IndexPage />);

        expect(useGetWeeklyWorkHoursQueryMock).toHaveBeenCalledWith(expect.anything(), false);
        expect(screen.getByTestId('table')).toHaveAttribute('data-no-permission', 'true');
    });

    it('offers date range, employee ID, residence status, workplace and work-hour status filters', () => {
        render(<IndexPage />);

        const { elements } = commonSearchBoxSpy.mock.calls[0][0];
        expect(elements.map((element: { name: string }) => element.name)).toEqual([
            'dates',
            'staff_no',
            'residence_statuses',
            'workplace_id',
            'load_status',
        ]);
        expect(elements[0].type).toBe('date-range');
        expect(elements[2].isMulti).toBe(true);
        expect(elements[4].options.map((option: { value: string }) => option.value)).toEqual([
            'normal',
            'near',
            'over',
        ]);
        expect(elements[4].showAll).toBe(true);
    });

    it('renders one column per 7-day window returned by the API, labelled by its range', () => {
        render(<IndexPage />);

        expect(screen.getByTestId('header-week_0')).toHaveTextContent('8/31 (Mo) ~ 9/6 (Su)');
        expect(screen.getByTestId('header-week_1')).toHaveTextContent('9/1 (Tu) ~ 9/7 (Mo)');
        expect(screen.queryByTestId('header-week_2')).not.toBeInTheDocument();
    });

    it('shows only the employee, residence and window columns, with the first column pinned', () => {
        render(<IndexPage />);

        expect(screen.queryByTestId('header-status')).not.toBeInTheDocument();
        expect(screen.queryByTestId('header-peak_window')).not.toBeInTheDocument();
        expect(screen.getByTestId('table')).toHaveAttribute('data-pin-first-column', 'true');
    });

    it('exports the employees matching the current filters', async () => {
        routerState.query = { dates: ['2026-08-01', '2026-08-31'], load_status: 'over', page: '2' };

        render(<IndexPage />);
        fireEvent.click(screen.getByText('Export excel'));

        await waitFor(() =>
            expect(exportWeeklyWorkHoursMock).toHaveBeenCalledWith({
                dates: ['2026-08-01', '2026-08-31'],
                load_status: 'over',
                page: '2',
            }),
        );
        expect(showErrorMock).not.toHaveBeenCalled();
    });

    it('exports the default 10-day range before the user searches', async () => {
        render(<IndexPage />);
        fireEvent.click(screen.getByText('Export excel'));

        await waitFor(() =>
            expect(exportWeeklyWorkHoursMock).toHaveBeenCalledWith({ dates: ['2026-09-06', '2026-09-15'] }),
        );
    });

    it('reports a failed export', async () => {
        exportWeeklyWorkHoursMock.mockRejectedValue(new Error('boom'));

        render(<IndexPage />);
        fireEvent.click(screen.getByText('Export excel'));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('Could not export'));
        expect(screen.getByText('Export excel').closest('button')).toHaveAttribute('data-loading', 'false');
    });

    it('disables the export button without the shift export permission', () => {
        usePermissionsMock.mockReturnValue({ SHIFT_LIST: true, SHIFT_EXPORT: false, USER_LIST: true });

        render(<IndexPage />);

        expect(screen.getByText('Export excel').closest('button')).toBeDisabled();
    });

    it('tells the user when the selected period is longer than the backend allows', () => {
        useGetWeeklyWorkHoursQueryMock.mockReturnValue({
            isPending: false,
            isError: true,
            error: new ApiError({ status: 422, message: 'E_VALIDATION', data: { dates: ['too long'] } }),
            data: undefined,
        });

        render(<IndexPage />);

        expect(showErrorMock).toHaveBeenCalledWith('The date range can cover at most 35 days');
    });

    it('reports other loading failures generically', () => {
        useGetWeeklyWorkHoursQueryMock.mockReturnValue({
            isPending: false,
            isError: true,
            error: new Error('network'),
            data: undefined,
        });

        render(<IndexPage />);

        expect(showErrorMock).toHaveBeenCalledWith('Could not load weekly work hours');
    });

    it('gives each window cell the days of its own 7-day slice', () => {
        render(<IndexPage />);

        const firstWindow = within(screen.getByTestId('cell-7-week_0'));
        const secondWindow = within(screen.getByTestId('cell-7-week_1'));
        expect(firstWindow.queryAllByText('8/31', { exact: false }).length).toBeGreaterThan(0);
        expect(firstWindow.queryAllByText('9/7', { exact: false })).toHaveLength(0);
        expect(secondWindow.queryAllByText('9/7', { exact: false }).length).toBeGreaterThan(0);
        expect(secondWindow.queryAllByText('8/31', { exact: false })).toHaveLength(0);
    });

    it('disables the export button when there is nothing to export', () => {
        useGetWeeklyWorkHoursQueryMock.mockReturnValue({
            isPending: false,
            data: { data: { weeks: [], data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 } },
        });

        render(<IndexPage />);

        expect(screen.getByText('Export excel').closest('button')).toBeDisabled();
    });

    it('shows each week total against its limit and marks employee-specific limits', () => {
        render(<IndexPage />);

        expect(within(screen.getByTestId('cell-7-week_0')).getByText('27h / 28h')).toBeInTheDocument();
        expect(within(screen.getByTestId('cell-7-week_1')).getByText('36h / 40h*')).toBeInTheDocument();
        expect(within(screen.getByTestId('cell-7-week_1')).getAllByText('9/1 (Tu) ~ 9/7 (Mo)').length).toBeGreaterThan(
            0,
        );
        expect(within(screen.getByTestId('cell-7-week_1')).getByText('Employee config', { exact: false })).toBeTruthy();
    });

    it('renders the employee with a clickable avatar and name, and the residence status', () => {
        render(<IndexPage />);

        expect(userWithAvatarSpy).toHaveBeenCalledWith(
            expect.objectContaining({ user: row.user, isLink: true, isAvatarLink: true }),
        );
        expect(screen.getByTestId('cell-7-residence_status')).toHaveTextContent('enum:study_abroad');
    });

    it('disables the employee link without the user list permission', () => {
        usePermissionsMock.mockReturnValue({ SHIFT_LIST: true, SHIFT_EXPORT: true, USER_LIST: false });

        render(<IndexPage />);

        expect(userWithAvatarSpy).toHaveBeenCalledWith(expect.objectContaining({ isLink: false }));
    });
});
