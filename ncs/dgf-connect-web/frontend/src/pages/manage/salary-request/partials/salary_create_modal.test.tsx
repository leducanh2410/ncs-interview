import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';
import SalaryRequestCreateModal from '@/pages/manage/salary-request/partials/salary_create_modal';

const { mutateAsyncMock, refetchMock, useSalaryInfoMock, showSuccessMock, showErrorMock, setFormErrorMock, translate } =
    vi.hoisted(() => ({
        mutateAsyncMock: vi.fn(),
        refetchMock: vi.fn(),
        useSalaryInfoMock: vi.fn(),
        showSuccessMock: vi.fn(),
        showErrorMock: vi.fn(),
        setFormErrorMock: vi.fn(),
        translate: (key: string, params?: Record<string, unknown>) =>
            params ? key.replace(/{{(\w+)}}/g, (_m: string, name: string) => String(params[name] ?? '')) : key,
    }));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: translate }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: translate },
}));

vi.mock('react-icons/lu', () => ({
    LuDollarSign: () => React.createElement('span', null, 'dollar-icon'),
    LuSave: () => React.createElement('span', null, 'save-icon'),
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
        Button: ({ children, onClick, type, disabled }: any) =>
            React.createElement('button', { onClick, type: type || 'button', disabled }, children),
        Flex: ({ children }: any) => React.createElement('div', null, children),
        LoadingOverlay: ({ visible }: any) =>
            visible ? React.createElement('div', { 'data-testid': 'loading-overlay' }) : null,
        Modal: ({ children, opened, title }: any) =>
            opened
                ? React.createElement(
                      'div',
                      { 'data-testid': 'modal' },
                      React.createElement('h2', null, title),
                      children,
                  )
                : null,
        NumberInput: ({ label, value, onChange, min, max, error }: any) =>
            React.createElement(
                'div',
                null,
                React.createElement('input', {
                    'aria-label': label,
                    'value': value ?? '',
                    'data-min': min,
                    'data-max': max,
                    'onChange': (e: any) => onChange?.(Number(e.target.value)),
                }),
                error ? React.createElement('span', { role: 'alert' }, error) : null,
            ),
        Radio,
        Text: ({ children }: any) => React.createElement('span', null, children),
    };
});

vi.mock('@mantine/form', () => ({
    useForm: ({ initialValues, validate }: any) => {
        const [values, setValues] = React.useState(initialValues);
        const [errors, setErrors] = React.useState<Record<string, string>>({});

        return {
            values,
            errors,
            reset: () => {
                setValues(initialValues);
                setErrors({});
            },
            setFieldValue: (field: string, value: any) => setValues((prev: any) => ({ ...prev, [field]: value })),
            setFieldError: (field: string, message: string) =>
                setErrors((prev: any) => ({ ...prev, [field]: message })),
            getInputProps: (field: string) => ({
                value: values[field] ?? '',
                onChange: (v: any) => setValues((prev: any) => ({ ...prev, [field]: v })),
                error: errors[field],
            }),
            onSubmit: (handler: (v: any) => void) => (e: any) => {
                e.preventDefault();
                const validationErrors: Record<string, string> = {};
                for (const field of Object.keys(validate)) {
                    const message = validate[field](values[field], values, field);
                    if (message) {
                        validationErrors[field] = message;
                    }
                }
                if (Object.keys(validationErrors).length > 0) {
                    setErrors(validationErrors);
                    return;
                }
                handler(values);
            },
        };
    },
}));

vi.mock('@/components/DUserSelectRemote', () => ({
    default: ({ label, value, onChange, error }: any) =>
        React.createElement(
            'div',
            null,
            React.createElement('input', {
                'aria-label': label,
                'value': value ?? '',
                'onChange': (e: any) => onChange?.(e.target.value),
            }),
            error ? React.createElement('span', { role: 'alert' }, error) : null,
        ),
}));

