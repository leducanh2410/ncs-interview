import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/manage/report/index.page';

const { commonSearchBoxSpy, dataTableSpy, useGetReportsQueryMock, usePermissionsMock, routerMock, useAtomMock } =
    vi.hoisted(() => ({
        commonSearchBoxSpy: vi.fn(),
        dataTableSpy: vi.fn(),
        useGetReportsQueryMock: vi.fn(),
        usePermissionsMock: vi.fn(),
        useAtomMock: vi.fn(),
        routerMock: { query: {}, push: vi.fn() },
    }));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string, opts?: any) => (opts ? `${key}:${JSON.stringify(opts)}` : key) }),
}));

vi.mock('react-icons/lu', () => ({
    LuFolders: () => null,
    LuX: () => React.createElement('span', { 'data-testid': 'absent-icon' }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href, component: _c }: any) => React.createElement('a', { href }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick }: any) => React.createElement('button', { onClick }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('jotai/index', () => ({
    useAtom: (...args: unknown[]) => useAtomMock(...args),
    atom: (v: any) => v,
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: null,
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/manage/report/api', () => ({
    useGetReportsQuery: (...args: unknown[]) => useGetReportsQueryMock(...args),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) => {
        commonSearchBoxSpy(props);
        return React.createElement(
            'div',
            { 'data-testid': 'search-box' },
            props.elements.map((el: any) =>
                React.createElement('span', { 'key': el.id, 'data-testid': `filter-${el.id}` }, el.label),
            ),
        );
    },
    userRenderItem: vi.fn(),
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        dataTableSpy(props);
        const records: any[] = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);
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
    default: ({ user }: any) => React.createElement('span', null, user?.name),
}));

vi.mock('@/helpers/date.helper', () => {
    const chain = { subtract: () => chain, toDate: () => new Date() };
    return {
        default: {
            now: () => chain,
            parseAndFormat: (v: string) => v ?? '',
            formatWorkHour: () => ({ startTime: '09:00', endTime: '18:00' }),
        },
    };
});

vi.mock('@/helpers/workplace.helper', () => ({
    default: { filterWorkplacesByAccess: vi.fn(() => []) },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: (_route: string, params: any) => `/path/${params?.id ?? ''}` },
}));

vi.mock('@/configs/api.config', () => ({
    ApiConfig: { ADMIN_MASTER_USER_SEARCH: '/api/users/search' },
}));

vi.mock('@/configs/route.config', () => ({
    default: {
        HOME: { path: '/' },
        MANAGE_MONTHLY_REPORT_LIST: { path: '/manage/report/monthly' },
    },
}));

const makeReport = (overrides: Record<string, any> = {}) => ({
    id: 1,
    shift_user_id: 5,
    user_id: 3,
    date: '2026-05-10',
    late_minutes: null,
    early_minutes: null,
    is_absent: 0,
    user: { id: 3, name: 'Test User', staff_no: 'EMP001' },
    shift_user: {
        shift_id: 10,
        shift: {
            id: 10,
            date: '2026-05-10',
            workplace: { id: 1, name: 'Test Workplace' },
            workplace_shift: { start_time: '09:00:00', end_time: '18:00:00' },
        },
    },
    ...overrides,
});

const makePaginated = (items: any[]) => ({
    data: { data: items, current_page: 1, last_page: 1, per_page: 15, total: items.length },
});

describe('Report index page', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({ WORK_REPORT_LIST: true });

        useGetReportsQueryMock.mockReturnValue({
            data: makePaginated([makeReport()]),
            isLoading: false,
        });

        useAtomMock.mockReturnValue([{ workplaces: [], workplace_access: [] }, vi.fn()]);
    });

    describe('search form', () => {
        it('has date-range, workplace, and user filters', () => {
            render(<IndexPage />);

            expect(screen.getByTestId('filter-dates')).toBeInTheDocument();
            expect(screen.getByTestId('filter-workplace_id')).toBeInTheDocument();
            expect(screen.getByTestId('filter-user_id')).toBeInTheDocument();

            expect(commonSearchBoxSpy).toHaveBeenCalledWith(
                expect.objectContaining({
                    elements: expect.arrayContaining([
                        expect.objectContaining({ id: 'dates', type: 'date-range' }),
                        expect.objectContaining({ id: 'workplace_id', type: 'select' }),
                        expect.objectContaining({ id: 'user_id', type: 'select-remote' }),
                    ]),
                }),
            );
        });
    });

    describe('late arrival column', () => {
        it('shows minutes when late_minutes is set', () => {
            useGetReportsQueryMock.mockReturnValue({
                data: makePaginated([makeReport({ late_minutes: 15 })]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.getByText(/{{minute}} minutes/)).toBeInTheDocument();
        });

        it('shows dash when late_minutes is null', () => {
            render(<IndexPage />);

            const cells = screen.getAllByText('-');
            expect(cells.length).toBeGreaterThan(0);
        });
    });

    describe('early leave column', () => {
        it('shows minutes when early_minutes is set', () => {
            useGetReportsQueryMock.mockReturnValue({
                data: makePaginated([makeReport({ early_minutes: 10 })]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.getByText(/{{minute}} minutes/)).toBeInTheDocument();
        });
    });

    describe('absent column', () => {
        it('shows absent icon when is_absent is truthy', () => {
            useGetReportsQueryMock.mockReturnValue({
                data: makePaginated([makeReport({ is_absent: 1 })]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.getByTestId('absent-icon')).toBeInTheDocument();
        });

        it('shows dash when is_absent is falsy', () => {
            render(<IndexPage />);

            expect(screen.queryByTestId('absent-icon')).not.toBeInTheDocument();
        });
    });

    describe('permission', () => {
        it('passes noPermission=true to table when user lacks WORK_REPORT_LIST', () => {
            usePermissionsMock.mockReturnValue({ WORK_REPORT_LIST: false });

            render(<IndexPage />);

            expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ noPermission: true }));
        });
    });
});
