import React from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/manage/exam/index.page';
import { ExamResponse } from '@/pages/manage/exam/api';

const {
    commonSearchBoxSpy,
    dataTableSpy,
    useGetExamsQueryMock,
    useDeleteExamMutationMock,
    deleteExamMock,
    usePermissionsMock,
    routerMock,
    refetchMock,
    showSuccessMock,
} = vi.hoisted(() => ({
    commonSearchBoxSpy: vi.fn(),
    dataTableSpy: vi.fn(),
    useGetExamsQueryMock: vi.fn(),
    useDeleteExamMutationMock: vi.fn(),
    deleteExamMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    routerMock: { query: {} as Record<string, unknown>, push: vi.fn() },
    refetchMock: vi.fn(),
    showSuccessMock: vi.fn(),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

vi.mock('react-icons/lu', async (importOriginal) => ({
    ...(await importOriginal<typeof import('react-icons/lu')>()),
    LuPencil: () => React.createElement('span', { 'data-testid': 'icon-pencil' }),
    LuPlus: () => React.createElement('span', { 'data-testid': 'icon-plus' }),
    LuTrash2: () => React.createElement('span', { 'data-testid': 'icon-trash' }),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string) => key,
        i18n: { language: 'en' },
    }),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled }, children),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened }: any) => (opened ? React.createElement('div', null, children) : null),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
    Tooltip: ({ children }: any) => children,
}));

vi.mock('jotai', () => ({
    atom: (initial: unknown) => ({ init: initial }),
    useAtom: () => [
        {
            workplaces: [
                { id: 1, name: 'Tokyo' },
                { id: 2, name: 'Osaka' },
            ],
        },
    ],
}));

vi.mock('@/helpers/date.helper', () => ({
    DATE_STORE_FORMAT_WITH_TIME: 'YYYY-MM-DD HH:mm:ss',
    default: {
        parseAndFormat: (value: string | null) => (value ? `FORMATTED_${value}` : '-'),
    },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showSuccess: (...args: unknown[]) => showSuccessMock(...args) },
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/manage/exam/api', () => ({
    useGetExamsQuery: (...args: unknown[]) => useGetExamsQueryMock(...args),
    useDeleteExamMutation: (...args: unknown[]) => useDeleteExamMutationMock(...args),
}));

vi.mock('@/components/ExamStatusBadge', () => ({
    default: ({ exam }: any) => React.createElement('span', null, `status:${exam.status}`),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) => {
        commonSearchBoxSpy(props);

        return React.createElement(
            'div',
            { 'data-testid': 'exam-search-box' },
            props.elements.map((element: any) =>
                React.createElement('div', { key: element.id }, React.createElement('span', null, element.label)),
            ),
        );
    },
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        dataTableSpy(props);

        const records = Array.isArray(props.data) ? props.data : props.data.data;

        return React.createElement(
            'table',
            { 'data-no-permission': props.noPermission ? 'true' : 'false' },
            React.createElement(
                'tbody',
                null,
                records.map((record: any) =>
                    React.createElement(
                        'tr',
                        { key: record.id },
                        props.columns.map((column: any) =>
                            React.createElement(
                                'td',
                                { key: column.key },
                                column.render ? column.render(record) : record[column.key],
                            ),
                        ),
                    ),
                ),
            ),
        );
    },
}));

const buildExam = (overrides: Partial<ExamResponse> = {}): ExamResponse => ({
    id: 1,
    title: { en: 'Safety training', vi: 'Đào tạo an toàn', ja: '安全教育' },
    languages: ['en'],
    type: 'assessment',
    is_required: 1,
    completion_stats: { eligible_count: 0, completed_count: 0, pending_count: 0, completion_rate: 0 },
    status: 'active',
    start_at: '2026-07-01 09:00:00',
    end_at: '2026-07-31 18:00:00',
    retry_limit: 3,
    min_score: 80,
    time_limit: 30,
    external_url: { en: '', vi: '', ja: '' },
    notified_at: null,
    created_at: '2026-06-01 00:00:00',
    updated_at: '2026-06-01 00:00:00',
    documents: [],
    workplaces: [],
    questions: [],
    ...overrides,
});

