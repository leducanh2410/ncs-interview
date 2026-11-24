import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import ImportModal from '@/pages/manage/work-history/edit-requests/partials/import_modal';

const { previewMutateAsyncMock, usePreviewImportMock } = vi.hoisted(() => ({
    previewMutateAsyncMock: vi.fn(),
    usePreviewImportMock: vi.fn(),
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

vi.mock('@/locales/i18n', () => ({
    default: {
        t: (key: string, opts?: any) => {
            if (!opts) return key;
            return key.replace(/{{(\w+)}}/g, (_match: string, name: string) => String(opts[name] ?? ''));
        },
    },
}));

vi.mock('react-icons/lu', () => ({
    LuUpload: () => React.createElement('span', null, 'upload-icon'),
}));

vi.mock('@/components/DAlert', () => ({
    default: ({ type, children }: any) => React.createElement('div', { 'data-testid': `alert-${type}` }, children),
}));

vi.mock('@/pages/manage/work-history/edit-requests/api', () => ({
    usePreviewImportWorkHistoryEditRequestMutation: () => usePreviewImportMock(),
}));

vi.mock('@mantine/core', () => {
    const List: any = ({ children }: any) => React.createElement('ul', null, children);
    List.Item = ({ children }: any) => React.createElement('li', null, children);

    return {
        Box: ({ children, ...rest }: any) => React.createElement('div', rest, children),
        Button: ({ children, onClick, loading }: any) =>
            React.createElement('button', { onClick, disabled: loading }, children),
        FileButton: ({ children, onChange }: any) =>
            React.createElement(React.Fragment, null, [
                React.createElement('input', {
                    'key': 'file-input',
                    'type': 'file',
                    'data-testid': 'file-input',
                    'onChange': (e: any) => onChange(e.target.files?.[0] ?? null),
                }),
                children({}),
            ]),
        List,
        Modal: ({ children, opened, title }: any) =>
            opened
                ? React.createElement(
                      'div',
                      { 'data-testid': 'modal' },
                      React.createElement('h2', null, title),
                      children,
                  )
                : null,
        Stack: ({ children }: any) => React.createElement('div', null, children),
        Text: ({ children, ...rest }: any) => React.createElement('span', rest, children),
    };
});

const selectFile = async (file: File) => {
    const input = screen.getByTestId('file-input');
    await userEvent.upload(input, file);
};

describe('ImportModal', () => {
    beforeEach(() => {
        previewMutateAsyncMock.mockReset();
        usePreviewImportMock.mockReturnValue({ mutateAsync: previewMutateAsyncMock, isPending: false });
    });

    it('renders nothing when closed', () => {
        const { container } = render(<ImportModal opened={false} onClose={vi.fn()} onPreviewReady={vi.fn()} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('always shows the notice that only check-in/check-out/break columns are read', () => {
        render(<ImportModal opened onClose={vi.fn()} onPreviewReady={vi.fn()} />);

        const notice = screen.getByTestId('import-scope-notice');
        expect(notice).toBeInTheDocument();
        expect(notice.textContent).toContain('Giờ vào làm');
        expect(notice.textContent).toContain('Giờ tan làm');
        expect(notice.textContent).toContain('Giờ nghỉ');
    });

    it('calls onPreviewReady with the returned rows on a successful preview', async () => {
        const rows = [{ check_in_id: 1, employee_name: 'Yamada', workplace_name: 'A', date: '2026/8/20', items: [] }];
        previewMutateAsyncMock.mockResolvedValue({ data: { rows } });
        const onPreviewReady = vi.fn();

        render(<ImportModal opened onClose={vi.fn()} onPreviewReady={onPreviewReady} />);
        await selectFile(new File(['x'], 'work_history.xlsx'));

        await waitFor(() => {
            expect(onPreviewReady).toHaveBeenCalledWith(rows);
        });
    });

    it('shows the "no changes" message without calling onPreviewReady', async () => {
        previewMutateAsyncMock.mockRejectedValue(
            new ApiError({ status: 422, message: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_NO_CHANGES', data: {} }),
        );
        const onPreviewReady = vi.fn();

        render(<ImportModal opened onClose={vi.fn()} onPreviewReady={onPreviewReady} />);
        await selectFile(new File(['x'], 'work_history.xlsx'));

        await waitFor(() => {
            expect(screen.getByTestId('alert-info')).toBeInTheDocument();
        });
        expect(onPreviewReady).not.toHaveBeenCalled();
    });

    it('shows a structural header-mismatch message identifying the column, expected, and found values', async () => {
        previewMutateAsyncMock.mockRejectedValue(
            new ApiError({
                status: 422,
                message: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_INVALID_FORMAT',
                data: { reason: 'header_mismatch', column: 'D', expected: 'Mã nhân viên', found: 'Wrong' },
            }),
        );

        render(<ImportModal opened onClose={vi.fn()} onPreviewReady={vi.fn()} />);
        await selectFile(new File(['x'], 'work_history.xlsx'));

        await waitFor(() => {
            const alert = screen.getByTestId('alert-danger');
            expect(alert.textContent).toContain('D');
            expect(alert.textContent).toContain('Mã nhân viên');
            expect(alert.textContent).toContain('Wrong');
        });
    });

    it('lists every row-content error with row, employee, date, and reason', async () => {
        previewMutateAsyncMock.mockRejectedValue(
            new ApiError({
                status: 422,
                message: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR',
                data: {
                    errors: [
                        {
                            row: 3,
                            employee_name: 'Yamada',
                            date: '2026/8/20',
                            reason: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_DUPLICATE',
                        },
                        {
                            row: 5,
                            employee_name: 'Sato',
                            date: '2026/8/21',
                            reason: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ALREADY_PENDING',
                        },
                    ],
                },
            }),
        );

        render(<ImportModal opened onClose={vi.fn()} onPreviewReady={vi.fn()} />);
        await selectFile(new File(['x'], 'work_history.xlsx'));

        await waitFor(() => {
            const alert = screen.getByTestId('alert-danger');
            expect(alert.textContent).toContain('Yamada');
            expect(alert.textContent).toContain('Sato');
        });
    });

    it('explains a check-out-before-check-in row with an actionable message, not "invalid number"', async () => {
        previewMutateAsyncMock.mockRejectedValue(
            new ApiError({
                status: 422,
                message: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR',
                data: {
                    errors: [
                        {
                            row: 3,
                            employee_name: 'Yamada',
                            date: '2026/8/20',
                            reason: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CHECK_OUT_BEFORE_CHECK_IN',
                        },
                    ],
                },
            }),
        );

        render(<ImportModal opened onClose={vi.fn()} onPreviewReady={vi.fn()} />);
        await selectFile(new File(['x'], 'work_history.xlsx'));

        await waitFor(() => {
            const alert = screen.getByTestId('alert-danger');
            expect(alert.textContent).not.toContain('not a valid number');
            expect(alert.textContent).toContain('fall before the start time');
        });
    });

    it('explains an implausibly-long row with an actionable message', async () => {
        previewMutateAsyncMock.mockRejectedValue(
            new ApiError({
                status: 422,
                message: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR',
                data: {
                    errors: [
                        {
                            row: 3,
                            employee_name: 'Yamada',
                            date: '2026/8/20',
                            reason: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_IMPLAUSIBLE_DURATION',
                        },
                    ],
                },
            }),
        );

        render(<ImportModal opened onClose={vi.fn()} onPreviewReady={vi.fn()} />);
        await selectFile(new File(['x'], 'work_history.xlsx'));

        await waitFor(() => {
            const alert = screen.getByTestId('alert-danger');
            expect(alert.textContent).toContain('implausibly long');
        });
    });
});
