import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PdfBatchDetail from './pdf_batch_detail';

const {
    usePermissionsMock,
    useGetExportBatchItemsQueryMock,
    downloadItemMock,
    downloadZipMock,
    showErrorMock,
    routerMock,
} = vi.hoisted(() => ({
    usePermissionsMock: vi.fn(),
    useGetExportBatchItemsQueryMock: vi.fn(),
    downloadItemMock: vi.fn(),
    downloadZipMock: vi.fn(),
    showErrorMock: vi.fn(),
    routerMock: { query: { page: '1' } },
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) =>
            opts && 'number' in opts ? key.replace('{{number}}', String(opts.number)) : key,
    }),
}));

vi.mock('next/router', () => ({ useRouter: () => routerMock }));

vi.mock('next/link', () => ({
    default: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@mantine/core', () => {
    const Progress: any = ({ children }: any) => React.createElement('div', null, children);
    Progress.Root = ({ children }: any) => React.createElement('div', null, children);
    Progress.Section = ({ children, value }: any) =>
        React.createElement('div', { 'data-testid': 'progress', 'data-value': value }, children);
    Progress.Label = ({ children }: any) => React.createElement('span', null, children);

    return {
        ActionIcon: ({ children, onClick }: any) =>
            React.createElement('button', { 'type': 'button', onClick, 'data-testid': 'download-item' }, children),
        Anchor: ({ children }: any) => React.createElement('a', null, children),
        Badge: ({ children }: any) => React.createElement('span', null, children),
        Box: ({ children }: any) => React.createElement('div', null, children),
        Button: ({ children, onClick, disabled, href }: any) =>
            href
                ? React.createElement('a', { href, 'data-testid': 'zip-link' }, children)
                : React.createElement('button', { type: 'button', onClick, disabled }, children),
        Card: ({ children }: any) => React.createElement('div', null, children),
        CardSection: ({ children }: any) => React.createElement('div', null, children),
        Flex: ({ children }: any) => React.createElement('div', null, children),
        Group: ({ children }: any) => React.createElement('div', null, children),
        Modal: ({ children, opened }: any) =>
            opened ? React.createElement('div', { 'data-testid': 'zip-modal' }, children) : null,
        Paper: ({ children }: any) => React.createElement('div', null, children),
        Progress,
        Stack: ({ children }: any) => React.createElement('div', null, children),
        Text: ({ children }: any) => React.createElement('span', null, children),
        Title: ({ children }: any) => React.createElement('h4', null, children),
        Tooltip: ({ children }: any) => children,
    };
});

vi.mock('react-icons/lu', () => ({
    LuDownload: () => React.createElement('span', null, '⬇'),
    LuFileWarning: () => React.createElement('span', null, '⚠'),
}));

vi.mock('@/configs/route.config', () => ({
    default: { ADMIN_PDF_TEMPLATE_DETAIL: { path: '/admin/pdf-template/[id]' } },
}));

vi.mock('@/enums/export_batch_status.enum', () => ({
    ExportBatchStatusEnum: {},
    getExportBatchStatusColor: () => 'green',
}));

vi.mock('@/enums/export_batch_item_status.enum', () => ({
    ExportBatchItemStatusEnum: {},
    getExportBatchItemStatusColor: () => 'green',
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (value: string) => `formatted:${value}` },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: { getText: (_enum: unknown, value: string) => `text:${value}` },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showError: (...args: unknown[]) => showErrorMock(...args) },
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => React.createElement('span', null, user?.name),
}));

vi.mock('@/components/DDataTable', () => ({
    default: ({ columns, data }: any) =>
        React.createElement(
            'table',
            null,
            React.createElement(
                'tbody',
                null,
                (data ?? []).map((row: any, ri: number) =>
                    React.createElement(
                        'tr',
                        { key: ri },
                        (columns ?? []).map((col: any, ci: number) =>
                            React.createElement('td', { key: ci }, col.render ? col.render(row) : row[col.key]),
                        ),
                    ),
                ),
            ),
        ),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/pdf-template/api', () => ({
    useGetExportBatchItemsQuery: (...args: unknown[]) => useGetExportBatchItemsQueryMock(...args),
    useDownloadExportItem: () => ({ mutateAsync: downloadItemMock, isPending: false }),
    useDownloadBatchZip: () => ({ mutateAsync: downloadZipMock, isPending: false }),
}));

