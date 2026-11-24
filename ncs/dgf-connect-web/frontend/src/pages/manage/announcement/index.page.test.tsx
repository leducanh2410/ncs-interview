import React from 'react';
import { MantineProvider } from '@mantine/core';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from './index.page';

const { useGetAnnouncementsQueryMock, deleteMutateAsyncMock } = vi.hoisted(() => ({
    useGetAnnouncementsQueryMock: vi.fn(),
    deleteMutateAsyncMock: vi.fn(),
}));

vi.mock('@/pages/manage/announcement/api', () => ({
    useGetAnnouncementsQuery: (...args: unknown[]) => useGetAnnouncementsQueryMock(...args),
    useCreateAnnouncementMutation: () => ({ mutateAsync: vi.fn() }),
    useUpdateAnnouncementMutation: () => ({ mutateAsync: vi.fn() }),
    useDeleteAnnouncementMutation: () => ({ mutateAsync: deleteMutateAsyncMock, isPending: false }),
}));

vi.mock('@/pages/manage/announcement/partials/announcement_create_update', () => ({
    __esModule: true,
    default: () => null,
}));

vi.mock('jotai', () => ({
    useAtom: () => [{ workplaces: [{ id: 3, name: 'Xưởng Kawasaki' }] }],
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: { init: { workplaces: [] } },
}));

vi.mock('@/helpers/date.helper', () => ({
    __esModule: true,
    default: {
        parseAndFormat: (value: string | null) => (value ? `FORMATTED(${value})` : '-'),
    },
    DATE_STORE_FORMAT_WITH_TIME: 'YYYY-MM-DD HH:mm:ss',
}));

vi.mock('@/components/DDataTable', () => ({
    __esModule: true,
    default: ({ columns, data }: any) => {
        const records = Array.isArray(data) ? data : data?.data || [];
        return (
            <div data-testid="mock-datatable">
                {records.map((row: any, i: number) => (
                    <div key={i} data-testid="mock-row">
                        {columns.map((c: any, j: number) => (
                            <span key={j} data-testid={`col-${c.key}`}>
                                {c.render ? c.render(row) : row[c.key]}
                            </span>
                        ))}
                    </div>
                ))}
            </div>
        );
    },
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string) => key,
        i18n: { language: 'en' },
    }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => ({
        query: {},
        push: vi.fn(),
        replace: vi.fn(),
        pathname: '/',
    }),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (perms: string[]) => {
        const result: Record<string, boolean> = {};
        perms.forEach((p) => {
            result[p] = true;
        });
        return result;
    },
}));

const successQuery = (data: any) => ({
    data,
    isLoading: false,
    isError: false,
    isPending: false,
    refetch: vi.fn(),
});

const baseAnnouncement = {
    id: 7,
    title: 'Bảo trì dây chuyền A',
    content: '<p>Từ 10:00</p>',
    image_path: null,
    image_url: null,
    start_at: '2026-10-01 08:00:00',
    end_at: '2026-10-03 18:00:00',
    is_active: true,
    state: 'scheduled',
    created_by: 1,
    created_at: '2026-09-30 00:00:00',
    updated_at: '2026-09-30 00:00:00',
    workplaces: [
        { id: 3, name: 'Xưởng Kawasaki' },
        { id: 4, name: 'Xưởng Yokohama' },
    ],
    translations: [{ id: 1, announcement_id: 7, lang: 'ja', title: 'ライン A メンテナンス', content: '<p>10:00</p>' }],
};

describe('Announcement management IndexPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        deleteMutateAsyncMock.mockResolvedValue(undefined);
    });

    const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false } },
    });

    const renderComponent = () => {
        return render(
            <QueryClientProvider client={queryClient}>
                <MantineProvider env="test">
                    <IndexPage />
                </MantineProvider>
            </QueryClientProvider>,
        );
    };

    it('renders the title with flags, the workplaces, the period and the state badge', () => {
        useGetAnnouncementsQueryMock.mockReturnValue(
            successQuery({
                data: [baseAnnouncement],
                meta: { current_page: 1, last_page: 1, total: 1 },
            }),
        );

        renderComponent();

        const titleCol = screen.getByTestId('col-title');
        expect(titleCol.textContent).toContain('🇻🇳');
        expect(titleCol.textContent).toContain('Bảo trì dây chuyền A');
        expect(titleCol.textContent).toContain('🇯🇵');
        expect(titleCol.textContent).toContain('ライン A メンテナンス');

        expect(screen.getByTestId('col-workplaces').textContent).toBe('Xưởng Kawasaki, Xưởng Yokohama');

        const periodCol = screen.getByTestId('col-period');
        expect(periodCol.textContent).toContain('FORMATTED(2026-10-01 08:00:00)');
        expect(periodCol.textContent).toContain('FORMATTED(2026-10-03 18:00:00)');

        expect(screen.getByTestId('col-state').textContent).toBe('enum|Scheduled');
    });

    it('shows a placeholder icon when there is no image and a thumbnail when there is one', () => {
        useGetAnnouncementsQueryMock.mockReturnValue(
            successQuery({
                data: [baseAnnouncement, { ...baseAnnouncement, id: 8, image_url: 'https://cdn/img.jpg' }],
                meta: { current_page: 1, last_page: 1, total: 2 },
            }),
        );

        renderComponent();

        const imageCols = screen.getAllByTestId('col-image');
        expect(imageCols[0].querySelector('img')).toBeNull();
        expect(imageCols[1].querySelector('img')).toHaveAttribute('src', 'https://cdn/img.jpg');
    });

    it('asks for confirmation and then calls the delete mutation with the row id', async () => {
        useGetAnnouncementsQueryMock.mockReturnValue(
            successQuery({
                data: [baseAnnouncement],
                meta: { current_page: 1, last_page: 1, total: 1 },
            }),
        );

        renderComponent();

        const actionCol = screen.getByTestId('col-action');
        const buttons = actionCol.querySelectorAll('button');
        fireEvent.click(buttons[1]);

        const confirmButton = await screen.findByRole('button', { name: 'Delete announcement' });
        fireEvent.click(confirmButton);

        await waitFor(() => expect(deleteMutateAsyncMock).toHaveBeenCalledWith('7'));
    });
});
