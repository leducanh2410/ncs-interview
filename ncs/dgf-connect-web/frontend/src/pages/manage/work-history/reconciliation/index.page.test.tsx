import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import dayjs from 'dayjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import IndexPage from '@/pages/manage/work-history/reconciliation/index.page';

const {
    useGetReconciliationBatchesQueryMock,
    useCreateReconciliationBatchMutationMock,
    usePermissionsMock,
    commonSearchBoxSpy,
    downloadReconciliationOriginalMock,
} = vi.hoisted(() => ({
    useGetReconciliationBatchesQueryMock: vi.fn(),
    useCreateReconciliationBatchMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    commonSearchBoxSpy: vi.fn(),
    downloadReconciliationOriginalMock: vi.fn(),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => ({ query: {}, push: vi.fn(), isReady: true }),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href, onClick }: any) => React.createElement('a', { href, onClick }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled }: any) => React.createElement('button', { onClick, disabled }, children),
    FileButton: ({ children, onChange }: any) =>
        React.createElement(
            React.Fragment,
            null,
            children({ onClick: vi.fn() }),
            React.createElement(
                'button',
                { 'data-testid': 'pick-file', 'onClick': () => onChange(new File(['x'], 'amazon.xlsx')) },
                'pick',
            ),
        ),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ opened, children, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'upload-modal' }, title, children) : null,
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Select: ({ label }: any) => React.createElement('div', null, label),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) => {
        commonSearchBoxSpy(props);
        return React.createElement('div', { 'data-testid': 'search-box' });
    },
    userRenderItem: (user: any) => ({ value: String(user.id), label: user.name }),
}));

vi.mock('@/components/DDataTable', () => ({
    headerWrapClassName: 'header-wrap',
    default: ({ noPermission, columns, data }: any) =>
        React.createElement(
            'div',
            { 'data-testid': 'table', 'data-no-permission': String(noPermission) },
            (columns ?? []).map((column: { key: string; name: string }) =>
                React.createElement('span', { key: column.key }, column.name),
            ),
            (Array.isArray(data) ? data : (data?.data ?? [])).map((record: any) =>
                React.createElement(
                    'div',
                    { 'key': record.id, 'data-testid': `batch-row-${record.id}` },
                    (columns ?? []).map((column: { key: string; render?: (row: any) => React.ReactNode }) =>
                        React.createElement(
                            'div',
                            { 'key': column.key, 'data-column': column.key },
                            column.render ? column.render(record) : record[column.key],
                        ),
                    ),
                ),
            ),
        ),
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user, isLink = true }: any) =>
        React.createElement(
            'a',
            { 'data-testid': `avatar-${user.id}`, 'href': isLink ? `/users/${user.id}` : undefined },
            user.name,
        ),
}));

vi.mock('@/pages/manage/work-history/reconciliation/api', () => ({
    useGetReconciliationBatchesQuery: (...args: unknown[]) => useGetReconciliationBatchesQueryMock(...args),
    useCreateReconciliationBatchMutation: () => useCreateReconciliationBatchMutationMock(),
    downloadReconciliationOriginal: (...args: unknown[]) => downloadReconciliationOriginalMock(...args),
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showError: vi.fn(), showSuccess: vi.fn() },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: { getColor: () => 'gray', getText: (_e: unknown, v: string) => v },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (v: string) => v, now: () => dayjs() },
    DATE_STORE_FORMAT_WITH_TIME: 'YYYY-MM-DD HH:mm:ss',
}));

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: () => '/x' },
}));

const batchRecord = {
    id: 3,
    source: 'amazon_kadou',
    original_filename: 'amazon_20260901.xlsx',
    company_label: null,
    status: 'draft',
    punch_count: 94,
    skipped_non_punch_count: 171,
    matched_count: 1,
    differs_count: 2,
    unmatched_count: 3,
    manual_map_count: 0,
    uploaded_by: { id: 7, name: 'UPLOADER NAME', staff_no: '1001', profile_picture: null },
    created_at: '2026-09-23 10:00:00',
    confirmed_by: null,
    confirmed_at: null,
    exported_by: null,
    exported_at: null,
};

