import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExamResultIndexPage from '@/pages/manage/exam-result/index.page';

const { exportExamResultMock, showErrorMock, useGetAllExamResultQueryMock, usePermissionsMock, routerMock } =
    vi.hoisted(() => ({
        exportExamResultMock: vi.fn(),
        showErrorMock: vi.fn(),
        useGetAllExamResultQueryMock: vi.fn(),
        usePermissionsMock: vi.fn(),
        routerMock: { query: {} as Record<string, string> },
    }));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key, i18n: { language: 'en' } }),
}));

vi.mock('react-icons/lu', () => ({
    LuFileSpreadsheet: () => React.createElement('span', null, 'icon'),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href, component: _c }: any) => React.createElement('a', { href }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled, loading, leftSection: _l }: any) =>
        React.createElement(
            'button',
            { onClick, 'disabled': disabled || loading, 'data-loading': !!loading },
            children,
        ),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/manage/exam-result/api', () => ({
    useGetAllExamResultQuery: (...args: unknown[]) => useGetAllExamResultQueryMock(...args),
    exportExamResult: (...args: unknown[]) => exportExamResultMock(...args),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) =>
        React.createElement(
            'div',
            { 'data-testid': 'search-box' },
            props.elements.map((el: any) =>
                React.createElement('span', { 'key': el.id, 'data-testid': `filter-${el.id}` }, el.label),
            ),
        ),
    userRenderItem: vi.fn(),
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        const records: any[] = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);
        return React.createElement(
            'table',
            null,
            React.createElement(
                'tbody',
                null,
                records.map((record: any) =>
                    React.createElement(
                        'tr',
                        { key: record.id },
                        props.columns.map((col: any) =>
                            React.createElement(
                                'td',
                                { key: col.key },
                                col.render ? col.render(record) : record[col.key],
                            ),
                        ),
                    ),
                ),
            ),
        );
    },
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => React.createElement('span', null, user?.name),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (v: string) => v ?? '' },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showError: (...args: unknown[]) => showErrorMock(...args) },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: (_route: string, params: any) => `/path/${params?.id ?? ''}` },
}));

vi.mock('@/configs/api.config', () => ({
    ApiConfig: { ADMIN_MASTER_USER_SEARCH: '/api/users/search' },
}));

vi.mock('@/configs/route.config', () => ({
    default: { HOME: { path: '/' } },
}));

const makeExamResult = (overrides: Record<string, any> = {}) => ({
    id: 1,
    user: { id: 3, name: 'Test User' },
    exam: { id: 7, title: { en: 'Exam title', vi: 'Bai thi' } },
    score: 80,
    correct_answers: 8,
    total_questions: 10,
    status: 'completed',
    completed_at: '2026-07-20 09:30:00',
    ...overrides,
});

const makePaginated = (items: any[]) => ({
    data: { data: items, current_page: 1, last_page: 1, per_page: 15, total: items.length },
});

describe('Exam result index page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = {};

        usePermissionsMock.mockReturnValue({ EXAM_RESULT_LIST: true, EXAM_RESULT_EXPORT: true });
        useGetAllExamResultQueryMock.mockReturnValue({
            data: makePaginated([makeExamResult()]),
            isPending: false,
        });
    });

    it('exports with the current search filters', async () => {
        routerMock.query = { status: 'completed', title: 'Exam' };
        exportExamResultMock.mockResolvedValue(undefined);

        render(<ExamResultIndexPage />);
        fireEvent.click(screen.getByRole('button', { name: /Export CSV/ }));

        await waitFor(() => expect(exportExamResultMock).toHaveBeenCalledWith({ status: 'completed', title: 'Exam' }));
        expect(showErrorMock).not.toHaveBeenCalled();
    });

    it('blocks a second export while the first is still in flight', async () => {
        let resolveExport: () => void = () => {};
        exportExamResultMock.mockReturnValue(
            new Promise<void>((resolve) => {
                resolveExport = resolve;
            }),
        );

        render(<ExamResultIndexPage />);
        const button = screen.getByRole('button', { name: /Export CSV/ });

        fireEvent.click(button);
        await waitFor(() => expect(button).toBeDisabled());

        // Further clicks while the request is pending must not fire another export.
        fireEvent.click(button);
        fireEvent.click(button);
        expect(exportExamResultMock).toHaveBeenCalledTimes(1);

        resolveExport();
        await waitFor(() => expect(button).not.toBeDisabled());
    });

    it('re-enables the export button after a failed export', async () => {
        exportExamResultMock.mockRejectedValue(new Error('boom'));

        render(<ExamResultIndexPage />);
        const button = screen.getByRole('button', { name: /Export CSV/ });
        fireEvent.click(button);

        await waitFor(() => expect(showErrorMock).toHaveBeenCalled());
        await waitFor(() => expect(button).not.toBeDisabled());
    });

    it('shows an error notification when the export fails', async () => {
        exportExamResultMock.mockRejectedValue(new Error('boom'));

        render(<ExamResultIndexPage />);
        fireEvent.click(screen.getByRole('button', { name: /Export CSV/ }));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|Could not export exam result'));
    });

    it('disables the export button without the export permission', () => {
        usePermissionsMock.mockReturnValue({ EXAM_RESULT_LIST: true, EXAM_RESULT_EXPORT: false });

        render(<ExamResultIndexPage />);

        expect(screen.getByRole('button', { name: /Export CSV/ })).toBeDisabled();
    });

    it('disables the export button when there is nothing to export', () => {
        useGetAllExamResultQueryMock.mockReturnValue({ data: makePaginated([]), isPending: false });

        render(<ExamResultIndexPage />);

        expect(screen.getByRole('button', { name: /Export CSV/ })).toBeDisabled();
    });
});
