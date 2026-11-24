import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AdminCheckInSpoofIndexPage from '@/pages/admin/check-in-spoof/index.page';

const { commonSearchBoxSpy, dataTableSpy, useGetSpoofedCheckInsQueryMock, usePermissionsMock, routerMock } = vi.hoisted(
    () => ({
        commonSearchBoxSpy: vi.fn(),
        dataTableSpy: vi.fn(),
        useGetSpoofedCheckInsQueryMock: vi.fn(),
        usePermissionsMock: vi.fn(),
        routerMock: { query: {} },
    }),
);

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href, target, component: _c }: any) => React.createElement('a', { href, target }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/check-in-spoof/api', () => ({
    useGetSpoofedCheckInsQuery: (...args: unknown[]) => useGetSpoofedCheckInsQueryMock(...args),
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

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parseAndFormat: (v: string) => v ?? '',
        formatWorkHour: () => ({ startTime: '09:00', endTime: '18:00' }),
    },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: (_route: string, params: any) => `/path/${params?.id ?? ''}` },
}));

vi.mock('@/helpers/url.helper', () => ({
    default: { getStaticUrl: (path: string) => `https://cdn.example.com/${path}` },
}));

vi.mock('@/configs/api.config', () => ({
    ApiConfig: { ADMIN_MASTER_USER_SEARCH: '/api/users/search' },
}));

vi.mock('@/configs/route.config', () => ({
    default: { HOME: { path: '/' } },
}));

const makeVerification = (overrides: Record<string, any> = {}) => ({
    id: 1,
    check_in_id: 5,
    is_check_in: true,
    image_path: null,
    bounding_box: null,
    status: 'verified',
    is_spoofed: true,
    spoofed_confidence: 0.856,
    created_at: '2026-05-10 09:00:00',
    updated_at: '2026-05-10 09:05:00',
    check_in: {
        id: 5,
        shift_user_id: 10,
        user: { id: 3, name: 'Test User', staff_no: 'EMP001' },
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

describe('Check-in spoof index page', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({ CHECK_IN_SPOOF_LIST: true });

        useGetSpoofedCheckInsQueryMock.mockReturnValue({
            data: makePaginated([makeVerification()]),
            isPending: false,
        });
    });

    describe('search form', () => {
        it('has only the user filter', () => {
            render(<AdminCheckInSpoofIndexPage />);

            expect(screen.getByTestId('filter-user_id')).toBeInTheDocument();

            expect(commonSearchBoxSpy).toHaveBeenCalledWith(
                expect.objectContaining({
                    elements: [expect.objectContaining({ id: 'user_id', type: 'select-remote' })],
                }),
            );
        });
    });

    describe('spoofed confidence column', () => {
        it('renders confidence as a percentage with one decimal place', () => {
            render(<AdminCheckInSpoofIndexPage />);

            expect(screen.getByText('85.6%')).toBeInTheDocument();
        });

        it('renders 100.0% for a confidence of 1', () => {
            useGetSpoofedCheckInsQueryMock.mockReturnValue({
                data: makePaginated([makeVerification({ spoofed_confidence: 1 })]),
                isPending: false,
            });

            render(<AdminCheckInSpoofIndexPage />);

            expect(screen.getByText('100.0%')).toBeInTheDocument();
        });
    });

    describe('type column', () => {
        it('shows Check-In when is_check_in is true', () => {
            render(<AdminCheckInSpoofIndexPage />);

            expect(screen.getByText('Check-In')).toBeInTheDocument();
        });

        it('shows Check-Out when is_check_in is false', () => {
            useGetSpoofedCheckInsQueryMock.mockReturnValue({
                data: makePaginated([makeVerification({ is_check_in: false })]),
                isPending: false,
            });

            render(<AdminCheckInSpoofIndexPage />);

            expect(screen.getByText('Check-Out')).toBeInTheDocument();
        });
    });

    describe('image column', () => {
        it('shows a View link when image_path is set', () => {
            useGetSpoofedCheckInsQueryMock.mockReturnValue({
                data: makePaginated([makeVerification({ image_path: 'images/spoof/abc.jpg' })]),
                isPending: false,
            });

            render(<AdminCheckInSpoofIndexPage />);

            const link = screen.getByRole('link', { name: 'View' });
            expect(link).toHaveAttribute('href', 'https://cdn.example.com/images/spoof/abc.jpg');
        });

        it('shows a dash when image_path is null', () => {
            render(<AdminCheckInSpoofIndexPage />);

            expect(screen.queryByRole('link', { name: 'View' })).not.toBeInTheDocument();
            expect(screen.getByText('-')).toBeInTheDocument();
        });
    });
});
