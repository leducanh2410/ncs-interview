import React from 'react';
import { MantineProvider } from '@mantine/core';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ManagePollPage from './index.page';

const {
    usePollsQueryMock,
    usePollQueryMock,
    useDeletePollMutationMock,
    useActivatePollMutationMock,
    useDeactivatePollMutationMock,
} = vi.hoisted(() => ({
    usePollsQueryMock: vi.fn(),
    usePollQueryMock: vi.fn().mockReturnValue({ data: null }),
    useDeletePollMutationMock: vi.fn().mockReturnValue({ mutateAsync: vi.fn(), isPending: false }),
    useActivatePollMutationMock: vi.fn().mockReturnValue({ mutateAsync: vi.fn(), isPending: false }),
    useDeactivatePollMutationMock: vi.fn().mockReturnValue({ mutateAsync: vi.fn(), isPending: false }),
}));

vi.mock('@/pages/manage/poll/api', () => ({
    usePollsQuery: (...args: unknown[]) => usePollsQueryMock(...args),
    usePollQuery: (...args: unknown[]) => usePollQueryMock(...args),
    useDeletePollMutation: () => useDeletePollMutationMock(),
    useActivatePollMutation: () => useActivatePollMutationMock(),
    useDeactivatePollMutation: () => useDeactivatePollMutationMock(),
    useCreatePollMutation: () => ({ mutateAsync: vi.fn() }),
    useUpdatePollMutation: () => ({ mutateAsync: vi.fn() }),
}));

