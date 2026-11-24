import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import BulkRejectModal from '@/pages/manage/work-history/edit-requests/partials/bulk_reject_modal';

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, opts?: any) => (opts?.count !== undefined ? `${key}:${opts.count}` : key),
    }),
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
    Text: ({ children }: any) => React.createElement('span', null, children),
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

describe('BulkRejectModal', () => {
    it('renders nothing when closed', () => {
        const { container } = render(<BulkRejectModal opened={false} count={2} onClose={vi.fn()} onSubmit={vi.fn()} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('requires a non-empty shared reason before submitting', async () => {
        const onSubmit = vi.fn();
        render(<BulkRejectModal opened count={3} onClose={vi.fn()} onSubmit={onSubmit} />);

        await userEvent.click(screen.getByRole('button', { name: 'Reject' }));

        await waitFor(() => {
            expect(screen.getByRole('alert')).toBeInTheDocument();
        });
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('submits the shared reason applied to all selected requests', async () => {
        const onSubmit = vi.fn().mockResolvedValue(undefined);
        render(<BulkRejectModal opened count={3} onClose={vi.fn()} onSubmit={onSubmit} />);

        expect(
            screen.getByText('This reason will be applied to all {{count}} selected requests.:3'),
        ).toBeInTheDocument();

        await userEvent.type(screen.getByRole('textbox', { name: 'Reject reason' }), 'Thiếu bằng chứng');
        await userEvent.click(screen.getByRole('button', { name: 'Reject' }));

        await waitFor(() => {
            expect(onSubmit).toHaveBeenCalledWith('Thiếu bằng chứng');
        });
    });
});
