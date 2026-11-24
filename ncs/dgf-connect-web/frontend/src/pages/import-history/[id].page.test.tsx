import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ImportBatchDetailPage from '@/pages/import-history/[id].page';

const {
    usePermissionsMock,
    useAuthLoadedMock,
    useGetImportBatchQueryMock,
    downloadMutateMock,
    showErrorMock,
    clickSpy,
} = vi.hoisted(() => ({
    usePermissionsMock: vi.fn(),
    useAuthLoadedMock: vi.fn(),
    useGetImportBatchQueryMock: vi.fn(),
    downloadMutateMock: vi.fn(),
    showErrorMock: vi.fn(),
    clickSpy: vi.fn(),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: any) => React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => ({ query: { id: '3' }, isReady: true }),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, options?: Record<string, unknown>) => {
            if (!options) return key;

            return Object.entries(options).reduce(
                (result, [placeholder, value]) => result.replace(`{{${placeholder}}}`, String(value)),
                key,
            );
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick }: any) => React.createElement('button', { type: 'button', onClick }, children),
    Card: ({ children }: any) => React.createElement('section', null, children),
    CardSection: ({ children }: any) => React.createElement('div', null, children),
    Center: ({ children }: any) => React.createElement('div', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
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

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => React.createElement('a', { href: `/admin/user/${user.id}` }, user.name),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parseAndFormat: (value: string) => value,
    },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: vi.fn(),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
    useAuthLoaded: () => useAuthLoadedMock(),
}));

vi.mock('@/pages/import-history/api', () => ({
    useGetImportBatchQuery: (...args: unknown[]) => useGetImportBatchQueryMock(...args),
    useGetImportBatchDownloadUrlMutation: () => ({ mutateAsync: downloadMutateMock, isPending: false }),
}));

const buildDetail = () => ({
    data: {
        data: {
            batch: {
                id: 3,
                type: 'shift_order',
                status: 'completed',
                file_name: 'order.xlsx',
                importer: { id: 1, name: 'Admin One' },
                created_count: 10,
                updated_count: 2,
                created_at: '2026-09-17 10:00:00',
            },
            month: '2026-10',
            sheet_name: '2026.10',
            skipped_count: 1,
            workplaces: [
                {
                    workplace_id: 5,
                    workplace_name: 'Musashino',
                    applied_count: 11,
                    skipped_count: 1,
                },
            ],
        },
    },
    isLoading: false,
});

describe('Import batch detail page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue({ SHIFT_IMPORT_ORDER: true });
        useAuthLoadedMock.mockReturnValue(true);
        useGetImportBatchQueryMock.mockReturnValue(buildDetail());
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(clickSpy);
    });

    it('shows the file, importer and per-workplace result', () => {
        render(<ImportBatchDetailPage />);

        expect(screen.getByText('order.xlsx')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Admin One' })).toHaveAttribute('href', '/admin/user/1');
        expect(screen.getByText('2026-10')).toBeInTheDocument();
        expect(screen.getByText('Musashino')).toBeInTheDocument();
        expect(screen.getByText('Created shifts: 10')).toBeInTheDocument();
        expect(screen.getByText('Updated shifts: 2')).toBeInTheDocument();
        expect(screen.getByText('Kept as in the system: 1')).toBeInTheDocument();
    });

    it('opens the download link returned by the server', async () => {
        downloadMutateMock.mockResolvedValue({ data: { download_url: 'https://files.test/order.xlsx' } });

        render(<ImportBatchDetailPage />);
        await userEvent.click(screen.getByRole('button', { name: 'Download file' }));

        expect(downloadMutateMock).toHaveBeenCalledWith(3);
        expect(clickSpy).toHaveBeenCalled();
    });

    it('warns when the file can no longer be downloaded', async () => {
        downloadMutateMock.mockRejectedValue(new Error('gone'));

        render(<ImportBatchDetailPage />);
        await userEvent.click(screen.getByRole('button', { name: 'Download file' }));

        expect(showErrorMock).toHaveBeenCalledWith('Could not download the imported file');
    });

    it('waits for the permissions to load instead of claiming the batch is missing', () => {
        useAuthLoadedMock.mockReturnValue(false);
        useGetImportBatchQueryMock.mockReturnValue({ data: undefined, isLoading: false });

        render(<ImportBatchDetailPage />);

        expect(screen.getByTestId('loader')).toBeInTheDocument();
        expect(screen.queryByText('Import batch not found')).not.toBeInTheDocument();
        expect(useGetImportBatchQueryMock).toHaveBeenCalledWith('3', false);
    });

    it('tells the admin when the batch does not exist', () => {
        useGetImportBatchQueryMock.mockReturnValue({ data: { data: null }, isLoading: false });

        render(<ImportBatchDetailPage />);

        expect(screen.getByText('Import batch not found')).toBeInTheDocument();
    });
});
