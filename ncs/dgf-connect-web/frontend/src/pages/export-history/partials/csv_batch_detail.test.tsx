import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CsvBatchDetail from './csv_batch_detail';

const { usePermissionsMock, downloadMock, showErrorMock, isPendingRef } = vi.hoisted(() => ({
    usePermissionsMock: vi.fn(),
    downloadMock: vi.fn(),
    showErrorMock: vi.fn(),
    isPendingRef: { current: false },
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) =>
            opts && 'count' in opts ? key.replace('{{count}}', String(opts.count)) : key,
    }),
}));

vi.mock('@mantine/core', () => ({
    Badge: ({ children, color }: any) =>
        React.createElement('span', { 'data-testid': 'badge', 'data-color': color }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { 'type': 'button', onClick, disabled, 'data-loading': !!loading }, children),
    Card: ({ children }: any) => React.createElement('div', null, children),
    CardSection: ({ children }: any) => React.createElement('div', null, children),
    Center: ({ children }: any) => React.createElement('div', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
    SimpleGrid: ({ children }: any) => React.createElement('div', null, children),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Table: Object.assign(
        ({ children }: any) => React.createElement('table', { 'data-testid': 'user-table' }, children),
        {
            Thead: ({ children }: any) => React.createElement('thead', null, children),
            Tbody: ({ children }: any) => React.createElement('tbody', null, children),
            Tr: ({ children }: any) => React.createElement('tr', null, children),
            Th: ({ children }: any) => React.createElement('th', null, children),
            Td: ({ children }: any) => React.createElement('td', null, children),
        },
    ),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h4', null, children),
}));

vi.mock('react-icons/lu', () => ({
    LuDownload: () => React.createElement('span', null, '⬇'),
    LuFileWarning: () => React.createElement('span', null, '⚠'),
}));

vi.mock('@/enums/export_batch_status.enum', () => ({
    ExportBatchStatusEnum: { COMPLETED: { value: 'completed', text: 'Completed' } },
    getExportBatchStatusColor: (status: string) => (status === 'completed' ? 'green' : 'gray'),
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

vi.mock('@/pages/admin/csv-export/csv_property_label', () => ({
    getCsvPropertyLabel: (key: string) => `label:${key}`,
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/csv-export/api', () => ({
    useGetCsvExportDownloadUrlMutation: () => ({ mutateAsync: downloadMock, isPending: isPendingRef.current }),
}));

const buildBatch = (overrides: Record<string, unknown> = {}) =>
    ({
        id: 12,
        status: 'completed',
        total_count: 3,
        created_at: '2026-01-02T03:04:05Z',
        error_message: null,
        exporter: { name: 'Admin One' },
        users: [],
        meta: { export_keys: [], user_ids: [], encoding: null },
        ...overrides,
    }) as any;

describe('CsvBatchDetail', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        isPendingRef.current = false;
        usePermissionsMock.mockReturnValue({ USER_EXPORT_CSV: true });
    });

    it('renders only a loader without USER_EXPORT_CSV permission', () => {
        usePermissionsMock.mockReturnValue({ USER_EXPORT_CSV: false });

        render(<CsvBatchDetail batch={buildBatch()} />);

        expect(screen.getByTestId('loader')).toBeInTheDocument();
        expect(screen.queryByText('Export Info')).not.toBeInTheDocument();
    });

    it('renders the batch id, exporter, total and formatted created date', () => {
        render(<CsvBatchDetail batch={buildBatch()} />);

        expect(screen.getByRole('heading')).toHaveTextContent('Export Batch #12');
        expect(screen.getByText('Admin One')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
        expect(screen.getByText('formatted:2026-01-02T03:04:05Z')).toBeInTheDocument();
    });

    it('renders a dash when the batch has no exporter', () => {
        render(<CsvBatchDetail batch={buildBatch({ exporter: null })} />);

        expect(screen.getByText('-')).toBeInTheDocument();
    });

    it('renders the status badge with the status color', () => {
        render(<CsvBatchDetail batch={buildBatch({ status: 'failed' })} />);

        const badge = screen.getByTestId('badge');
        expect(badge).toHaveTextContent('text:failed');
        expect(badge).toHaveAttribute('data-color', 'gray');
    });

    it('shows the encoding in upper case when present', () => {
        render(<CsvBatchDetail batch={buildBatch({ meta: { export_keys: [], user_ids: [], encoding: 'sjis' } })} />);

        expect(screen.getByText('Encoding')).toBeInTheDocument();
        expect(screen.getByText('SJIS')).toBeInTheDocument();
    });

    it('hides the encoding row when absent', () => {
        render(<CsvBatchDetail batch={buildBatch()} />);

        expect(screen.queryByText('Encoding')).not.toBeInTheDocument();
    });

    it('shows the error message when the batch failed', () => {
        render(<CsvBatchDetail batch={buildBatch({ status: 'failed', error_message: 'disk full' })} />);

        expect(screen.getByText('disk full')).toBeInTheDocument();
    });

    it('shows an empty state when no properties were selected', () => {
        render(<CsvBatchDetail batch={buildBatch()} />);

        expect(screen.getByText('No properties selected')).toBeInTheDocument();
    });

    it('lists the selected properties with a 1-based index', () => {
        render(
            <CsvBatchDetail
                batch={buildBatch({ meta: { export_keys: ['email', 'staff_no'], user_ids: [], encoding: null } })}
            />,
        );

        expect(screen.getByText('label:email')).toBeInTheDocument();
        expect(screen.getByText('label:staff_no')).toBeInTheDocument();
        expect(screen.getByText('1')).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
    });

    it('hides the user selection card when no user ids were exported', () => {
        render(<CsvBatchDetail batch={buildBatch()} />);

        expect(screen.queryByText('User Selection')).not.toBeInTheDocument();
    });

    it('renders the user table when users are present', () => {
        render(
            <CsvBatchDetail
                batch={buildBatch({
                    meta: { export_keys: [], user_ids: [1, 2], encoding: null },
                    users: [
                        { id: 1, name: 'Alice', staff_no: 'S1' },
                        { id: 2, name: 'Bob', staff_no: null },
                    ],
                })}
            />,
        );

        expect(screen.getByTestId('user-table')).toBeInTheDocument();
        expect(screen.getByText('Alice')).toBeInTheDocument();
        expect(screen.getByText('S1')).toBeInTheDocument();
        expect(screen.getByText('Bob')).toBeInTheDocument();
    });

    it('falls back to a selected-count summary when user ids exist but users are not loaded', () => {
        render(
            <CsvBatchDetail batch={buildBatch({ meta: { export_keys: [], user_ids: [1, 2, 3], encoding: null } })} />,
        );

        expect(screen.queryByTestId('user-table')).not.toBeInTheDocument();
        expect(screen.getByText('3 selected users')).toBeInTheDocument();
    });

    it('disables the download button unless the batch is completed', () => {
        render(<CsvBatchDetail batch={buildBatch({ status: 'processing' })} />);

        expect(screen.getByRole('button', { name: /Download CSV/ })).toBeDisabled();
    });

    it('opens the download url in a new tab on success', async () => {
        const user = userEvent.setup();
        const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null);
        downloadMock.mockResolvedValue({ data: { download_url: 'https://files.test/a.csv' } });

        render(<CsvBatchDetail batch={buildBatch()} />);
        await user.click(screen.getByRole('button', { name: /Download CSV/ }));

        await waitFor(() => expect(downloadMock).toHaveBeenCalledWith(12));
        expect(openSpy).toHaveBeenCalledWith('https://files.test/a.csv', '_blank', 'noopener,noreferrer');
        expect(showErrorMock).not.toHaveBeenCalled();
    });

    it('shows an error when the response has no download url', async () => {
        const user = userEvent.setup();
        downloadMock.mockResolvedValue({ data: { download_url: null } });

        render(<CsvBatchDetail batch={buildBatch()} />);
        await user.click(screen.getByRole('button', { name: /Download CSV/ }));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('Could not download CSV'));
    });

    it('shows an error when the download request throws', async () => {
        const user = userEvent.setup();
        downloadMock.mockRejectedValue(new Error('network'));

        render(<CsvBatchDetail batch={buildBatch()} />);
        await user.click(screen.getByRole('button', { name: /Download CSV/ }));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('Could not download CSV'));
    });

    it('marks the download button as loading while the request is pending', () => {
        isPendingRef.current = true;

        render(<CsvBatchDetail batch={buildBatch()} />);

        expect(screen.getByRole('button', { name: /Download CSV/ })).toHaveAttribute('data-loading', 'true');
    });
});