vi.mock('@/components/DDataTable', () => ({
    __esModule: true,
    default: ({ columns, data }: any) => {
        const records = Array.isArray(data) ? data : data?.data || [];
        return (
            <div data-testid="mock-datatable">
                {records.length === 0 ? (
                    <div>No data</div>
                ) : (
                    records.map((row: any, i: number) => (
                        <div key={i} data-testid="mock-row">
                            {columns.map((c: any, j: number) => (
                                <span key={j}>{c.render ? c.render(row) : row[c.key]}</span>
                            ))}
                        </div>
                    ))
                )}
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

describe('ManagePollPage', () => {
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
                    <ManagePollPage />
                </MantineProvider>
            </QueryClientProvider>,
        );
    };

    it('renders the page and list of polls', async () => {
        usePollsQueryMock.mockReturnValue(
            successQuery({
                data: [
                    {
                        id: 1,
                        title_en: 'Test Poll 1',
                        type: 'vote',
                        target_type: 'global',
                        is_active: true,
                        is_required: true,
                    },
                    {
                        id: 2,
                        title_en: 'Test Poll 2',
                        type: 'feedback',
                        target_type: 'specific_users',
                        is_active: false,
                        is_required: false,
                    },
                ],
                meta: { current_page: 1, last_page: 1, total: 2 },
            }),
        );

        renderComponent();

        expect(screen.getAllByText('Vote/Feedback').length).toBeGreaterThan(0);
        expect(screen.getByText('Create poll')).toBeInTheDocument();
        expect(await screen.findByText('Test Poll 1')).toBeInTheDocument();
    });

    it('renders empty state when no polls', () => {
        usePollsQueryMock.mockReturnValue(
            successQuery({
                data: [],
                meta: { current_page: 1, last_page: 1, total: 0 },
            }),
        );

        renderComponent();
        expect(screen.queryByText('Test Poll 1')).not.toBeInTheDocument();
    });

    it('opens create modal on clicking create button', async () => {
        usePollsQueryMock.mockReturnValue(
            successQuery({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } }),
        );
        renderComponent();
        const createBtn = screen.getByText('Create poll');
        fireEvent.click(createBtn);
        const dialog = await screen.findByRole('dialog');
        expect(dialog).toBeInTheDocument();
        expect(within(dialog).getByText('Create poll')).toBeInTheDocument();
    });

    it('handles target_type rendering properly', async () => {
        usePollsQueryMock.mockReturnValue(
            successQuery({
                data: [
                    { id: 1, target_type: 'specific_users', type: 'vote' },
                    { id: 2, target_type: 'workplace', workplace: { name: 'WP1' }, type: 'feedback' },
                    { id: 3, target_type: 'workplace', type: 'vote' },
                    { id: 4, target_type: null, type: 'feedback' },
                ],
                meta: { current_page: 1, last_page: 1, total: 4 },
            }),
        );
        renderComponent();
        expect(await screen.findByText('Specific Users')).toBeInTheDocument();
        expect(await screen.findByText('WP1')).toBeInTheDocument();
        expect(await screen.findAllByText('Workplace')).toHaveLength(1);
        expect(await screen.findAllByText('Global')).toHaveLength(1);
    });

    it('handles title fallbacks', () => {
        usePollsQueryMock.mockReturnValue(
            successQuery({
                data: [
                    { id: 1, title_vi: 'VI Title', type: 'vote' },
                    { id: 2, title_ja: 'JA Title', type: 'vote' },
                ],
                meta: { current_page: 1, last_page: 1, total: 2 },
            }),
        );
        renderComponent();
        expect(screen.getByText('VI Title')).toBeInTheDocument();
        expect(screen.getByText('JA Title')).toBeInTheDocument();
    });

    it('handles activate and deactivate actions', async () => {
        const activateMock = vi.fn().mockResolvedValue({});
        const deactivateMock = vi.fn().mockResolvedValue({});
        useActivatePollMutationMock.mockReturnValue({ mutateAsync: activateMock, isPending: false });
        useDeactivatePollMutationMock.mockReturnValue({ mutateAsync: deactivateMock, isPending: false });

        usePollsQueryMock.mockReturnValue(
            successQuery({
                data: [
                    { id: 1, is_active: true, type: 'vote' },
                    { id: 2, is_active: false, type: 'vote' },
                ],
                meta: { current_page: 1, last_page: 1, total: 2 },
            }),
        );
        renderComponent();

        const deactivateBtn = await screen.findByTestId('deactivate-btn');
        const activateBtn = await screen.findByTestId('activate-btn');

        fireEvent.click(deactivateBtn);
        expect(deactivateMock).toHaveBeenCalledWith('1');

        fireEvent.click(activateBtn);
        expect(activateMock).toHaveBeenCalledWith('2');

        deactivateMock.mockRejectedValue(new Error('Failed'));
        activateMock.mockRejectedValue(new Error('Failed'));
        fireEvent.click(deactivateBtn);
        fireEvent.click(activateBtn);
    });

    it('handles delete action', async () => {
        const deleteMock = vi.fn().mockResolvedValue({});
        useDeletePollMutationMock.mockReturnValue({ mutateAsync: deleteMock, isPending: false });

        usePollsQueryMock.mockReturnValue(
            successQuery({
                data: [
                    { id: 1, responses_count: 0, type: 'vote' },
                    { id: 2, responses_count: 5, type: 'vote' },
                ],
                meta: { current_page: 1, last_page: 1, total: 2 },
            }),
        );
        renderComponent();

        const deleteBtns = await screen.findAllByTestId('delete-btn');
        expect(deleteBtns[1]).toBeDisabled();

        fireEvent.click(deleteBtns[0]);

        expect(
            await screen.findByText('Are you sure you want to delete this poll? This action cannot be undone.'),
        ).toBeInTheDocument();

        const confirmBtn = (await screen.findByText('Delete poll', { selector: 'button *' })).closest('button');
        expect(confirmBtn).not.toBeNull();
        fireEvent.click(confirmBtn!);
        expect(deleteMock).toHaveBeenCalledWith('1');

        fireEvent.click(deleteBtns[0]);
        const cancelBtn = (await screen.findByText('Cancel', { selector: 'button *' })).closest('button');
        expect(cancelBtn).not.toBeNull();
        fireEvent.click(cancelBtn!);

        // Test failure path
        deleteMock.mockRejectedValueOnce(new Error('Failed'));
        fireEvent.click(deleteBtns[0]);
        const confirmBtnFail = (await screen.findByText('Delete poll', { selector: 'button *' })).closest('button');
        expect(confirmBtnFail).not.toBeNull();
        fireEvent.click(confirmBtnFail!);

        await waitFor(() => {
            expect(deleteMock).toHaveBeenCalledTimes(2);
        });
    });

    it('handles edit action', async () => {
        usePollsQueryMock.mockReturnValue(
            successQuery({
                data: [{ id: 1, type: 'vote' }],
                meta: { current_page: 1, last_page: 1, total: 1 },
            }),
        );
        usePollQueryMock.mockReturnValue({
            data: { data: { id: 'test', title_en: 'Edit Poll', type: 'vote', options: [] } },
        });

        renderComponent();

        const editBtn = await screen.findByTestId('edit-btn');
        fireEvent.click(editBtn);

        const dialog = await screen.findByRole('dialog');
        expect(dialog).toBeInTheDocument();
        expect(within(dialog).getByText('Edit poll')).toBeInTheDocument();

        // Close modal
        const cancelModalBtn = within(dialog).getByText('Cancel');
        fireEvent.click(cancelModalBtn);
        await waitFor(() => {
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });
    });
});
