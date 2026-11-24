import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import ImportReviewModal from '@/pages/manage/work-history/edit-requests/partials/import_review_modal';
import { ImportPreviewRow } from '@/pages/manage/work-history/edit-requests/api';

const { commitMutateAsyncMock, useCommitImportMock, showSuccessMock, showErrorMock } = vi.hoisted(() => ({
    commitMutateAsyncMock: vi.fn(),
    useCommitImportMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, opts?: any) => {
            if (!opts) return key;
            return key.replace(/{{(\w+)}}/g, (_match: string, name: string) => String(opts[name] ?? ''));
        },
    }),
}));

vi.mock('react-icons/lu', () => ({
    LuArrowRight: () => React.createElement('span', null, 'arrow-icon'),
    LuSave: () => React.createElement('span', null, 'save-icon'),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parseAndFormat: (value: string | null) => value ?? '',
    },
    DATE_STORE_FORMAT_WITH_TIME: 'YYYY-MM-DD HH:mm:ss',
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showSuccess: showSuccessMock, showError: showErrorMock },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: {
        getPath: (routeName: string, params: Record<string, string | number>) => `/${routeName}/${params.id}`,
    },
}));

vi.mock('@/components/DAvatar', () => ({
    default: ({ name }: any) => React.createElement('span', { 'data-testid': 'avatar' }, name),
}));

vi.mock('@/pages/manage/work-history/edit-requests/api', () => ({
    useCommitImportWorkHistoryEditRequestMutation: () => useCommitImportMock(),
}));

vi.mock('@mantine/core', () => {
    const Table: any = ({ children }: any) => React.createElement('table', null, children);
    Table.Thead = ({ children }: any) => React.createElement('thead', null, children);
    Table.Tbody = ({ children }: any) => React.createElement('tbody', null, children);
    Table.Tr = ({ children }: any) => React.createElement('tr', null, children);
    Table.Th = ({ children }: any) => React.createElement('th', null, children);
    Table.Td = ({ children }: any) => React.createElement('td', null, children);

    return {
        Anchor: ({ children, href, ...rest }: any) => React.createElement('a', { href, ...rest }, children),
        Button: ({ children, onClick, disabled, loading }: any) =>
            React.createElement('button', { onClick, disabled: disabled || loading }, children),
        Flex: ({ children }: any) => React.createElement('div', null, children),
        Modal: ({ children, title }: any) =>
            React.createElement('div', { 'data-testid': 'modal' }, React.createElement('h2', null, title), children),
        Table,
        Text: ({ children, span }: any) => React.createElement(span ? 'span' : 'div', null, children),
        Textarea: ({ value, onChange, error, placeholder }: any) =>
            React.createElement('div', null, [
                React.createElement('textarea', {
                    'key': 'input',
                    'aria-label': placeholder,
                    'value': value ?? '',
                    'onChange': (e: any) => onChange?.(e),
                }),
                error ? React.createElement('span', { key: 'err', role: 'alert' }, error) : null,
            ]),
    };
});

const row: ImportPreviewRow = {
    check_in_id: 1,
    user_id: 42,
    employee_name: 'Yamada Taro',
    employee_avatar: null,
    workplace_id: 7,
    workplace_name: 'Tokyo Office',
    date: '2026/8/20',
    items: [{ field: 'check_in_time', old_value: '2026-08-20 09:00:00', new_value: '2026-08-20 08:45:00' }],
};