vi.mock('@/helpers/common.helper', () => ({
    default: {
        formatNumber: (value: number) => new Intl.NumberFormat('en-US').format(value),
        // The real Validator runs against these, so they have to behave like the originals.
        isNullOrUndefined: (value: unknown) => value === null || value === undefined,
        isNumeric: (value: unknown) => value !== '' && value !== null && !isNaN(Number(value)),
        isString: (value: unknown) => typeof value === 'string',
    },
}));

vi.mock('@/helpers/form.helper', () => ({
    default: { setFormError: (...args: unknown[]) => setFormErrorMock(...args) },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/pages/manage/salary-request/api', () => ({
    useCreateSalaryRequestMutation: () => ({ mutateAsync: mutateAsyncMock, isPending: false }),
    useGetUserSalaryInfo: (...args: unknown[]) => useSalaryInfoMock(...args),
}));

const SALARY_INFO = {
    data: {
        amount: { min: 50000, max: 66000, min_transfer: 51000, max_transfer: 65000 },
        salaries: [{ month: '2026-03', total_amount: 50000 }],
    },
};

const withSalaryInfo = (data: unknown = SALARY_INFO, isPending = false) =>
    useSalaryInfoMock.mockReturnValue({ data, isPending, refetch: refetchMock });

const selectUser = async (id = '7') => {
    await userEvent.type(screen.getByRole('textbox', { name: 'User' }), id);
};

