import React from 'react';
import { MantineProvider } from '@mantine/core';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from './index.page';

const { useGetNotificationsQueryMock, useDeleteNotificationMutationMock } = vi.hoisted(() => ({
    useGetNotificationsQueryMock: vi.fn(),
    useDeleteNotificationMutationMock: vi.fn().mockReturnValue({ mutateAsync: vi.fn(), isPending: false }),
}));

vi.mock('@/pages/manage/notification/api', () => ({
    useGetNotificationsQuery: (...args: unknown[]) => useGetNotificationsQueryMock(...args),
    useCreateNotificationMutation: () => ({ mutateAsync: vi.fn() }),
    useUpdateNotificationMutation: () => ({ mutateAsync: vi.fn() }),
    useDeleteNotificationMutation: () => useDeleteNotificationMutationMock(),
}));

vi.mock('@/pages/manage/shift/api', () => ({
    useGetActiveShiftsQuery: () => ({ data: { data: [] }, isLoading: false }),
}));

vi.mock('jotai', () => ({
    useAtom: () => [{ workplaces: [] }],
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: { init: { workplaces: [] } },
}));

vi.mock('@/helpers/date.helper', () => ({
    __esModule: true,
    default: {
        now: () => ({
            subtract: (_n: number, _unit: string) => ({ toDate: () => new Date('2026-07-29T00:00:00Z') }),
            toDate: () => new Date('2026-08-05T00:00:00Z'),
        }),
        parseAndFormat: (value: string | null) => (value ? `FORMATTED(${value})` : '-'),
        formatWorkHour: (start: string, end: string) => ({ startTime: start, endTime: end }),
    },
    DATE_STORE_FORMAT_WITH_TIME: 'YYYY-MM-DD HH:mm:ss',
}));

vi.mock('@/components/DDataTable', () => ({
    __esModule: true,
    headerWrapClassName: 'header-wrap-mock',
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
        t: (key: string, opts?: Record<string, unknown>) =>
            opts ? key.replace(/{{(\w+)}}/g, (_, k) => String(opts[k] ?? '')) : key,
        i18n: { language: 'en' },
    }),
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

describe('Notification management IndexPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
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

    const baseNotification = {
        id: 1,
        type: 'normal',
        title: 'Tiêu đề gốc',
        content: 'Nội dung gốc',
        status: 'sent',
        icon: null,
        url: null,
        is_manually_scheduled: 0,
        scheduled_at: '2026-08-05 13:08:21',
        processed_at: null,
        data: null,
        recipients_resolved_count: null,
        target_type: 'all',
        workplaces: [],
        shift: null,
        translations: [
            { id: 1, notification_id: 1, lang: 'en', title: 'English title', content: 'English content' },
            { id: 2, notification_id: 1, lang: 'ja', title: '日本語タイトル', content: '日本語コンテンツ' },
        ],
    };

    it('shows a flag instead of a text label for the base title/content and every translation', () => {
        useGetNotificationsQueryMock.mockReturnValue(
            successQuery({
                data: [baseNotification],
                meta: { current_page: 1, last_page: 1, total: 1 },
            }),
        );

        renderComponent();

        const titleCol = screen.getByTestId('col-title');
        const contentCol = screen.getByTestId('col-content');

        // Base row (Vietnamese) now carries a flag too, not just the translations below it.
        expect(titleCol.textContent).toContain('🇻🇳');
        expect(titleCol.textContent).toContain('Tiêu đề gốc');
        expect(contentCol.textContent).toContain('🇻🇳');
        expect(contentCol.textContent).toContain('Nội dung gốc');

        // Each translation shows its own flag next to its text, no "Tiếng XXX:" label.
        expect(titleCol.textContent).toContain('🇬🇧');
        expect(titleCol.textContent).toContain('English title');
        expect(titleCol.textContent).toContain('🇯🇵');
        expect(titleCol.textContent).toContain('日本語タイトル');
        // The old "Tiếng XXX:" language-name badge is gone; only flags mark each row now.
        expect(titleCol.textContent).not.toMatch(/Tiếng \w+/);

        expect(contentCol.textContent).toContain('🇬🇧');
        expect(contentCol.textContent).toContain('English content');
        expect(contentCol.textContent).toContain('🇯🇵');
        expect(contentCol.textContent).toContain('日本語コンテンツ');
    });

    it('hides fallback translation rows so filler content is not shown as a real translation', () => {
        useGetNotificationsQueryMock.mockReturnValue(
            successQuery({
                data: [
                    {
                        ...baseNotification,
                        translations: [
                            {
                                id: 1,
                                notification_id: 1,
                                lang: 'en',
                                title: 'English title',
                                content: 'English content',
                                is_fallback: false,
                            },
                            {
                                id: 4,
                                notification_id: 1,
                                lang: 'ne',
                                title: 'Tiêu đề gốc',
                                content: 'Nội dung gốc',
                                is_fallback: true,
                            },
                        ],
                    },
                ],
                meta: { current_page: 1, last_page: 1, total: 1 },
            }),
        );

        renderComponent();

        const titleCol = screen.getByTestId('col-title');
        const contentCol = screen.getByTestId('col-content');

        // The genuine English translation still shows.
        expect(titleCol.textContent).toContain('🇬🇧');
        expect(titleCol.textContent).toContain('English title');
        // The Nepali fallback row (filler copied from the base text) is not shown as a translation.
        expect(titleCol.textContent).not.toContain('🇳🇵');
        expect(contentCol.textContent).not.toContain('🇳🇵');
    });

    it('falls back to no flag for an unknown translation locale', () => {
        useGetNotificationsQueryMock.mockReturnValue(
            successQuery({
                data: [
                    {
                        ...baseNotification,
                        translations: [
                            { id: 3, notification_id: 1, lang: 'xx', title: 'Unknown lang title', content: 'c' },
                        ],
                    },
                ],
                meta: { current_page: 1, last_page: 1, total: 1 },
            }),
        );

        renderComponent();

        const titleCol = screen.getByTestId('col-title');
        expect(titleCol.textContent).toContain('Unknown lang title');
        // No known flag matched; the base (Vietnamese) flag is still present from the title row above.
        expect(titleCol.textContent).not.toContain('🇬🇧');
        expect(titleCol.textContent).not.toContain('🇯🇵');
    });

    it('shows the audience of each notification in the target column', () => {
        useGetNotificationsQueryMock.mockReturnValue(
            successQuery({
                data: [
                    baseNotification,
                    {
                        ...baseNotification,
                        id: 2,
                        type: 'bank_account_required',
                        target_type: 'workplace',
                        workplaces: [
                            { id: 11, name: 'Shop A' },
                            { id: 12, name: 'Shop B' },
                        ],
                        recipients_resolved_count: 8,
                        read_count: 3,
                    },
                    { ...baseNotification, id: 3, type: 'recruitment' },
                ],
                meta: { current_page: 1, last_page: 1, total: 3 },
            }),
        );

        renderComponent();

        const targetCols = screen.getAllByTestId('col-target');
        expect(targetCols[0].textContent).toBe('All employees');
        expect(targetCols[1].textContent).toBe('2 workplaces');
        expect(targetCols[2].textContent).toBe('-');

        const readCols = screen.getAllByTestId('col-read_received');
        expect(readCols[0].textContent).toBe('-');
        expect(readCols[1].textContent).toBe('3 / 8');
    });
});
