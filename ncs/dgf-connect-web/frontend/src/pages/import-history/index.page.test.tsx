import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ImportHistoryPage from '@/pages/import-history/index.page';

const { usePermissionsMock, useAuthLoadedMock, useGetImportHistoryQueryMock, dataTableSpy, routerMock } = vi.hoisted(
    () => ({
        usePermissionsMock: vi.fn(),
        useAuthLoadedMock: vi.fn(),
        useGetImportHistoryQueryMock: vi.fn(),
        dataTableSpy: vi.fn(),
        routerMock: { query: {} as Record<string, unknown> },
    }),
);

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

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parseAndFormat: (value: string) => value,
    },
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
    useAuthLoaded: () => useAuthLoadedMock(),
}));

vi.mock('@/pages/import-history/api', () => ({
    useGetImportHistoryQuery: (...args: unknown[]) => useGetImportHistoryQueryMock(...args),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) =>
        React.createElement(
            'div',
            { 'data-testid': 'import-search-box' },
            props.elements.map((element: any) => React.createElement('span', { key: element.id }, element.label)),
        ),
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => React.createElement('a', { href: `/admin/user/${user.id}` }, user.name),
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

const buildBatch = (overrides: Record<string, unknown> = {}) => ({
    id: 3,
    type: 'shift_order',
    status: 'completed',
    file_name: 'order.xlsx',
    importer: { id: 1, name: 'Admin One' },
    created_count: 12,
    updated_count: 4,
    created_at: '2026-09-17 10:00:00',
    ...overrides,
});

describe('Import history page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = {};
        usePermissionsMock.mockReturnValue({ SHIFT_IMPORT_ORDER: true });
        useAuthLoadedMock.mockReturnValue(true);
        useGetImportHistoryQueryMock.mockReturnValue({
            data: { data: { data: [buildBatch()], current_page: 1, last_page: 1, per_page: 15, total: 1 } },
            isLoading: false,
        });
    });

    it('shows the file, importer and counts of each import', () => {
        render(<ImportHistoryPage />);

        expect(screen.getByText('order.xlsx')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Admin One' })).toHaveAttribute('href', '/admin/user/1');
        expect(screen.getByText('12')).toBeInTheDocument();
        expect(screen.getByText('4')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'View' })).toHaveAttribute('href', '/import-history/3');
    });

    it('offers the shift order type filter only', () => {
        render(<ImportHistoryPage />);

        expect(screen.getByTestId('import-search-box')).toHaveTextContent('Type');
    });

    it('keeps the table in a loading state until the permissions are known', () => {
        useAuthLoadedMock.mockReturnValue(false);
        usePermissionsMock.mockReturnValue({ SHIFT_IMPORT_ORDER: false });
        useGetImportHistoryQueryMock.mockReturnValue({ data: undefined, isLoading: false });

        render(<ImportHistoryPage />);

        expect(screen.getByRole('table')).toHaveAttribute('data-no-permission', 'false');
        expect(useGetImportHistoryQueryMock).toHaveBeenCalledWith({}, false);
    });

    it('marks the table as not permitted without the import order permission', () => {
        usePermissionsMock.mockReturnValue({ SHIFT_IMPORT_ORDER: false });
        useGetImportHistoryQueryMock.mockReturnValue({ data: undefined, isLoading: false });

        render(<ImportHistoryPage />);

        expect(screen.getByRole('table')).toHaveAttribute('data-no-permission', 'true');
        expect(useGetImportHistoryQueryMock).toHaveBeenCalledWith({}, false);
    });
});
