import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SalaryRejectModal from '@/pages/manage/salary-request/partials/salary_reject_modal';

const { mutateAsyncMock, showSuccessMock, showErrorMock, useRejectMock } = vi.hoisted(() => ({
    mutateAsyncMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
    useRejectMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

vi.mock('react-icons/lu', () => ({
    LuSave: () => React.createElement('span', null, 'save-icon'),
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, type, loading }: any) =>
        React.createElement('button', { onClick, type: type || 'button', disabled: loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
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
    Textarea: ({ label, value, onChange, error }: any) =>
        React.createElement(
            'div',
            null,
            React.createElement('textarea', {
                'key': 'input',
                'aria-label': label,
                'value': value ?? '',
                'onChange': (e: any) => onChange?.(e.target.value),
            }),
            error ? React.createElement('span', { key: 'err', role: 'alert' }, error) : null,
        ),
}));

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

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/pages/manage/salary-request/api', () => ({
    useRejectSalaryRequestMutation: (...args: unknown[]) => useRejectMock(...args),
}));

describe('SalaryRejectModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mutateAsyncMock.mockResolvedValue({ data: null });
        useRejectMock.mockReturnValue({ mutateAsync: mutateAsyncMock, isPending: false });
    });

    it('renders nothing while closed', () => {
        const { container } = render(<SalaryRejectModal id="42" opened={false} onClose={vi.fn()} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('binds the mutation to the request being rejected', () => {
        render(<SalaryRejectModal id="42" opened onClose={vi.fn()} />);

        expect(useRejectMock).toHaveBeenCalledWith('42');
    });

    it('refuses to submit without a reason', async () => {
        render(<SalaryRejectModal id="42" opened onClose={vi.fn()} />);

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
        expect(mutateAsyncMock).not.toHaveBeenCalled();
    });

    it('sends the typed reason, reports success and closes', async () => {
        const onClose = vi.fn();
        render(<SalaryRejectModal id="42" opened onClose={onClose} />);

        await userEvent.type(screen.getByRole('textbox', { name: 'Reject reason' }), 'Requested too early');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(mutateAsyncMock).toHaveBeenCalledWith('Requested too early'));
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully reject salary request');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('keeps the modal open and reports the failure when the rejection is refused', async () => {
        mutateAsyncMock.mockRejectedValue(new Error('bad state'));
        const onClose = vi.fn();
        render(<SalaryRejectModal id="42" opened onClose={onClose} />);

        await userEvent.type(screen.getByRole('textbox', { name: 'Reject reason' }), 'Too late');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|Could not reject salary request'));
        expect(showSuccessMock).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();
    });

    it('closes without sending anything when cancelled', async () => {
        const onClose = vi.fn();
        render(<SalaryRejectModal id="42" opened onClose={onClose} />);

        await userEvent.type(screen.getByRole('textbox', { name: 'Reject reason' }), 'Draft');
        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(mutateAsyncMock).not.toHaveBeenCalled();
        expect(onClose).toHaveBeenCalledTimes(1);
        // The draft is dropped, so reopening starts from an empty reason.
        expect(screen.getByRole('textbox', { name: 'Reject reason' })).toHaveValue('');
    });

    it('disables the submit button while the rejection is in flight', () => {
        useRejectMock.mockReturnValue({ mutateAsync: mutateAsyncMock, isPending: true });
        render(<SalaryRejectModal id="42" opened onClose={vi.fn()} />);

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });
});
