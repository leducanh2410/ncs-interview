import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/manage/exam-result/[id]/index.page';

const { useGetDetailExamResultQueryMock, useCompleteExternalOverrideMutationMock, usePermissionsMock, routerMock } =
    vi.hoisted(() => ({
        useGetDetailExamResultQueryMock: vi.fn(),
        useCompleteExternalOverrideMutationMock: vi.fn(),
        usePermissionsMock: vi.fn(),
        routerMock: { query: { id: '1' }, isReady: true },
    }));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

// Admin's own UI locale is 'ja' - deliberately different from the
// candidate's saved language, so the test can prove the Google Form link
// follows the candidate, not the viewer.
vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key, i18n: { language: 'ja' } }),
}));

vi.mock('react-icons/lu', () => ({
    LuFileText: () => React.createElement('span', null, 'icon'),
}));

vi.mock('@mantine/core', () => {
    const Passthrough =
        (tag: string) =>
        ({ children }: any) =>
            React.createElement(tag, null, children);
    const Table: any = Passthrough('table');
    Table.Thead = Passthrough('thead');
    Table.Tbody = Passthrough('tbody');
    Table.Tr = Passthrough('tr');
    Table.Th = Passthrough('th');
    Table.Td = Passthrough('td');

    return {
        Anchor: Passthrough('a'),
        Badge: Passthrough('span'),
        Breadcrumbs: Passthrough('nav'),
        Button: ({ children, onClick }: any) => React.createElement('button', { onClick }, children),
        Divider: Passthrough('hr'),
        Flex: Passthrough('div'),
        Group: Passthrough('div'),
        Loader: () => React.createElement('span', null, 'loading'),
        Paper: Passthrough('div'),
        Stack: Passthrough('div'),
        Table,
        Text: Passthrough('span'),
        Title: Passthrough('h1'),
    };
});

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/manage/exam-result/api', () => ({
    useGetDetailExamResultQuery: (...args: unknown[]) => useGetDetailExamResultQueryMock(...args),
    useCompleteExternalOverrideMutation: (...args: unknown[]) => useCompleteExternalOverrideMutationMock(...args),
}));

// Avoids pulling in the real @mantine/notifications package, whose
// Notifications component needs getDefaultZIndex from @mantine/core - an
// export the trimmed-down @mantine/core mock above doesn't provide.
vi.mock('@/helpers/notification.helper', () => ({
    default: { showSuccess: vi.fn(), showError: vi.fn(), showInfo: vi.fn() },
}));

const makeExamResult = (overrides: Record<string, any> = {}) => ({
    id: 1,
    user: { id: 3, name: 'Test User', settings: { lang: 'ne' } },
    exam: {
        id: 7,
        type: 'external_form_prep',
        title: { en: 'Exam title' },
        external_url: {
            en: 'https://docs.google.com/forms/en',
            ja: 'https://docs.google.com/forms/ja',
            ne: 'https://docs.google.com/forms/ne',
        },
    },
    questions: [],
    score: 0,
    correct_answers: 0,
    total_questions: 0,
    status: 'pending_external',
    started_at: null,
    completed_at: null,
    external_completed_at: null,
    ...overrides,
});

describe('Exam result detail page - Google Form link locale', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue({ EXAM_RESULT_LIST: true, EXAM_EDIT: true });
        useCompleteExternalOverrideMutationMock.mockReturnValue({
            mutateAsync: vi.fn(),
            isPending: false,
        });
    });

    it("resolves the Google Form link using the candidate's saved language, not the admin's UI locale", () => {
        useGetDetailExamResultQueryMock.mockReturnValue({
            data: { data: { exam_result: makeExamResult(), questions: [] } },
            isLoading: false,
            refetch: vi.fn(),
        });

        render(<IndexPage />);

        expect(screen.getByText('https://docs.google.com/forms/ne')).toBeInTheDocument();
        expect(screen.queryByText('https://docs.google.com/forms/ja')).not.toBeInTheDocument();
    });

    it("falls back to the admin's UI locale when the candidate has no saved language", () => {
        useGetDetailExamResultQueryMock.mockReturnValue({
            data: {
                data: {
                    exam_result: makeExamResult({ user: { id: 3, name: 'Test User', settings: null } }),
                    questions: [],
                },
            },
            isLoading: false,
            refetch: vi.fn(),
        });

        render(<IndexPage />);

        expect(screen.getByText('https://docs.google.com/forms/ja')).toBeInTheDocument();
    });
});

describe('Exam result detail page - per-question result', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue({ EXAM_RESULT_LIST: true, EXAM_EDIT: true });
        useCompleteExternalOverrideMutationMock.mockReturnValue({
            mutateAsync: vi.fn(),
            isPending: false,
        });
    });

    const questions = [
        {
            id: 1,
            content: { en: 'Question one' },
            options: [
                { id: 11, content: { en: 'One right' }, is_correct: true },
                { id: 12, content: { en: 'One wrong' }, is_correct: false },
            ],
        },
        {
            id: 2,
            content: { en: 'Question two' },
            options: [
                { id: 21, content: { en: 'Two right' }, is_correct: true },
                { id: 22, content: { en: 'Two wrong' }, is_correct: false },
            ],
        },
    ];

    it('uses the server correctness flag and marks answers carried over from a previous attempt', () => {
        useGetDetailExamResultQueryMock.mockReturnValue({
            data: {
                data: {
                    exam_result: makeExamResult({
                        exam: { id: 7, type: 'assessment', title: { en: 'Exam title' } },
                        status: 'completed',
                        questions: [
                            { question_id: 1, question_option_id: 11, is_correct: true, is_carried_over: true },
                            { question_id: 2, question_option_id: 21, is_correct: false, is_carried_over: false },
                        ],
                    }),
                    questions,
                },
            },
            isLoading: false,
            refetch: vi.fn(),
        });

        render(<IndexPage />);

        const rows = screen.getAllByRole('row').slice(1);
        expect(rows[0]).toHaveTextContent('Correct');
        expect(rows[0]).toHaveTextContent('Correct (previous attempt)');
        expect(rows[1]).toHaveTextContent('Incorrect');
        expect(rows[1]).not.toHaveTextContent('Correct (previous attempt)');
    });
});
