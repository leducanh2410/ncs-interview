import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import RejectRequestModal from './reject_request_modal';

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
    Modal: ({ children, opened, title }: any) =>
        opened
            ? React.createElement('div', { 'data-testid': 'modal' }, React.createElement('h2', null, title), children)
            : null,
    Textarea: ({ label, value, onChange, error }: any) =>
        React.createElement('div', null, [
            React.createElement('textarea', {
                'key': 'input',
                'aria-label': label,
                'value': value ?? '',
                'onChange': (e: any) => onChange?.(e.target.value),
            }),
            error ? React.createElement('span', { key: 'err', role: 'alert' }, error) : null,
        ]),
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

describe('RejectRequestModal', () => {
    it('renders nothing when closed', () => {
        const { container } = render(<RejectRequestModal opened={false} onClose={vi.fn()} onSubmit={vi.fn()} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('blocks submission and does not call onSubmit when the reason is empty', async () => {
        const onSubmit = vi.fn();
        render(<RejectRequestModal opened onClose={vi.fn()} onSubmit={onSubmit} />);

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByRole('alert')).toBeInTheDocument();
        });
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('calls onSubmit with the typed reason once filled', async () => {
        const onSubmit = vi.fn().mockResolvedValue(undefined);
        render(<RejectRequestModal opened onClose={vi.fn()} onSubmit={onSubmit} />);

        await userEvent.type(screen.getByRole('textbox', { name: 'Reject reason' }), 'Coverage already thin');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(onSubmit).toHaveBeenCalledWith('Coverage already thin');
        });
    });

    it('keeps the typed reason when onSubmit rejects, e.g. a concurrent decision', async () => {
        const onSubmit = vi.fn().mockRejectedValue(new Error('already decided'));
        render(<RejectRequestModal opened onClose={vi.fn()} onSubmit={onSubmit} />);

        const textarea = screen.getByRole('textbox', { name: 'Reject reason' });
        await userEvent.type(textarea, 'Coverage already thin');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(onSubmit).toHaveBeenCalledWith('Coverage already thin');
        });
        expect(textarea).toHaveValue('Coverage already thin');
    });

    it('calls onClose without submitting when Cancel is clicked', async () => {
        const onSubmit = vi.fn();
        const onClose = vi.fn();
        render(<RejectRequestModal opened onClose={onClose} onSubmit={onSubmit} />);

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalled();
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('disables the Save button while a decision is processing', () => {
        render(<RejectRequestModal opened onClose={vi.fn()} onSubmit={vi.fn()} isProcessing />);

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });
});
