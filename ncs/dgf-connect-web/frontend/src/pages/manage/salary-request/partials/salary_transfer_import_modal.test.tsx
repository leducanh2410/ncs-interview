import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import SalaryTransferImportModal from '@/pages/manage/salary-request/partials/salary_transfer_import_modal';

const { mutateAsyncMock, showSuccessMock, showErrorMock, resetFileInputMock, translate } = vi.hoisted(() => ({
    mutateAsyncMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
    resetFileInputMock: vi.fn(),
    translate: (key: string, params?: Record<string, unknown>) =>
        params ? key.replace(/{{(\w+)}}/g, (_m: string, name: string) => String(params[name] ?? '')) : key,
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: translate }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: translate },
}));

vi.mock('react-icons/lu', () => ({
    LuUpload: () => React.createElement('span', null, 'upload-icon'),
}));

vi.mock('@mantine/core', () => {
    const Radio: any = ({ label, value, checked, onChange }: any) =>
        React.createElement(
            'label',
            null,
            React.createElement('input', { 'type': 'radio', value, checked, onChange, 'aria-label': label }),
            label,
        );
    Radio.Group = ({ label, value, onChange, children }: any) =>
        React.createElement(
            'fieldset',
            null,
            React.createElement('legend', null, label),
            React.Children.map(children, (child: any) =>
                React.cloneElement(child, {
                    checked: child.props.value === value,
                    onChange: () => onChange(child.props.value),
                }),
            ),
        );

    return {
        Box: ({ children }: any) => React.createElement('div', null, children),
        Button: ({ children, onClick, loading }: any) =>
            React.createElement('button', { type: 'button', onClick, disabled: loading }, children),
        FileButton: ({ children, onChange, resetRef }: any) => {
            if (resetRef) {
                resetRef.current = resetFileInputMock;
            }
            return React.createElement(
                React.Fragment,
                null,
                React.createElement('input', {
                    'type': 'file',
                    'aria-label': 'file-input',
                    'onChange': (e: React.ChangeEvent<HTMLInputElement>) => onChange(e.target.files?.[0] ?? null),
                }),
                children({}),
            );
        },
        Modal: ({ children, opened, title, onClose }: any) =>
            opened
                ? React.createElement(
                      'div',
                      { 'data-testid': 'modal' },
                      React.createElement('h2', null, title),
                      React.createElement('button', { type: 'button', onClick: onClose }, 'close-modal'),
                      children,
                  )
                : null,
        Radio,
        Stack: ({ children }: any) => React.createElement('div', null, children),
    };
});

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        toSelectArray: () => [
            { value: 'utf-8', label: 'UTF-8' },
            { value: 'shift-jis', label: 'Shift-JIS' },
        ],
    },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/components/DAlert', () => ({
    default: ({ children }: any) => React.createElement('div', { role: 'alert' }, children),
}));

vi.mock('@/pages/manage/salary-request/api', () => ({
    useImportSalaryTransferMutation: () => ({ mutateAsync: mutateAsyncMock, isPending: false }),
}));

const uploadFile = async (file = new File(['bank'], 'transfer.csv', { type: 'text/csv' })) => {
    await userEvent.upload(screen.getByLabelText('file-input'), file);
};