describe('Exam index page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = {};

        usePermissionsMock.mockReturnValue({
            EXAM_LIST: true,
            EXAM_EDIT: true,
            EXAM_CREATE: true,
            EXAM_DELETE: true,
        });
        useGetExamsQueryMock.mockReturnValue({ data: { data: [buildExam()] }, isPending: false, refetch: refetchMock });
        useDeleteExamMutationMock.mockReturnValue({ mutateAsync: deleteExamMock });
    });

    it('configures the search box with a searchable workplace filter', () => {
        render(<IndexPage />);

        const searchBox = screen.getByTestId('exam-search-box');
        expect(within(searchBox).getByText('Exam name')).toBeInTheDocument();
        expect(within(searchBox).getByText('Status')).toBeInTheDocument();
        expect(within(searchBox).getByText('Required')).toBeInTheDocument();
        expect(within(searchBox).getByText('Workplace')).toBeInTheDocument();

        expect(commonSearchBoxSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                elements: expect.arrayContaining([
                    expect.objectContaining({
                        id: 'workplace_id',
                        name: 'workplace_id',
                        isSearchable: true,
                        isClearable: false,
                        showAll: true,
                        allValue: '0',
                        options: [
                            { value: '1', label: 'Tokyo' },
                            { value: '2', label: 'Osaka' },
                        ],
                    }),
                ]),
            }),
        );
    });

    it('renders an exam row linking to its detail page', () => {
        render(<IndexPage />);

        expect(screen.getByRole('link', { name: 'Safety training' })).toHaveAttribute('href', '/manage/exam/1');
        expect(screen.getByText('status:active')).toBeInTheDocument();
        expect(screen.getByText('Knowledge assessment')).toBeInTheDocument();
        expect(screen.getByText('FORMATTED_2026-07-01 09:00:00')).toBeInTheDocument();
    });

    it('shows a dash for retry limit and min score on external form prep exams', () => {
        const exam = buildExam({ type: 'external_form_prep' });
        useGetExamsQueryMock.mockReturnValue({
            data: { data: [exam] },
            isPending: false,
            refetch: refetchMock,
        });

        render(<IndexPage />);

        expect(screen.getByText('External form preparation')).toBeInTheDocument();

        const lastCall = dataTableSpy.mock.calls[dataTableSpy.mock.calls.length - 1][0];
        const retryLimitColumn = lastCall.columns.find((column: any) => column.key === 'retry_limit');
        const minScoreColumn = lastCall.columns.find((column: any) => column.key === 'min_score');

        expect(retryLimitColumn.render(exam)).toBe('-');
        expect(minScoreColumn.render(exam)).toBe('-');
    });

    it('passes the router query and permission flag to useGetExamsQuery', () => {
        routerMock.query = { workplace_id: '2' };

        render(<IndexPage />);

        expect(useGetExamsQueryMock).toHaveBeenCalledWith({ workplace_id: '2' }, true);
    });

    it('disables the create button without EXAM_CREATE permission', () => {
        usePermissionsMock.mockReturnValue({
            EXAM_LIST: true,
            EXAM_EDIT: false,
            EXAM_CREATE: false,
            EXAM_DELETE: false,
        });

        render(<IndexPage />);

        expect(screen.getByRole('button', { name: 'Create exam' })).toBeDisabled();
    });

    it('passes the missing permission flag to the data table and disables the exams query', () => {
        usePermissionsMock.mockReturnValue({
            EXAM_LIST: false,
            EXAM_EDIT: false,
            EXAM_CREATE: false,
            EXAM_DELETE: false,
        });

        render(<IndexPage />);

        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ noPermission: true }));
        expect(useGetExamsQueryMock).toHaveBeenCalledWith(expect.anything(), false);
    });

    it('disables the delete action without EXAM_DELETE permission', () => {
        usePermissionsMock.mockReturnValue({
            EXAM_LIST: true,
            EXAM_EDIT: true,
            EXAM_CREATE: true,
            EXAM_DELETE: false,
        });

        render(<IndexPage />);

        expect(screen.getByTestId('icon-trash').closest('button')).toBeDisabled();
    });

    it('deletes the exam and refetches after confirming', async () => {
        const user = userEvent.setup();
        deleteExamMock.mockResolvedValue({});

        render(<IndexPage />);

        await user.click(screen.getByTestId('icon-trash').closest('button') as HTMLElement);
        await user.click(screen.getByRole('button', { name: 'Delete' }));

        expect(deleteExamMock).toHaveBeenCalledWith('1');
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully deleted exam');
        expect(refetchMock).toHaveBeenCalled();
    });

    it('closes the delete confirmation without deleting when Cancel is clicked', async () => {
        const user = userEvent.setup();

        render(<IndexPage />);

        await user.click(screen.getByTestId('icon-trash').closest('button') as HTMLElement);
        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(deleteExamMock).not.toHaveBeenCalled();
        expect(screen.queryByText('Are you sure you want to delete this exam?')).not.toBeInTheDocument();
    });
});
