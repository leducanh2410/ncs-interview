import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExportBatchDetailPage from '@/pages/export-history/[id].page';

const { useGetUserExportBatchQueryMock, usePermissionsMock, routerMock } = vi.hoisted(() => ({
    useGetUserExportBatchQueryMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    routerMock: { query: { id: '5' }, isReady: true, push: vi.fn() },
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
    Box: ({ children }: any) => React.createElement('div', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Center: ({ children }: any) => React.createElement('div', { 'data-testid': 'center' }, children),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/configs/route.config', () => ({
    default: {
        HOME: { path: '/' },
        ADMIN_USER_LIST: { path: '/admin/user' },
        EXPORT_HISTORY: { path: '/export-history' },
    },
}));

vi.mock('@/enums/export_batch_type.enum', () => ({
    ExportBatchTypeEnum: {
        CSV: { value: 'csv', text: 'CSV' },
        PDF: { value: 'pdf', text: 'PDF' },
        WORK_HISTORY: { value: 'work_history', text: 'Work history' },
    },
}));

vi.mock('@/pages/admin/pdf-template/api', () => ({
    useGetUserExportBatchQuery: (...args: unknown[]) => useGetUserExportBatchQueryMock(...args),
}));

vi.mock('./partials/csv_batch_detail', () => ({
    default: ({ batch }: any) =>
        React.createElement('div', { 'data-testid': 'csv-batch-detail', 'data-batch-id': batch?.id }),
}));

vi.mock('./partials/pdf_batch_detail', () => ({
    default: ({ batch }: any) =>
        React.createElement('div', { 'data-testid': 'pdf-batch-detail', 'data-batch-id': batch?.id }),
}));

vi.mock('./partials/work_history_batch_detail', () => ({
    default: ({ batch }: any) =>
        React.createElement('div', { 'data-testid': 'work-history-batch-detail', 'data-batch-id': batch?.id }),
}));

describe('ExportBatchDetailPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = { id: '5' };
        routerMock.isReady = true;

        usePermissionsMock.mockReturnValue({ PDF_TEMPLATE_LIST: true, USER_EXPORT_CSV: true });

        useGetUserExportBatchQueryMock.mockReturnValue({ data: undefined, isLoading: false });
    });

    it('shows a loader while the query is loading', () => {
        useGetUserExportBatchQueryMock.mockReturnValue({ data: undefined, isLoading: true });

        render(<ExportBatchDetailPage />);

        expect(screen.getByTestId('loader')).toBeInTheDocument();
    });

    it('renders CsvBatchDetail when batch type is csv', () => {
        useGetUserExportBatchQueryMock.mockReturnValue({
            data: { data: { id: 5, type: 'csv' } },
            isLoading: false,
        });

        render(<ExportBatchDetailPage />);

        expect(screen.getByTestId('csv-batch-detail')).toBeInTheDocument();
        expect(screen.queryByTestId('pdf-batch-detail')).not.toBeInTheDocument();
    });

    it('renders PdfBatchDetail when batch type is pdf', () => {
        useGetUserExportBatchQueryMock.mockReturnValue({
            data: { data: { id: 5, type: 'pdf' } },
            isLoading: false,
        });

        render(<ExportBatchDetailPage />);

        expect(screen.getByTestId('pdf-batch-detail')).toBeInTheDocument();
        expect(screen.queryByTestId('csv-batch-detail')).not.toBeInTheDocument();
    });

    it('renders WorkHistoryBatchDetail when batch type is work_history', () => {
        usePermissionsMock.mockReturnValue({
            PDF_TEMPLATE_LIST: false,
            USER_EXPORT_CSV: false,
            WORK_HISTORY_EXPORT: true,
        });
        useGetUserExportBatchQueryMock.mockReturnValue({
            data: { data: { id: 5, type: 'work_history' } },
            isLoading: false,
        });

        render(<ExportBatchDetailPage />);

        expect(screen.getByTestId('work-history-batch-detail')).toBeInTheDocument();
        expect(screen.queryByTestId('csv-batch-detail')).not.toBeInTheDocument();
    });

    it('passes canViewHistory=true with only the work history permission', () => {
        usePermissionsMock.mockReturnValue({
            PDF_TEMPLATE_LIST: false,
            USER_EXPORT_CSV: false,
            WORK_HISTORY_EXPORT: true,
        });

        render(<ExportBatchDetailPage />);

        const [, canView] = useGetUserExportBatchQueryMock.mock.calls[0];
        expect(canView).toBe(true);
    });

    it('shows not-found message when batch has an unrecognised type', () => {
        useGetUserExportBatchQueryMock.mockReturnValue({
            data: { data: { id: 5, type: 'unknown' } },
            isLoading: false,
        });

        render(<ExportBatchDetailPage />);

        expect(screen.getByText('Export batch not found')).toBeInTheDocument();
        expect(screen.queryByTestId('csv-batch-detail')).not.toBeInTheDocument();
        expect(screen.queryByTestId('pdf-batch-detail')).not.toBeInTheDocument();
    });

    it('shows not-found message when batch is absent and not loading', () => {
        render(<ExportBatchDetailPage />);

        expect(screen.getByText('Export batch not found')).toBeInTheDocument();
    });

    it('includes the batch ID in the breadcrumb', () => {
        render(<ExportBatchDetailPage />);

        expect(screen.getByText('Export Batch #5')).toBeInTheDocument();
    });

    it('passes canViewHistory=true when either permission is granted', () => {
        usePermissionsMock.mockReturnValue({ PDF_TEMPLATE_LIST: false, USER_EXPORT_CSV: true });

        render(<ExportBatchDetailPage />);

        const [, canView] = useGetUserExportBatchQueryMock.mock.calls[0];
        expect(canView).toBe(true);
    });

    it('passes canViewHistory=false when no permission is granted', () => {
        usePermissionsMock.mockReturnValue({
            PDF_TEMPLATE_LIST: false,
            USER_EXPORT_CSV: false,
            WORK_HISTORY_EXPORT: false,
        });

        render(<ExportBatchDetailPage />);

        const [, canView] = useGetUserExportBatchQueryMock.mock.calls[0];
        expect(canView).toBe(false);
    });

    it('passes canViewHistory=false when router is not ready', () => {
        routerMock.isReady = false;

        render(<ExportBatchDetailPage />);

        const [, canView] = useGetUserExportBatchQueryMock.mock.calls[0];
        expect(canView).toBe(false);
    });
});