describe('SalaryTransferImportModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mutateAsyncMock.mockResolvedValue({ data: null });
    });

    it('renders nothing while closed', () => {
        const { container } = render(<SalaryTransferImportModal opened={false} onClose={vi.fn()} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('defaults to Shift-JIS, the encoding the bank returns the sheet in', () => {
        render(<SalaryTransferImportModal opened onClose={vi.fn()} />);

        expect(screen.getByRole('radio', { name: 'Shift-JIS' })).toBeChecked();
        expect(screen.getByRole('radio', { name: 'UTF-8' })).not.toBeChecked();
    });

    it('uploads the file with the selected encoding', async () => {
        render(<SalaryTransferImportModal opened onClose={vi.fn()} />);
        await userEvent.click(screen.getByRole('radio', { name: 'UTF-8' }));

        await uploadFile();

        await waitFor(() => expect(mutateAsyncMock).toHaveBeenCalledTimes(1));
        const data = mutateAsyncMock.mock.calls[0][0] as FormData;
        expect(data.get('encoding')).toBe('utf-8');
        expect(data.get('_method')).toBe('POST');
        expect((data.get('file') as File).name).toBe('transfer.csv');
        // The bank sheet covers whatever months the requests drew from, so no month is sent.
        expect(data.get('month')).toBeNull();
    });

    it('reports success and closes the modal once the file went through', async () => {
        const onClose = vi.fn();
        render(<SalaryTransferImportModal opened onClose={onClose} />);

        await uploadFile();

        await waitFor(() => expect(showSuccessMock).toHaveBeenCalledWith('Successfully imported CSV file'));
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('translates a malformed-file rejection into its own message', async () => {
        mutateAsyncMock.mockRejectedValue(new ApiError({ status: 400, message: 'SALARY_CSV_MALFORMED', data: {} }));
        render(<SalaryTransferImportModal opened onClose={vi.fn()} />);

        await uploadFile();

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|CSV file is malformed'));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('lists every rejected line with a message for its transfer error code', async () => {
        mutateAsyncMock.mockRejectedValue(
            new ApiError({
                status: 422,
                message: 'E_UNPROCESSABLE_ENTITY',
                data: {
                    error_data: [
                        { line: 2, error_code: 'SALARY_TRANSFER_CSV_INVALID_STAFF_NO' },
                        { line: 3, error_code: 'SALARY_TRANSFER_CSV_INVALID_AMOUNT' },
                        { line: 4, error_code: 'SALARY_TRANSFER_CSV_REQUEST_NOT_FOUND' },
                        { line: 5, error_code: 'SALARY_TRANSFER_CSV_AMOUNT_NOT_MATCH' },
                        { line: 6, error_code: 'SALARY_TRANSFER_CSV_INVALID_TIME' },
                        { line: 7, error_code: 'SALARY_TRANSFER_CSV_AMOUNT_TOO_HIGH' },
                        { line: 8, error_code: 'SOMETHING_NEW' },
                    ],
                },
            }),
        );
        render(<SalaryTransferImportModal opened onClose={vi.fn()} />);

        await uploadFile();

        await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
        expect(screen.getByText('Line 2: error|Invalid staff number')).toBeInTheDocument();
        expect(screen.getByText('Line 3: error|Invalid amount')).toBeInTheDocument();
        expect(screen.getByText('Line 4: error|Salary request not found')).toBeInTheDocument();
        expect(screen.getByText('Line 5: error|Amount does not match')).toBeInTheDocument();
        expect(screen.getByText('Line 6: error|Invalid time format')).toBeInTheDocument();
        expect(screen.getByText('Line 7: error|Amount is too high')).toBeInTheDocument();
        expect(screen.getByText('Line 8: error|Unknown error')).toBeInTheDocument();
    });

    it('shows only the generic message when a 422 carries no lines at all', async () => {
        mutateAsyncMock.mockRejectedValue(
            new ApiError({ status: 422, message: 'E_UNPROCESSABLE_ENTITY', data: { error_data: [] } }),
        );
        render(<SalaryTransferImportModal opened onClose={vi.fn()} />);

        await uploadFile();

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|Could not import CSV file'));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('clears the line errors when a new file is picked', async () => {
        mutateAsyncMock.mockRejectedValueOnce(
            new ApiError({
                status: 422,
                message: 'E_UNPROCESSABLE_ENTITY',
                data: { error_data: [{ line: 2, error_code: 'SALARY_TRANSFER_CSV_INVALID_TIME' }] },
            }),
        );
        render(<SalaryTransferImportModal opened onClose={vi.fn()} />);
        await uploadFile();
        await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());

        await uploadFile(new File(['fixed'], 'fixed.csv', { type: 'text/csv' }));

        await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
    });

    it('does nothing when the file dialog was dismissed without a file', async () => {
        render(<SalaryTransferImportModal opened onClose={vi.fn()} />);

        // userEvent.upload cannot deliver an empty selection, so fire the change directly.
        fireEvent.change(screen.getByLabelText('file-input'), { target: { files: [] } });

        await waitFor(() => expect(mutateAsyncMock).not.toHaveBeenCalled());
        expect(showErrorMock).not.toHaveBeenCalled();
    });

    it('still reports a failure that is not an API error', async () => {
        mutateAsyncMock.mockRejectedValue(new Error('offline'));
        render(<SalaryTransferImportModal opened onClose={vi.fn()} />);

        await uploadFile();

        // Silence would read as success and invite a second upload of the same file.
        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|Could not import CSV file'));
        expect(showSuccessMock).not.toHaveBeenCalled();
        expect(resetFileInputMock).toHaveBeenCalled();
    });
});
