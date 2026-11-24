import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import SalaryDeductionImportModal from '@/pages/manage/salary/partials/salary_deduction_import_modal';

const { mutateAsyncMock, showSuccessMock, showErrorMock, showInfoMock, resetFileInputMock, translate } = vi.hoisted(
    () => ({
        mutateAsyncMock: vi.fn(),
        showSuccessMock: vi.fn(),
        showErrorMock: vi.fn(),
        showInfoMock: vi.fn(),
        resetFileInputMock: vi.fn(),
        /** Substitutes {{placeholders}} so the per-line error strings can be asserted verbatim. */
        translate: (key: string, params?: Record<string, unknown>) =>
            params ? key.replace(/{{(\w+)}}/g, (_m: string, name: string) => String(params[name] ?? '')) : key,
    }),
);

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: translate }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: translate },
}));

vi.mock('react-icons/lu', () => ({
    LuCalendar: () => React.createElement('span', null, 'calendar-icon'),
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

vi.mock('@mantine/dates', () => ({
    // Mantine hands back either a string or a Date depending on how the month was chosen, so
    // the mock exposes both: typing into the field, and a button standing in for a click in
    // the calendar popover.
    MonthPickerInput: ({ label, value, onChange }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', {
                'aria-label': label,
                'value': value ? 'picked' : '',
                'onChange': (e: React.ChangeEvent<HTMLInputElement>) => onChange(e.target.value),
            }),
            React.createElement(
                'button',
                { type: 'button', onClick: () => onChange(new Date('2026-01-15T00:00:00Z')) },
                'pick-month-in-calendar',
            ),
        ),
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        toSelectArray: () => [
            { value: 'utf-8', label: 'UTF-8' },
            { value: 'shift-jis', label: 'Shift-JIS' },
        ],
    },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        now: () => ({ subtract: () => ({ toDate: () => new Date('2026-04-01T00:00:00Z') }) }),
        // Mirrors the real Y-m formatting so a month change is visible in the payload.
        format: (value: Date) => value.toISOString().slice(0, 7),
        parse: (value: string) => ({ toDate: () => new Date(`${value}-01T00:00:00Z`) }),
    },
    DATE_STORE_FORMAT_MONTH: 'YYYY-MM',
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
        showInfo: (...args: unknown[]) => showInfoMock(...args),
    },
}));

vi.mock('@/components/DAlert', () => ({
    default: ({ children }: any) => React.createElement('div', { role: 'alert' }, children),
}));

vi.mock('@/pages/manage/salary-request/api', () => ({
    useImportSalaryDeductionMutation: () => ({ mutateAsync: mutateAsyncMock, isPending: false }),
}));

const csvFile = () => new File(['staff_no'], 'deduction.csv', { type: 'text/csv' });

const uploadFile = async (file: File = csvFile()) => {
    await userEvent.upload(screen.getByLabelText('file-input'), file);
};

const sentFormData = () => mutateAsyncMock.mock.calls[0][0] as FormData;