describe('ImportReviewModal', () => {
    beforeEach(() => {
        commitMutateAsyncMock.mockReset();
        showSuccessMock.mockReset();
        showErrorMock.mockReset();
        useCommitImportMock.mockReturnValue({ mutateAsync: commitMutateAsyncMock, isPending: false });
    });

    it('renders nothing when there are no rows', () => {
        const { container } = render(<ImportReviewModal rows={[]} onClose={vi.fn()} onConfirmed={vi.fn()} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('shows an avatar and opens the employee/workplace detail pages in a new tab', () => {
        render(<ImportReviewModal rows={[row]} onClose={vi.fn()} onConfirmed={vi.fn()} />);

        expect(screen.getByTestId('avatar')).toHaveTextContent('Yamada Taro');

        const employeeLink = screen.getByRole('link', { name: 'Yamada Taro' });
        expect(employeeLink).toHaveAttribute('href', '/ADMIN_USER_DETAIL/42');
        expect(employeeLink).toHaveAttribute('target', '_blank');

        const workplaceLink = screen.getByRole('link', { name: 'Tokyo Office' });
        expect(workplaceLink).toHaveAttribute('href', '/ADMIN_WORKPLACE_DETAIL/7');
        expect(workplaceLink).toHaveAttribute('target', '_blank');
    });

    it('defaults every row to apply and confirms without requiring any input', async () => {
        commitMutateAsyncMock.mockResolvedValue({ data: { applied_count: 1, rejected_count: 0 } });
        const onConfirmed = vi.fn();

        render(<ImportReviewModal rows={[row]} onClose={vi.fn()} onConfirmed={onConfirmed} />);

        expect(screen.getByRole('button', { name: 'Confirm and process' })).not.toBeDisabled();
        await userEvent.click(screen.getByRole('button', { name: 'Confirm and process' }));

        await waitFor(() => {
            expect(commitMutateAsyncMock).toHaveBeenCalledWith([
                { check_in_id: 1, items: row.items, decision: 'apply', reject_reason: undefined },
            ]);
        });
        expect(onConfirmed).toHaveBeenCalled();
    });

    it('disables confirm until a rejected row has a reason, then submits the reject decision', async () => {
        commitMutateAsyncMock.mockResolvedValue({ data: { applied_count: 0, rejected_count: 1 } });

        render(<ImportReviewModal rows={[row]} onClose={vi.fn()} onConfirmed={vi.fn()} />);

        await userEvent.click(screen.getByRole('button', { name: 'Reject' }));
        expect(screen.getByRole('button', { name: 'Confirm and process' })).toBeDisabled();

        await userEvent.type(screen.getByRole('textbox', { name: 'Reject reason' }), 'Sai định dạng');
        expect(screen.getByRole('button', { name: 'Confirm and process' })).not.toBeDisabled();

        await userEvent.click(screen.getByRole('button', { name: 'Confirm and process' }));

        await waitFor(() => {
            expect(commitMutateAsyncMock).toHaveBeenCalledWith([
                { check_in_id: 1, items: row.items, decision: 'reject', reject_reason: 'Sai định dạng' },
            ]);
        });
    });

    it('shows an error notification and does not call onConfirmed when the commit fails', async () => {
        commitMutateAsyncMock.mockRejectedValue(new Error('conflict'));
        const onConfirmed = vi.fn();

        render(<ImportReviewModal rows={[row]} onClose={vi.fn()} onConfirmed={onConfirmed} />);
        await userEvent.click(screen.getByRole('button', { name: 'Confirm and process' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalled();
        });
        expect(onConfirmed).not.toHaveBeenCalled();
    });

    it('names the specific row that aborted the commit when the API reports its identity', async () => {
        commitMutateAsyncMock.mockRejectedValue(
            new ApiError({
                status: 409,
                message: 'WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED',
                data: { check_in_id: 1, employee_name: 'Yamada Taro', date: '2026/8/20' },
            }),
        );

        render(<ImportReviewModal rows={[row]} onClose={vi.fn()} onConfirmed={vi.fn()} />);
        await userEvent.click(screen.getByRole('button', { name: 'Confirm and process' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalled();
        });
        const [message] = showErrorMock.mock.calls[0];
        expect(message).toContain('Yamada Taro');
        expect(message).toContain('2026/8/20');
    });

    it('falls back to the generic message for WORK_HISTORY_EDIT_REQUEST_NOT_PENDING with no row identity', async () => {
        commitMutateAsyncMock.mockRejectedValue(
            new ApiError({ status: 409, message: 'WORK_HISTORY_EDIT_REQUEST_NOT_PENDING', data: {} }),
        );

        render(<ImportReviewModal rows={[row]} onClose={vi.fn()} onConfirmed={vi.fn()} />);
        await userEvent.click(screen.getByRole('button', { name: 'Confirm and process' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith(
                'error|Could not process the import - some data may have changed, please re-import',
            );
        });
    });

    it('names the specific row for WORK_HISTORY_EDIT_REQUEST_NOT_PENDING when the API reports its identity', async () => {
        commitMutateAsyncMock.mockRejectedValue(
            new ApiError({
                status: 409,
                message: 'WORK_HISTORY_EDIT_REQUEST_NOT_PENDING',
                data: { check_in_id: 1, employee_name: 'Yamada Taro', date: '2026/8/20' },
            }),
        );

        render(<ImportReviewModal rows={[row]} onClose={vi.fn()} onConfirmed={vi.fn()} />);
        await userEvent.click(screen.getByRole('button', { name: 'Confirm and process' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalled();
        });
        const [message] = showErrorMock.mock.calls[0];
        expect(message).toContain('Yamada Taro');
        expect(message).toContain('2026/8/20');
    });

    it('shows a permission-specific message instead of "data may have changed" for a 403', async () => {
        commitMutateAsyncMock.mockRejectedValue(
            new ApiError({
                status: 403,
                message: 'E_FORBIDDEN',
                data: { check_in_id: 1, employee_name: 'Yamada Taro', date: '2026/8/20' },
            }),
        );

        render(<ImportReviewModal rows={[row]} onClose={vi.fn()} onConfirmed={vi.fn()} />);
        await userEvent.click(screen.getByRole('button', { name: 'Confirm and process' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalled();
        });
        const [message] = showErrorMock.mock.calls[0];
        expect(message).not.toContain('may have changed');
        expect(message).toContain('Yamada Taro');
    });

    it('shows a not-found message naming the aborting row for a 404 that only carries check_in_id', async () => {
        const other: ImportPreviewRow = {
            ...row,
            check_in_id: 2,
            user_id: 43,
            employee_name: 'Sato Hanako',
            date: '2026/8/21',
        };
        commitMutateAsyncMock.mockRejectedValue(
            new ApiError({ status: 404, message: 'E_NOT_FOUND', data: { check_in_id: 2 } }),
        );

        render(<ImportReviewModal rows={[row, other]} onClose={vi.fn()} onConfirmed={vi.fn()} />);
        await userEvent.click(screen.getByRole('button', { name: 'Confirm and process' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalled();
        });
        const [message] = showErrorMock.mock.calls[0];
        expect(message).not.toContain('may have changed');
        expect(message).toContain('Sato Hanako');
        expect(message).toContain('2026/8/21');
        expect(message).not.toContain('Yamada Taro');
    });

    it('shows an already-pending message instead of "data may have changed" when another request beat it', async () => {
        commitMutateAsyncMock.mockRejectedValue(
            new ApiError({
                status: 409,
                message: 'WORK_HISTORY_EDIT_REQUEST_ALREADY_PENDING',
                data: { check_in_id: 1, employee_name: 'Yamada Taro', date: '2026/8/20' },
            }),
        );

        render(<ImportReviewModal rows={[row]} onClose={vi.fn()} onConfirmed={vi.fn()} />);
        await userEvent.click(screen.getByRole('button', { name: 'Confirm and process' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalled();
        });
        const [message] = showErrorMock.mock.calls[0];
        expect(message).not.toContain('may have changed');
        expect(message).toContain('already has another request pending');
    });
});
