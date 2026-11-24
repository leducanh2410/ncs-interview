import React from 'react';
import { MantineProvider } from '@mantine/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DateHelper from '@/helpers/date.helper';
import ResultsPage from './index.page';

// _app.page.tsx registers the dayjs utc/timezone plugins; this page is rendered without it.
DateHelper.initDayjs();

const { usePollQueryMock, usePollResponsesQueryMock, routerState } = vi.hoisted(() => ({
    usePollQueryMock: vi.fn(),
    usePollResponsesQueryMock: vi.fn(),
    routerState: { query: { id: '1' } as Record<string, string>, push: vi.fn() },
}));

vi.mock('@/pages/manage/poll/api', () => ({
    usePollQuery: (...args: unknown[]) => usePollQueryMock(...args),
    usePollResponsesQuery: (...args: unknown[]) => usePollResponsesQueryMock(...args),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, options?: Record<string, unknown>) =>
            options?.number !== undefined ? key.replace('{{number}}', String(options.number)) : key,
        i18n: { language: 'en' },
    }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { language: 'en', t: (key: string) => key },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => ({
        query: routerState.query,
        push: routerState.push,
        pathname: '/manage/poll/1/results',
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

// Mock DDataTable to render columns with data
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

// Mock DAvatar
vi.mock('@/components/DAvatar', () => ({
    __esModule: true,
    default: ({ name }: { name: string }) => <span data-testid="avatar">{name}</span>,
}));

const pendingQuery = () => ({
    data: undefined,
    isLoading: true,
    isError: false,
    isPending: true,
    refetch: vi.fn(),
});

const successQuery = (data: any) => ({
    data,
    isLoading: false,
    isError: false,
    isPending: false,
    refetch: vi.fn(),
});

const makeVotePoll = () => ({
    id: 1,
    type: 'vote' as const,
    target_type: 'global',
    is_active: true,
    title_vi: 'Bình chọn',
    title_en: 'Vote Title',
    title_ja: '投票',
    options: [10, 20],
    result_counters: {
        '10': { yes: 5, no: 2, blank: 1 },
        '20': { yes: 3, no: 4, blank: 0 },
    },
    candidate_users: [
        { id: 10, name: 'Alice', profile_picture: null },
        { id: 20, name: 'Bob', profile_picture: null },
    ],
    responses_count: 8,
    created_at: '2025-01-01',
    updated_at: '2025-01-01',
});

const makeFeedbackPoll = () => ({
    id: 2,
    type: 'feedback' as const,
    target_type: 'global',
    is_active: false,
    title_vi: 'Khảo sát',
    title_en: 'Feedback Title',
    title_ja: 'フィードバック',
    options: [
        { id: 'c1', label_vi: 'Tùy chọn A', label_en: 'Option A', label_ja: 'オプションA' },
        { id: 'c2', label_vi: 'Tùy chọn B', label_en: 'Option B', label_ja: 'オプションB' },
    ],
    result_counters: {
        c1: 10,
        c2: 5,
    },
    candidate_users: [],
    responses_count: 15,
    created_at: '2025-01-01',
    updated_at: '2025-01-01',
});

describe('ResultsPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerState.query = { id: '1' };
    });

    const renderComponent = () => {
        return render(
            <MantineProvider>
                <ResultsPage />
            </MantineProvider>,
        );
    };

    it('shows loading skeleton when poll is pending', () => {
        usePollQueryMock.mockReturnValue(pendingQuery());
        usePollResponsesQueryMock.mockReturnValue(pendingQuery());

        renderComponent();

        // Breadcrumbs should still render
        expect(screen.getByText('Home')).toBeInTheDocument();
        expect(screen.getByText('Vote/Feedback')).toBeInTheDocument();
        expect(screen.getByText('Results')).toBeInTheDocument();
    });

    it('renders vote results with candidate cards', () => {
        const poll = makeVotePoll();
        usePollQueryMock.mockReturnValue(successQuery({ data: poll }));
        usePollResponsesQueryMock.mockReturnValue(successQuery({ data: { data: [], total: 8 } }));

        renderComponent();

        // Title and badges
        expect(screen.getByText('Vote Title')).toBeInTheDocument();
        expect(screen.getByText('Active')).toBeInTheDocument();
        expect(screen.getByText(/Total responses/)).toBeInTheDocument();

        // Candidate names (rendered in both the avatar mock and the name text)
        expect(screen.getAllByText('Alice').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Bob').length).toBeGreaterThan(0);

        // Vote counters
        expect(screen.getByText('Yes: 5')).toBeInTheDocument();
        expect(screen.getByText('No: 2')).toBeInTheDocument();
        expect(screen.getByText('Blank: 1')).toBeInTheDocument();
        expect(screen.getByText('Yes: 3')).toBeInTheDocument();
        expect(screen.getByText('No: 4')).toBeInTheDocument();
    });

    it('renders feedback results with choice bars', () => {
        const poll = makeFeedbackPoll();
        usePollQueryMock.mockReturnValue(successQuery({ data: poll }));
        usePollResponsesQueryMock.mockReturnValue(successQuery({ data: { data: [], total: 15 } }));

        renderComponent();

        // Title and badges
        expect(screen.getByText('Feedback Title')).toBeInTheDocument();
        expect(screen.getByText('Inactive')).toBeInTheDocument();

        // Choice labels (en language)
        expect(screen.getByText('Option A')).toBeInTheDocument();
        expect(screen.getByText('Option B')).toBeInTheDocument();

        // Counts
        expect(screen.getByText('10')).toBeInTheDocument();
        expect(screen.getByText('5')).toBeInTheDocument();
    });

    it('renders vote response rows in the datatable', () => {
        const poll = makeVotePoll();
        const responses = [
            {
                id: 1,
                user_id: 100,
                poll_id: 1,
                answer: { '10': 1, '20': -1 },
                ip_address: '192.168.1.1',
                user: { id: 100, name: 'Respondent 1' },
                created_at: '2025-06-01 10:00:00',
                updated_at: '2025-06-01 10:00:00',
            },
        ];

        usePollQueryMock.mockReturnValue(successQuery({ data: poll }));
        usePollResponsesQueryMock.mockReturnValue(successQuery({ data: { data: responses, total: 1 } }));

        renderComponent();

        // Row data rendered through mock DDataTable
        expect(screen.getByText('Respondent 1')).toBeInTheDocument();
        expect(screen.getByText('192.168.1.1')).toBeInTheDocument();

        // Vote response badges: Alice: Yes, Bob: No
        expect(screen.getByText(/Alice.*Yes/)).toBeInTheDocument();
        expect(screen.getByText(/Bob.*No/)).toBeInTheDocument();
    });

    it('renders feedback response rows in the datatable', () => {
        const poll = makeFeedbackPoll();
        const responses = [
            {
                id: 1,
                user_id: 200,
                poll_id: 2,
                answer: ['c1', 'c2'],
                ip_address: '10.0.0.1',
                user: { id: 200, name: 'Feedback User' },
                created_at: '2025-06-02 14:30:00',
                updated_at: '2025-06-02 14:30:00',
            },
        ];

        usePollQueryMock.mockReturnValue(successQuery({ data: poll }));
        usePollResponsesQueryMock.mockReturnValue(successQuery({ data: { data: responses, total: 1 } }));

        renderComponent();

        expect(screen.getByText('Feedback User')).toBeInTheDocument();
        expect(screen.getByText('10.0.0.1')).toBeInTheDocument();

        // Feedback choice badges (also shown in the aggregated results cards)
        expect(screen.getAllByText('Option A').length).toBeGreaterThan(1);
        expect(screen.getAllByText('Option B').length).toBeGreaterThan(1);
    });

    it('handles missing candidate user gracefully', () => {
        const poll = makeVotePoll();
        // Add an option with a user ID that doesn't exist in candidate_users
        (poll.options as number[]).push(999);
        (poll.result_counters as any)['999'] = { yes: 0, no: 0, blank: 0 };

        usePollQueryMock.mockReturnValue(successQuery({ data: poll }));
        usePollResponsesQueryMock.mockReturnValue(successQuery({ data: { data: [], total: 0 } }));

        renderComponent();

        // Fallback text for unknown user
        expect(screen.getByText('User #999')).toBeInTheDocument();
    });

    it('renders zero total responses', () => {
        const poll = makeVotePoll();
        usePollQueryMock.mockReturnValue(successQuery({ data: poll }));
        usePollResponsesQueryMock.mockReturnValue(successQuery({ data: { data: [], total: 0 } }));

        renderComponent();

        // Total responses counter should show 0
        expect(screen.getByText(/Total responses/)).toBeInTheDocument();
    });

    it('handles vote results with zero total (no progress bar)', () => {
        const poll = makeVotePoll();
        // Set all counters to 0
        poll.result_counters = {
            '10': { yes: 0, no: 0, blank: 0 },
            '20': { yes: 0, no: 0, blank: 0 },
        };

        usePollQueryMock.mockReturnValue(successQuery({ data: poll }));
        usePollResponsesQueryMock.mockReturnValue(successQuery({ data: { data: [], total: 0 } }));

        renderComponent();

        // Counter text should still render
        expect(screen.getAllByText('Yes: 0')).toHaveLength(2);
        expect(screen.getAllByText('No: 0')).toHaveLength(2);
        expect(screen.getAllByText('Blank: 0')).toHaveLength(2);
    });

    it('hides the occurrence selector for a one-time poll without later rounds', () => {
        usePollQueryMock.mockReturnValue(
            successQuery({ data: { ...makeFeedbackPoll(), is_recurring: false, available_occurrences: [1] } }),
        );
        usePollResponsesQueryMock.mockReturnValue(successQuery({ data: { data: [], total: 0 } }));

        renderComponent();

        expect(screen.queryByLabelText('Occurrence')).not.toBeInTheDocument();
    });

    it('filters a recurring poll by the occurrence in the URL and shows the round columns', () => {
        routerState.query = { id: '1', occurrence: '2' };
        usePollQueryMock.mockReturnValue(
            successQuery({ data: { ...makeFeedbackPoll(), is_recurring: true, available_occurrences: [1, 2] } }),
        );
        usePollResponsesQueryMock.mockReturnValue(
            successQuery({
                data: {
                    data: [
                        {
                            id: 5,
                            user_id: 9,
                            answer: ['c1'],
                            occurrence: 2,
                            workplace: { id: 3, name: 'Factory A' },
                            created_at: '2026-09-11 10:00:00',
                        },
                    ],
                    total: 1,
                },
            }),
        );

        renderComponent();

        expect(usePollQueryMock).toHaveBeenCalledWith('1', '2');
        expect(usePollResponsesQueryMock).toHaveBeenCalledWith('1', { id: '1', occurrence: '2' }, true);
        expect(screen.getAllByLabelText('Occurrence').length).toBeGreaterThan(0);
        expect(screen.getByText('Factory A')).toBeInTheDocument();
    });

    it('updates the URL when another occurrence is selected', async () => {
        usePollQueryMock.mockReturnValue(
            successQuery({ data: { ...makeFeedbackPoll(), is_recurring: true, available_occurrences: [1, 2] } }),
        );
        usePollResponsesQueryMock.mockReturnValue(successQuery({ data: { data: [], total: 0 } }));

        renderComponent();

        fireEvent.click(screen.getAllByLabelText('Occurrence')[0]);
        fireEvent.click(await screen.findByText('Round 2'));

        expect(routerState.push).toHaveBeenCalledWith(
            { pathname: '/manage/poll/1/results', query: { id: '1', occurrence: '2' } },
            undefined,
            { shallow: true },
        );
    });
});