describe('SalaryDeductionImportModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mutateAsyncMock.mockResolvedValue({
            data: { error_data: [], valid_data: { '0042': { total_deductions: 1000 } }, skipped_data: [] },
        });
    });

    it('renders nothing while closed', () => {
        const { container } = render(<SalaryDeductionImportModal opened={false} onClose={vi.fn()} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('defaults to Shift-JIS, the encoding the payroll system exports', () => {
        render(<SalaryDeductionImportModal opened onClose={vi.fn()} />);

        expect(screen.getByRole('radio', { name: 'Shift-JIS' })).toBeChecked();
        expect(screen.getByRole('radio', { name: 'UTF-8' })).not.toBeChecked();
    });

    it('uploads the file with the month and the selected encoding', async () => {
        render(<SalaryDeductionImportModal opened onClose={vi.fn()} />);
        await userEvent.click(screen.getByRole('radio', { name: 'UTF-8' }));

        await uploadFile();

        await waitFor(() => expect(mutateAsyncMock).toHaveBeenCalledTimes(1));
        const data = sentFormData();
        expect(data.get('encoding')).toBe('utf-8');
        expect(data.get('month')).toBe('2026-04');
        expect(data.get('_method')).toBe('POST');
        expect((data.get('file') as File).name).toBe('deduction.csv');
    });

    it('reports success and closes the modal once the file went through', async () => {
        const onClose = vi.fn();
        render(<SalaryDeductionImportModal opened onClose={onClose} />);

        await uploadFile();

        await waitFor(() => expect(showSuccessMock).toHaveBeenCalledWith('Successfully imported CSV file'));
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(resetFileInputMock).toHaveBeenCalled();
    });

    it('keeps the modal open and lists the rows the import left alone', async () => {
        mutateAsyncMock.mockResolvedValue({
            data: {
                error_data: [],
                valid_data: {},
                skipped_data: [
                    { line: 3, staff_no: '0042' },
                    { line: 7, staff_no: null },
                ],
            },
        });
        const onClose = vi.fn();
        render(<SalaryDeductionImportModal opened onClose={onClose} />);

        await uploadFile();

        // Nothing was written and no month settled, so calling it a success would contradict
        // what happened - the list says which rows were left out.
        await waitFor(() => expect(showInfoMock).toHaveBeenCalledWith('No deduction was applied from this CSV file'));
        expect(showSuccessMock).not.toHaveBeenCalled();
        expect(
            screen.getByText('Line 3 (0042): salary for this month has not been calculated yet'),
        ).toBeInTheDocument();
        expect(screen.getByText('Line 7 (-): salary for this month has not been calculated yet')).toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();
    });

    it('reports success when some rows were applied, and still lists the rest', async () => {
        mutateAsyncMock.mockResolvedValue({
            data: {
                error_data: [],
                valid_data: { '0042': { total_deductions: 1000 } },
                skipped_data: [{ line: 7, staff_no: '0043' }],
            },
        });
        const onClose = vi.fn();
        render(<SalaryDeductionImportModal opened onClose={onClose} />);

        await uploadFile();

        await waitFor(() => expect(showSuccessMock).toHaveBeenCalledWith('Successfully imported CSV file'));
        expect(showInfoMock).not.toHaveBeenCalled();
        expect(
            screen.getByText('Line 7 (0043): salary for this month has not been calculated yet'),
        ).toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();
    });

    it('closes as usual when nothing was left out', async () => {
        mutateAsyncMock.mockResolvedValue({
            data: { error_data: [], valid_data: { '0042': { total_deductions: 1000 } }, skipped_data: [] },
        });
        const onClose = vi.fn();
        render(<SalaryDeductionImportModal opened onClose={onClose} />);

        await uploadFile();

        await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('does nothing when the file dialog was dismissed without a file', async () => {
        render(<SalaryDeductionImportModal opened onClose={vi.fn()} />);

        // userEvent.upload cannot deliver an empty selection, so fire the change directly.
        fireEvent.change(screen.getByLabelText('file-input'), { target: { files: [] } });

        await waitFor(() => expect(mutateAsyncMock).not.toHaveBeenCalled());
        expect(showErrorMock).not.toHaveBeenCalled();
    });

    it('sends the month the picker handed back as a Date object', async () => {
        render(<SalaryDeductionImportModal opened onClose={vi.fn()} />);

        await userEvent.click(screen.getByRole('button', { name: 'pick-month-in-calendar' }));
        await uploadFile();

        await waitFor(() => expect(mutateAsyncMock).toHaveBeenCalledTimes(1));
        expect(sentFormData().get('month')).toBe('2026-01');
    });

    it('sends the month the admin picked rather than the default', async () => {
        render(<SalaryDeductionImportModal opened onClose={vi.fn()} />);

        // The picker hands back a whole month string in one go, never character by character.
        fireEvent.change(screen.getByLabelText('Month'), { target: { value: '2026-02' } });
        await uploadFile();

        await waitFor(() => expect(mutateAsyncMock).toHaveBeenCalledTimes(1));
        expect(sentFormData().get('month')).toBe('2026-02');
    });

    it('translates a malformed-file rejection into its own message', async () => {
        mutateAsyncMock.mockRejectedValue(new ApiError({ status: 400, message: 'SALARY_CSV_MALFORMED', data: {} }));
        const onClose = vi.fn();
        render(<SalaryDeductionImportModal opened onClose={onClose} />);

        await uploadFile();

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|CSV file is malformed'));
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('lists every rejected line with a message for its error code', async () => {
        mutateAsyncMock.mockRejectedValue(
            new ApiError({
                status: 422,
                message: 'E_UNPROCESSABLE_ENTITY',
                data: {
                    error_data: [
                        { line: 3, error_code: 'SALARY_CSV_INVALID_STAFF_NO' },
                        { line: 4, error_code: 'SALARY_CSV_INVALID_AMOUNT' },
                        { line: 5, error_code: 'SALARY_CSV_AMOUNT_TOO_HIGH' },
                        { line: 6, error_code: 'SALARY_CSV_SALARY_NOT_FOUND' },
                        { line: 7, error_code: 'SOMETHING_NEW' },
                    ],
                },
            }),
        );
        render(<SalaryDeductionImportModal opened onClose={vi.fn()} />);

        await uploadFile();

        await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
        expect(screen.getByText('Line 3: error|Invalid staff number')).toBeInTheDocument();
        expect(screen.getByText('Line 4: error|Invalid amount')).toBeInTheDocument();
        expect(screen.getByText('Line 5: error|Amount is too high')).toBeInTheDocument();
        // The month the payroll deducted from has not been calculated here yet - the file is
        // fine, the salary is missing.
        expect(screen.getByText('Line 6: error|Salary for this month has not been calculated yet')).toBeInTheDocument();
        // An error code the frontend has not been taught yet still shows the line.
        expect(screen.getByText('Line 7: error|Unknown error')).toBeInTheDocument();
        expect(showErrorMock).toHaveBeenCalledWith('error|Could not import CSV file');
    });

    it('clears the previous line errors when a new file is picked', async () => {
        mutateAsyncMock.mockRejectedValueOnce(
            new ApiError({
                status: 422,
                message: 'E_UNPROCESSABLE_ENTITY',
                data: { error_data: [{ line: 3, error_code: 'SALARY_CSV_INVALID_AMOUNT' }] },
            }),
        );
        render(<SalaryDeductionImportModal opened onClose={vi.fn()} />);
        await uploadFile();
        await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());

        await uploadFile(new File(['fixed'], 'fixed.csv', { type: 'text/csv' }));

        await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
    });

    it('clears the line errors when the modal is closed', async () => {
        mutateAsyncMock.mockRejectedValue(
            new ApiError({
                status: 422,
                message: 'E_UNPROCESSABLE_ENTITY',
                data: { error_data: [{ line: 3, error_code: 'SALARY_CSV_INVALID_AMOUNT' }] },
            }),
        );
        const onClose = vi.fn();
        const { rerender } = render(<SalaryDeductionImportModal opened onClose={onClose} />);
        await uploadFile();
        await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());

        await userEvent.click(screen.getByRole('button', { name: 'close-modal' }));
        expect(onClose).toHaveBeenCalledTimes(1);

        rerender(<SalaryDeductionImportModal opened onClose={onClose} />);
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('still reports a failure that is not an API error', async () => {
        mutateAsyncMock.mockRejectedValue(new Error('offline'));
        render(<SalaryDeductionImportModal opened onClose={vi.fn()} />);

        await uploadFile();

        // Silence would read as success and invite a second upload of the same file.
        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|Could not import CSV file'));
        expect(showSuccessMock).not.toHaveBeenCalled();
        expect(resetFileInputMock).toHaveBeenCalled();
    });
});
