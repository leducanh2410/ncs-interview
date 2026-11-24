import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExportHistoryPage from '@/pages/export-history/index.page';

const { useGetExportHistoryQueryMock, useGetPdfTemplatesQueryMock, usePermissionsMock, searchBoxSpy, routerMock } =
    vi.hoisted(() => ({
        useGetExportHistoryQueryMock: vi.fn(),
        useGetPdfTemplatesQueryMock: vi.fn(),
        usePermissionsMock: vi.fn(),
        searchBoxSpy: vi.fn(),
        routerMock: { query: {}, push: vi.fn() },
    }));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({ useRouter: () => routerMock }));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h2', null, children),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/configs/route.config', () => ({
    default: {
        HOME: { path: '/' },
        ADMIN_USER_LIST: { path: '/admin/user' },
    },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: {
        getPath: (_route: string, params: any) => `/admin/export-history/${params.id}`,
    },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (date: string) => date ?? '-' },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        getText: (_e: any, value: string) => value,
        toSelectArray: (e: any) => Object.values(e).map((v: any) => ({ value: v.value, label: v.text })),
    },
}));

vi.mock('@/enums/export_batch_status.enum', () => ({
    ExportBatchStatusEnum: {
        COMPLETED: { value: 'completed', text: 'Completed' },
        PENDING: { value: 'pending', text: 'Pending' },
    },
    getExportBatchStatusColor: () => 'gray',
}));

vi.mock('@/enums/export_batch_type.enum', () => ({
    ExportBatchTypeEnum: {
        PDF: { value: 'pdf', text: 'PDF' },
        CSV: { value: 'csv', text: 'CSV' },
        WORK_HISTORY: { value: 'work_history', text: 'Work history' },
    },
    getExportBatchTypeColor: () => 'blue',
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) => {
        searchBoxSpy(props);
        return React.createElement(
            'div',
            { 'data-testid': 'search-box' },
            props.elements.map((el: any) =>
                React.createElement('span', { 'key': el.id, 'data-element-id': el.id }, el.label),
            ),
        );
    },
}));

vi.mock('@/components/DDataTable', () => ({
    default: ({ columns, data }: any) => {
        const records = Array.isArray(data) ? data : (data?.data ?? []);

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
                        columns.map((col: any) =>
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

vi.mock('@/pages/admin/pdf-template/api', () => ({
    useGetExportHistoryQuery: (...args: unknown[]) => useGetExportHistoryQueryMock(...args),
    useGetPdfTemplatesQuery: (...args: unknown[]) => useGetPdfTemplatesQueryMock(...args),
}));

const fakeBatch = {
    id: 7,
    type: 'csv',
    status: 'completed',
    pdf_template: null,
    exporter: { id: 1, name: 'Admin User' },
    total_count: 50,
    completed_count: 48,
    failed_count: 2,
    created_at: '2024-06-01T09:00:00Z',
};

describe('ExportHistoryPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = {};

        usePermissionsMock.mockReturnValue({ PDF_TEMPLATE_LIST: true, USER_EXPORT_CSV: true });

        useGetPdfTemplatesQueryMock.mockReturnValue({
            data: { data: { data: [], total: 0 } },
        });

        useGetExportHistoryQueryMock.mockReturnValue({
            data: { data: { data: [fakeBatch], total: 1 } },
            isLoading: false,
        });
    });

    it('shows the type select filter in the search form', () => {
        render(<ExportHistoryPage />);

        expect(screen.getByTestId('search-box')).toBeInTheDocument();
        expect(screen.getByText('Type')).toBeInTheDocument();
    });

    it('shows the template and source filters only while the pdf type (or no type) is selected', () => {
        render(<ExportHistoryPage />);

        const passedProps = searchBoxSpy.mock.calls[0][0];
        const templateElement = passedProps.elements.find((el: any) => el.id === 'template_id');
        const sourceElement = passedProps.elements.find((el: any) => el.id === 'source');

        expect(templateElement.visibleWhen({ type: '' })).toBe(true);
        expect(templateElement.visibleWhen({ type: 'pdf' })).toBe(true);
        expect(templateElement.visibleWhen({ type: 'csv' })).toBe(false);
        expect(templateElement.visibleWhen({ type: 'work_history' })).toBe(false);
        expect(sourceElement.visibleWhen({ type: 'csv' })).toBe(false);
        expect(sourceElement.visibleWhen({ type: 'pdf' })).toBe(true);
    });

    it('omits the template filter entirely without the pdf template permission', () => {
        usePermissionsMock.mockReturnValue({
            PDF_TEMPLATE_LIST: false,
            USER_EXPORT_CSV: true,
            WORK_HISTORY_EXPORT: true,
        });

        render(<ExportHistoryPage />);

        const passedProps = searchBoxSpy.mock.calls[0][0];
        const elementIds = passedProps.elements.map((el: any) => el.id);
        expect(elementIds).not.toContain('template_id');
        expect(elementIds).not.toContain('source');
    });

    it('offers only the type options the user has permission for', () => {
        usePermissionsMock.mockReturnValue({
            PDF_TEMPLATE_LIST: false,
            USER_EXPORT_CSV: true,
            WORK_HISTORY_EXPORT: true,
        });

        render(<ExportHistoryPage />);

        const passedProps = searchBoxSpy.mock.calls[0][0];
        const typeElement = passedProps.elements.find((el: any) => el.id === 'type');
        const optionValues = typeElement.options.map((opt: any) => opt.value);
        expect(optionValues).toEqual(['csv', 'work_history']);
    });

    it('renders export history rows with type badge and view link', () => {
        render(<ExportHistoryPage />);

        expect(screen.getByText('csv')).toBeInTheDocument();
        expect(screen.getByText('Admin User')).toBeInTheDocument();

        const viewLink = screen.getByRole('link', { name: 'View' });
        expect(viewLink).toHaveAttribute('href', '/admin/export-history/7');
    });

    it('passes canViewHistory based on combined permissions', () => {
        render(<ExportHistoryPage />);

        const [, canView] = useGetExportHistoryQueryMock.mock.calls[0];
        expect(canView).toBe(true);
    });

    it('disables history fetch when user has no relevant permissions', () => {
        usePermissionsMock.mockReturnValue({
            PDF_TEMPLATE_LIST: false,
            USER_EXPORT_CSV: false,
            WORK_HISTORY_EXPORT: false,
        });

        render(<ExportHistoryPage />);

        const [, canView] = useGetExportHistoryQueryMock.mock.calls[0];
        expect(canView).toBe(false);
    });
});