describe('SalaryRequestCreateModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mutateAsyncMock.mockResolvedValue({ data: null });
        refetchMock.mockResolvedValue({ data: SALARY_INFO });
        withSalaryInfo(null);
    });

    it('renders nothing while closed', () => {
        const { container } = render(<SalaryRequestCreateModal opened={false} onClose={vi.fn()} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('cannot be submitted before a salary range has been quoted', () => {
        render(<SalaryRequestCreateModal opened onClose={vi.fn()} />);

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
        expect(screen.queryByRole('textbox', { name: 'Desired amount' })).not.toBeInTheDocument();
    });

    it('only offers to quote a range once an employee is chosen', async () => {
        render(<SalaryRequestCreateModal opened onClose={vi.fn()} />);
        const quoteButton = screen.getByRole('button', { name: 'Get salary info' });
        expect(quoteButton).toBeDisabled();

        await selectUser();

        expect(screen.getByRole('button', { name: 'Get salary info' })).toBeEnabled();
    });

    it('asks the backend for the chosen employees range', async () => {
        render(<SalaryRequestCreateModal opened onClose={vi.fn()} />);
        await selectUser('7');

        await userEvent.click(screen.getByRole('button', { name: 'Get salary info' }));

        expect(useSalaryInfoMock).toHaveBeenLastCalledWith('7');
        expect(refetchMock).toHaveBeenCalledTimes(1);
    });

    it('shows a spinner while the range is being fetched', () => {
        withSalaryInfo(null, true);
        render(<SalaryRequestCreateModal opened onClose={vi.fn()} />);

        expect(screen.getByTestId('loading-overlay')).toBeInTheDocument();
    });

    it('prefills the transfer ceiling and unlocks the form once the range arrives', () => {
        withSalaryInfo();
        render(<SalaryRequestCreateModal opened onClose={vi.fn()} />);

        expect(screen.getByText('Maximum amount: 66,000 Yen')).toBeInTheDocument();
        const amount = screen.getByRole('textbox', { name: 'Desired amount' });
        expect(amount).toHaveValue('65000');
        expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled();
    });

    it('bounds the amount by the transferable range while transfer is selected', () => {
        withSalaryInfo();
        render(<SalaryRequestCreateModal opened onClose={vi.fn()} />);

        const amount = screen.getByRole('textbox', { name: 'Desired amount' });
        expect(amount).toHaveAttribute('data-min', '51000');
        expect(amount).toHaveAttribute('data-max', '65000');
    });

    it('widens the bounds to the full balance when paying in cash', async () => {
        withSalaryInfo();
        render(<SalaryRequestCreateModal opened onClose={vi.fn()} />);

        await userEvent.click(screen.getByRole('radio', { name: 'enum|Cash' }));

        const amount = screen.getByRole('textbox', { name: 'Desired amount' });
        expect(amount).toHaveAttribute('data-min', '50000');
        expect(amount).toHaveAttribute('data-max', '66000');
    });

    it('creates the request, reports success and closes', async () => {
        withSalaryInfo();
        const onClose = vi.fn();
        render(<SalaryRequestCreateModal opened onClose={onClose} />);
        await selectUser('7');

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() =>
            expect(mutateAsyncMock).toHaveBeenCalledWith({ user_id: '7', amount: 65000, type: 'transfer' }),
        );
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully create salary request for user');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('sends the cash type when that method was picked', async () => {
        withSalaryInfo();
        render(<SalaryRequestCreateModal opened onClose={vi.fn()} />);
        await selectUser('7');
        await userEvent.click(screen.getByRole('radio', { name: 'enum|Cash' }));

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(mutateAsyncMock).toHaveBeenCalledWith(expect.objectContaining({ type: 'cash' })));
    });

    it('refuses to submit an amount of zero', async () => {
        withSalaryInfo();
        render(<SalaryRequestCreateModal opened onClose={vi.fn()} />);
        await selectUser('7');
        const amount = screen.getByRole('textbox', { name: 'Desired amount' });
        await userEvent.clear(amount);
        await userEvent.type(amount, '0');

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(screen.getAllByRole('alert').length).toBeGreaterThan(0));
        expect(mutateAsyncMock).not.toHaveBeenCalled();
    });

    it('marks the employee field when they already have an open request', async () => {
        withSalaryInfo();
        mutateAsyncMock.mockRejectedValue(new ApiError({ status: 400, message: 'SALARY_REQUEST_EXISTS', data: {} }));
        const onClose = vi.fn();
        render(<SalaryRequestCreateModal opened onClose={onClose} />);
        await selectUser('7');

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() =>
            expect(screen.getByText('error|Salary request already exists for this user')).toBeInTheDocument(),
        );
        // A field-level problem, so no toast and the modal stays open to be corrected.
        expect(showErrorMock).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();
    });

    it('hands a validation rejection back to the form', async () => {
        withSalaryInfo();
        const error = new ApiValidationError({
            status: 422,
            message: 'E_UNPROCESSABLE_ENTITY',
            data: { amount: ['The amount is invalid.'] },
        });
        mutateAsyncMock.mockRejectedValue(error);
        render(<SalaryRequestCreateModal opened onClose={vi.fn()} />);
        await selectUser('7');

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(setFormErrorMock).toHaveBeenCalledWith(expect.anything(), error));
        expect(showErrorMock).not.toHaveBeenCalled();
    });

    it('falls back to a generic message for an API error it has no special wording for', async () => {
        withSalaryInfo();
        mutateAsyncMock.mockRejectedValue(new ApiError({ status: 400, message: 'E_BAD_REQUEST', data: {} }));
        render(<SalaryRequestCreateModal opened onClose={vi.fn()} />);
        await selectUser('7');

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() =>
            expect(showErrorMock).toHaveBeenCalledWith('error|Could not create salary request for user'),
        );
        expect(screen.queryByText('error|Salary request already exists for this user')).not.toBeInTheDocument();
    });

    it('falls back to a generic message for any other failure', async () => {
        withSalaryInfo();
        mutateAsyncMock.mockRejectedValue(new Error('offline'));
        const onClose = vi.fn();
        render(<SalaryRequestCreateModal opened onClose={onClose} />);
        await selectUser('7');

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() =>
            expect(showErrorMock).toHaveBeenCalledWith('error|Could not create salary request for user'),
        );
        expect(onClose).not.toHaveBeenCalled();
    });

    it('discards the draft when cancelled', async () => {
        withSalaryInfo();
        const onClose = vi.fn();
        render(<SalaryRequestCreateModal opened onClose={onClose} />);
        await selectUser('7');

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(mutateAsyncMock).not.toHaveBeenCalled();
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('textbox', { name: 'User' })).toHaveValue('');
    });
});
