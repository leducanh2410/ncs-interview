import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/admin/user-paper-reconciliation/index.page';

const { useGetPaperReconciliationQueryMock, routerMock } = vi.hoisted(() => ({
    useGetPaperReconciliationQueryMock: vi.fn(),
    routerMock: { query: {} as Record<string, unknown>, pathname: '/admin/user-paper-reconciliation', push: vi.fn() },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: any) => React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key),
    }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick }: any) => React.createElement('button', { onClick }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@/components/DDataTable', () => ({
    headerWrapClassName: 'header-wrap',
    default: ({ data, columns }: any) => {
        const records = Array.isArray(data) ? data : (data?.data ?? []);
        return React.createElement(
            'table',
            null,
            React.createElement(
                'tbody',
                null,
                records.map((record: any) =>
                    React.createElement(
                        'tr',
                        { key: record.user.id },
                        columns.map((col: any) =>
                            React.createElement('td', { key: col.key }, col.render ? col.render(record) : null),
                        ),
                    ),
                ),
            ),
        );
    },
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => React.createElement('span', null, user.name),
}));

vi.mock('@/pages/admin/user-paper-reconciliation/partials/comparison_modal', () => ({
    default: () => null,
}));

vi.mock('@/pages/admin/user-paper-reconciliation/partials/filters', () => ({
    default: () => null,
}));

vi.mock('@/pages/admin/user-paper-reconciliation/partials/verdict_badge', () => ({
    default: ({ verdict }: any) => React.createElement('span', null, verdict),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (value: string | null) => (value ? `formatted:${value}` : '-') },
}));

vi.mock('@/pages/admin/user-paper-reconciliation/api', () => ({
    useGetPaperReconciliationQuery: (...args: unknown[]) => useGetPaperReconciliationQueryMock(...args),
}));

const makeRow = (overrides: Record<string, any> = {}) => ({
    user: { id: 19, staff_no: 'DGF1014', name: 'MAGAR PRAMILA', profile_picture: null },
    employee_scan: null,
    admin_scan: {
        id: 7,
        file_path: 'admin-paper-uploads/7.jpg',
        submitted_at: '2026-09-14T11:05:38+09:00',
        submitted_by: { id: 5, name: 'TANAKA MIKA' },
        confirmed_without_ocr: false,
    },
    verdict: 'matched',
    mismatch_count: 0,
    employee_residence_missing: false,
    ...overrides,
});

const renderWithRows = (rows: Record<string, any>[]) => {
    useGetPaperReconciliationQueryMock.mockReturnValue({
        data: { data: { data: rows, total: rows.length } },
        isPending: false,
    });

    return render(<IndexPage />);
};

describe('AdminPaperReconciliationPage', () => {
    it('links the scanning admin to their account page', () => {
        renderWithRows([makeRow()]);

        expect(screen.getByText('TANAKA MIKA').closest('a')?.getAttribute('href')).toBe('/admin/user/5');
    });

    it('leaves a deleted submitter as plain text, since there is no account left to open', () => {
        renderWithRows([makeRow({ admin_scan: { ...makeRow().admin_scan, submitted_by: null } })]);

        expect(screen.getByText('Deleted admin').closest('a')).toBeNull();
    });

    it('names a submitter whose account row no longer resolves without linking to it', () => {
        renderWithRows([makeRow({ admin_scan: { ...makeRow().admin_scan, submitted_by: { id: 5, name: null } } })]);

        expect(screen.getByText('Deleted admin').closest('a')).toBeNull();
    });

    it('shows the yellow badge rather than a link when nobody has scanned the employee', () => {
        renderWithRows([makeRow({ admin_scan: null })]);

        expect(screen.getByText('No admin scan yet')).toBeInTheDocument();
    });

    it('no longer prints its own empty message under the table', () => {
        renderWithRows([]);

        // The page used to print a second "no employee matches..." line beneath a table
        // that already draws its own empty state, so an admin who filtered down to
        // nothing was told twice. DDataTable is mocked here, so this covers only the
        // half that belongs to this page.
        expect(screen.queryByText(/No employee/i)).not.toBeInTheDocument();
    });
});