describe('reconciliation list', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        useGetReconciliationBatchesQueryMock.mockReturnValue({
            data: { data: { data: [], total: 0 } },
            isPending: false,
        });
        useCreateReconciliationBatchMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: false });
        usePermissionsMock.mockReturnValue({ RECONCILIATION_WORK: true });
    });

    it('renders list when permitted', () => {
        render(React.createElement(IndexPage));
        expect(screen.getByRole('heading', { name: 'Attendance reconciliation' })).toBeInTheDocument();
        expect(screen.getByTestId('table')).toHaveAttribute('data-no-permission', 'false');
    });

    it('hides write action permission on table when missing', () => {
        usePermissionsMock.mockReturnValue({ RECONCILIATION_WORK: false });
        render(React.createElement(IndexPage));
        expect(screen.getByTestId('table')).toHaveAttribute('data-no-permission', 'true');
    });

    it('opens upload modal from new reconciliation', () => {
        render(React.createElement(IndexPage));
        fireEvent.click(screen.getByText('New reconciliation'));
        expect(screen.getByTestId('upload-modal')).toBeInTheDocument();
        expect(screen.getByText('Choose file')).toBeInTheDocument();
        expect(screen.getByText('No file selected')).toBeInTheDocument();
        expect(screen.getByText('Start reconciliation')).toBeDisabled();
    });

    it('shows the uploader with avatar and a link to their profile', () => {
        usePermissionsMock.mockReturnValue({ RECONCILIATION_WORK: true, USER_LIST: true });
        useGetReconciliationBatchesQueryMock.mockReturnValue({
            data: { data: { data: [batchRecord], total: 1 } },
            isPending: false,
        });
        render(React.createElement(IndexPage));

        const avatar = screen.getByTestId('avatar-7');
        expect(avatar).toHaveTextContent('UPLOADER NAME');
        expect(avatar).toHaveAttribute('href', '/users/7');
    });

    it('links the last exporter name to their profile without an avatar', () => {
        usePermissionsMock.mockReturnValue({ RECONCILIATION_WORK: true, USER_LIST: true });
        useGetReconciliationBatchesQueryMock.mockReturnValue({
            data: {
                data: {
                    data: [
                        {
                            ...batchRecord,
                            exported_at: '2026-09-23 15:51:00',
                            exported_by: { id: 9, name: 'EXPORTER NAME', staff_no: null, profile_picture: null },
                        },
                    ],
                    total: 1,
                },
            },
            isPending: false,
        });
        render(React.createElement(IndexPage));

        expect(screen.getByText('EXPORTER NAME').closest('a')).toHaveAttribute('href', '/x');
        expect(screen.queryByTestId('avatar-9')).not.toBeInTheDocument();
    });

    it('shows uploader and exporter names without links when the viewer cannot open user profiles', () => {
        usePermissionsMock.mockReturnValue({ RECONCILIATION_WORK: true, USER_LIST: false });
        useGetReconciliationBatchesQueryMock.mockReturnValue({
            data: {
                data: {
                    data: [
                        {
                            ...batchRecord,
                            exported_at: '2026-09-23 15:51:00',
                            exported_by: { id: 9, name: 'EXPORTER NAME', staff_no: null, profile_picture: null },
                        },
                    ],
                    total: 1,
                },
            },
            isPending: false,
        });
        render(React.createElement(IndexPage));

        expect(screen.getByTestId('avatar-7')).not.toHaveAttribute('href');
        expect(screen.getByText(/EXPORTER NAME/).closest('a')).toBeNull();
    });

    it('downloads the original file when the file name is clicked', async () => {
        downloadReconciliationOriginalMock.mockResolvedValue(undefined);
        useGetReconciliationBatchesQueryMock.mockReturnValue({
            data: { data: { data: [batchRecord], total: 1 } },
            isPending: false,
        });
        render(React.createElement(IndexPage));

        fireEvent.click(screen.getByText('amazon_20260901.xlsx'));

        await waitFor(() => expect(downloadReconciliationOriginalMock).toHaveBeenCalledWith(3, 'amazon_20260901.xlsx'));
    });

    it('filters by uploader and shows history counts plus last export', () => {
        render(React.createElement(IndexPage));
        expect(commonSearchBoxSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                elements: expect.arrayContaining([
                    expect.objectContaining({ id: 'source', type: 'select' }),
                    expect.objectContaining({
                        id: 'uploaded_by',
                        type: 'select-remote',
                        uri: 'manage/work-history-reconciliations/users',
                    }),
                    expect.objectContaining({ id: 'dates', type: 'date-range' }),
                ]),
            }),
        );
        expect(screen.getByText('Skipped')).toBeInTheDocument();
        expect(screen.getByText('Counts')).toBeInTheDocument();
        expect(screen.getByText('Last export')).toBeInTheDocument();
    });

    // The parser reports which row it choked on and why; collapsing all of that
    // into one generic message leaves an admin with a several-hundred-row
    // workbook nothing to act on.
    describe.each([
        [
            'a formula it cannot evaluate',
            { message: 'RECONCILIATION_INVALID_FORMAT', data: { reason: 'invalid_formula', details: { row: 6 } } },
            'Row {{row}} of the file contains a formula that could not be read',
        ],
        [
            'a punch row with no name',
            { message: 'RECONCILIATION_INVALID_FORMAT', data: { reason: 'missing_name', details: { row: 12 } } },
            'Row {{row}} of the file has punch times but no employee name',
        ],
        [
            'an unreadable work date',
            { message: 'RECONCILIATION_INVALID_FORMAT', data: { reason: 'invalid_work_date', details: { row: 9 } } },
            'Row {{row}} of the file has no readable work date',
        ],
        [
            'a time or duration outside the valid range',
            {
                message: 'RECONCILIATION_INVALID_FORMAT',
                data: { reason: 'invalid_time', details: { row: 12, column: 'H' } },
            },
            'Row {{row}}, column {{column}} of the file has a time or duration outside the valid range',
        ],
        [
            'several sheets with the same headers',
            {
                message: 'RECONCILIATION_INVALID_FORMAT',
                data: { reason: 'multiple_sheets', details: { sheets: ['Week 1', 'Week 2'] } },
            },
            'The file has several attendance sheets ({{sheets}}). Keep only the sheet to reconcile and upload again',
        ],
        [
            'a file that belongs to another source',
            {
                message: 'RECONCILIATION_INVALID_FORMAT',
                data: { reason: 'header_mismatch', detected_source: 'yamato_staff_data' },
            },
            'This file is a {{detected}} file, but {{selected}} is selected. Choose {{detected}} and upload again',
        ],
        [
            'headers that do not match',
            { message: 'RECONCILIATION_INVALID_FORMAT', data: { reason: 'header_mismatch' } },
            'The uploaded file does not match the selected source format',
        ],
        [
            'no punch rows at all',
            { message: 'RECONCILIATION_NO_PUNCH_ROWS', data: {} },
            'The uploaded file has no punch-log rows to reconcile',
        ],
    ])('upload rejected because of %s', (_name, error, expectedMessage) => {
        it(`explains it with "${expectedMessage}"`, async () => {
            const mutateAsync = vi.fn().mockRejectedValue(new ApiError({ status: 422, ...error } as any));
            useCreateReconciliationBatchMutationMock.mockReturnValue({ mutateAsync, isPending: false });
            render(React.createElement(IndexPage));

            fireEvent.click(screen.getByText('New reconciliation'));
            fireEvent.click(screen.getByTestId('pick-file'));
            fireEvent.click(screen.getByText('Start reconciliation'));

            await waitFor(() => expect(mutateAsync).toHaveBeenCalled());
            const NotificationHelper = (await import('@/helpers/notification.helper')).default;
            expect(NotificationHelper.showError).toHaveBeenCalledWith(expectedMessage);
        });
    });

    it('falls back to the generic message for an error the API did not classify', async () => {
        const mutateAsync = vi.fn().mockRejectedValue(new Error('network down'));
        useCreateReconciliationBatchMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        render(React.createElement(IndexPage));

        fireEvent.click(screen.getByText('New reconciliation'));
        fireEvent.click(screen.getByTestId('pick-file'));
        fireEvent.click(screen.getByText('Start reconciliation'));

        await waitFor(() => expect(mutateAsync).toHaveBeenCalled());
        const NotificationHelper = (await import('@/helpers/notification.helper')).default;
        expect(NotificationHelper.showError).toHaveBeenCalledWith('Could not upload the file');
    });
});