const buildBatch = (overrides: Record<string, unknown> = {}) =>
    ({
        id: 7,
        status: 'completed',
        total_count: 4,
        completed_count: 3,
        failed_count: 1,
        created_at: '2026-01-02T03:04:05Z',
        exporter: { name: 'Admin One' },
        pdf_template: { id: 9, name: 'Contract' },
        ...overrides,
    }) as any;

const allPerms = { PDF_TEMPLATE_LIST: true, PDF_TEMPLATE_EXPORT: true };

describe('PdfBatchDetail', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue(allPerms);
        useGetExportBatchItemsQueryMock.mockReturnValue({ data: { data: [] }, isLoading: false });
    });

    it('renders batch header, exporter, template and formatted date', () => {
        render(<PdfBatchDetail batch={buildBatch()} />);

        expect(screen.getByRole('heading', { name: /Export Batch #7/ })).toBeInTheDocument();
        expect(screen.getByText('Admin One')).toBeInTheDocument();
        expect(screen.getByText('Contract')).toBeInTheDocument();
        expect(screen.getByText('formatted:2026-01-02T03:04:05Z')).toBeInTheDocument();
    });

    it('renders a dash when the batch has no template', () => {
        render(<PdfBatchDetail batch={buildBatch({ pdf_template: null })} />);

        expect(screen.getByText('-')).toBeInTheDocument();
    });

    it('computes the progress percentage from completed plus failed counts', () => {
        render(<PdfBatchDetail batch={buildBatch()} />);

        expect(screen.getByTestId('progress')).toHaveAttribute('data-value', '100');
        expect(screen.getByTestId('progress')).toHaveTextContent('4 / 4');
    });

    it('reports zero progress when the batch has no items', () => {
        render(<PdfBatchDetail batch={buildBatch({ total_count: 0, completed_count: 0, failed_count: 0 })} />);

        expect(screen.getByTestId('progress')).toHaveAttribute('data-value', '0');
    });

    it('polls the item list while the batch is still processing', () => {
        render(<PdfBatchDetail batch={buildBatch({ status: 'processing' })} />);

        expect(useGetExportBatchItemsQueryMock).toHaveBeenCalledWith('7', routerMock.query, true, 15000);
    });

    it('does not poll once the batch has completed', () => {
        render(<PdfBatchDetail batch={buildBatch()} />);

        expect(useGetExportBatchItemsQueryMock).toHaveBeenCalledWith('7', routerMock.query, true, 0);
    });

    it('disables the zip button when nothing completed', () => {
        render(<PdfBatchDetail batch={buildBatch({ completed_count: 0 })} />);

        expect(screen.getByRole('button', { name: /Download All \(ZIP\)/ })).toBeDisabled();
    });

    it('disables the zip button without PDF_TEMPLATE_EXPORT permission', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, PDF_TEMPLATE_EXPORT: false });

        render(<PdfBatchDetail batch={buildBatch()} />);

        expect(screen.getByRole('button', { name: /Download All \(ZIP\)/ })).toBeDisabled();
    });

    it('opens a single zip url directly without showing the modal', async () => {
        const user = userEvent.setup();
        const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null);
        downloadZipMock.mockResolvedValue(['https://files.test/a.zip']);

        render(<PdfBatchDetail batch={buildBatch()} />);
        await user.click(screen.getByRole('button', { name: /Download All \(ZIP\)/ }));

        await waitFor(() =>
            expect(openSpy).toHaveBeenCalledWith('https://files.test/a.zip', '_blank', 'noopener,noreferrer'),
        );
        expect(screen.queryByTestId('zip-modal')).not.toBeInTheDocument();
    });

    it('shows a chooser modal when the zip is split into several files', async () => {
        const user = userEvent.setup();
        const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null);
        downloadZipMock.mockResolvedValue(['https://files.test/a.zip', 'https://files.test/b.zip']);

        render(<PdfBatchDetail batch={buildBatch()} />);
        await user.click(screen.getByRole('button', { name: /Download All \(ZIP\)/ }));

        await waitFor(() => expect(screen.getByTestId('zip-modal')).toBeInTheDocument());
        const links = screen.getAllByTestId('zip-link');
        expect(links).toHaveLength(2);
        expect(links[0]).toHaveAttribute('href', 'https://files.test/a.zip');
        expect(links[0]).toHaveTextContent('ZIP file 1');
        expect(links[1]).toHaveTextContent('ZIP file 2');
        expect(openSpy).not.toHaveBeenCalled();
    });

    it('does nothing when the zip response is empty', async () => {
        const user = userEvent.setup();
        const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null);
        downloadZipMock.mockResolvedValue([]);

        render(<PdfBatchDetail batch={buildBatch()} />);
        await user.click(screen.getByRole('button', { name: /Download All \(ZIP\)/ }));

        await waitFor(() => expect(downloadZipMock).toHaveBeenCalled());
        expect(openSpy).not.toHaveBeenCalled();
        expect(screen.queryByTestId('zip-modal')).not.toBeInTheDocument();
    });

    it('shows an error when the zip download fails', async () => {
        const user = userEvent.setup();
        downloadZipMock.mockRejectedValue(new Error('boom'));

        render(<PdfBatchDetail batch={buildBatch()} />);
        await user.click(screen.getByRole('button', { name: /Download All \(ZIP\)/ }));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('Could not download ZIP file'));
    });

    it('renders export items with user and staff no', () => {
        useGetExportBatchItemsQueryMock.mockReturnValue({
            data: { data: [{ id: 1, status: 'completed', user: { id: 3, name: 'Alice', staff_no: 'S1' } }] },
            isLoading: false,
        });

        render(<PdfBatchDetail batch={buildBatch()} />);

        expect(screen.getByText('Alice')).toBeInTheDocument();
        expect(screen.getByText('S1')).toBeInTheDocument();
    });

    it('shows the error message for failed items', () => {
        useGetExportBatchItemsQueryMock.mockReturnValue({
            data: { data: [{ id: 1, status: 'failed', error_message: 'render failed', user: null }] },
            isLoading: false,
        });

        render(<PdfBatchDetail batch={buildBatch()} />);

        expect(screen.getByText('render failed')).toBeInTheDocument();
        expect(screen.queryByTestId('download-item')).not.toBeInTheDocument();
    });

    it('hides the per-item download button without PDF_TEMPLATE_EXPORT permission', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, PDF_TEMPLATE_EXPORT: false });
        useGetExportBatchItemsQueryMock.mockReturnValue({
            data: { data: [{ id: 1, status: 'completed', user: { id: 3, name: 'Alice' } }] },
            isLoading: false,
        });

        render(<PdfBatchDetail batch={buildBatch()} />);

        expect(screen.queryByTestId('download-item')).not.toBeInTheDocument();
    });

    it('downloads a single item when its button is clicked', async () => {
        const user = userEvent.setup();
        downloadItemMock.mockResolvedValue({});
        useGetExportBatchItemsQueryMock.mockReturnValue({
            data: { data: [{ id: 55, status: 'completed', user: { id: 3, name: 'Alice' } }] },
            isLoading: false,
        });

        render(<PdfBatchDetail batch={buildBatch()} />);
        await user.click(screen.getByTestId('download-item'));

        await waitFor(() => expect(downloadItemMock).toHaveBeenCalledWith(55));
        expect(showErrorMock).not.toHaveBeenCalled();
    });

    it('shows an error when a single item download fails', async () => {
        const user = userEvent.setup();
        downloadItemMock.mockRejectedValue(new Error('boom'));
        useGetExportBatchItemsQueryMock.mockReturnValue({
            data: { data: [{ id: 55, status: 'completed', user: { id: 3, name: 'Alice' } }] },
            isLoading: false,
        });

        render(<PdfBatchDetail batch={buildBatch()} />);
        await user.click(screen.getByTestId('download-item'));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('Could not download PDF'));
    });
});
