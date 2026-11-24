import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WorkHistoryBatchDetail from './work_history_batch_detail';

const { usePermissionsMock, downloadMock, showErrorMock, isPendingRef } = vi.hoisted(() => ({
    usePermissionsMock: vi.fn(),
    downloadMock: vi.fn(),
    showErrorMock: vi.fn(),
    isPendingRef: { current: false },
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
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
    Stack: ({ children }: any) => React.createElement('div', null, children),
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

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/export-history/api', () => ({
    useGetWorkHistoryExportDownloadUrlMutation: () => ({
        mutateAsync: downloadMock,
        isPending: isPendingRef.current,
    }),
}));

const buildBatch = (overrides: Record<string, unknown> = {}) =>
    ({
        id: 21,
        status: 'completed',
        total_count: 2,
        completed_count: 2,
        created_at: '2026-05-20T10:00:00Z',
        error_message: null,
        exporter: { name: 'Export Manager' },
        meta: { conditions: { dates: ['2026-05-01', '2026-05-31'] } },
        ...overrides,
    }) as any;

describe('WorkHistoryBatchDetail', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        isPendingRef.current = false;
        usePermissionsMock.mockReturnValue({ WORK_HISTORY_EXPORT: true });
    });

    it('renders only a loader without WORK_HISTORY_EXPORT permission', () => {
        usePermissionsMock.mockReturnValue({ WORK_HISTORY_EXPORT: false });

        render(<WorkHistoryBatchDetail batch={buildBatch()} />);

        expect(screen.getByTestId('loader')).toBeInTheDocument();
        expect(screen.queryByText('Export Info')).not.toBeInTheDocument();
    });

    it('renders batch details, progress, date range and error message', () => {
        render(<WorkHistoryBatchDetail batch={buildBatch({ status: 'failed', error_message: 'disk full' })} />);

        expect(screen.getByRole('heading')).toHaveTextContent('Export Batch #21');
        expect(screen.getByText('Export Manager')).toBeInTheDocument();
        expect(screen.getByText('formatted:2026-05-01 ~ formatted:2026-05-31')).toBeInTheDocument();
        expect(screen.getByText('2 / 2')).toBeInTheDocument();
        expect(screen.getByText('disk full')).toBeInTheDocument();
        expect(screen.getByTestId('badge')).toHaveTextContent('text:failed');
    });

    it('disables download until the batch is completed', () => {
        render(<WorkHistoryBatchDetail batch={buildBatch({ status: 'processing' })} />);

        expect(screen.getByRole('button', { name: /Download ZIP/ })).toBeDisabled();
    });

    it('downloads through an anchor after receiving the url', async () => {
        const user = userEvent.setup();
        let clickedAnchor: HTMLAnchorElement | null = null;
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            clickedAnchor = this;
        });
        downloadMock.mockResolvedValue({ data: { download_url: 'https://files.test/work-history.zip' } });

        render(<WorkHistoryBatchDetail batch={buildBatch()} />);
        await user.click(screen.getByRole('button', { name: /Download ZIP/ }));

        await waitFor(() => expect(downloadMock).toHaveBeenCalledWith(21));
        expect(clickedAnchor).not.toBeNull();
        expect(clickedAnchor!.href).toBe('https://files.test/work-history.zip');
        expect(clickedAnchor!.target).toBe('_blank');
        expect(clickedAnchor!.rel).toBe('noopener noreferrer');
        expect(showErrorMock).not.toHaveBeenCalled();
    });

    it('shows an error when the response has no download url', async () => {
        const user = userEvent.setup();
        downloadMock.mockResolvedValue({ data: { download_url: null } });

        render(<WorkHistoryBatchDetail batch={buildBatch()} />);
        await user.click(screen.getByRole('button', { name: /Download ZIP/ }));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('Could not download export file'));
    });

    it('shows an error when the download request fails', async () => {
        const user = userEvent.setup();
        downloadMock.mockRejectedValue(new Error('network'));

        render(<WorkHistoryBatchDetail batch={buildBatch()} />);
        await user.click(screen.getByRole('button', { name: /Download ZIP/ }));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('Could not download export file'));
    });

    it('marks the download button as loading while the request is pending', () => {
        isPendingRef.current = true;

        render(<WorkHistoryBatchDetail batch={buildBatch()} />);

        expect(screen.getByRole('button', { name: /Download ZIP/ })).toHaveAttribute('data-loading', 'true');
    });
});
