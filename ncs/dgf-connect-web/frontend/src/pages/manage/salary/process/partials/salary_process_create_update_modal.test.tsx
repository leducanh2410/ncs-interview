import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SalaryProcess } from '@/types/models/salary';
import SalaryProcessCreateUpdateModal from '@/pages/manage/salary/process/partials/salary_process_create_update_modal';

const { createMutateMock, updateMutateMock, showSuccessMock, showErrorMock } = vi.hoisted(() => ({
    createMutateMock: vi.fn(),
    updateMutateMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

vi.mock('react-icons/lu', () => ({
    LuCalendar: () => React.createElement('span', null, 'calendar-icon'),
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
        Button: ({ children, onClick, type }: any) =>
            React.createElement('button', { onClick, type: type || 'button' }, children),
        Flex: ({ children }: any) => React.createElement('div', null, children),
        Modal: ({ children, opened, title }: any) =>
            opened
                ? React.createElement(
                      'div',
                      { 'data-testid': 'modal' },
                      React.createElement('h2', null, title),
                      children,
                  )
                : null,
        NumberInput: ({ label, value, onChange, error }: any) =>
            React.createElement(
                'div',
                null,
                React.createElement('input', {
                    'aria-label': label,
                    'value': value ?? '',
                    'onChange': (e: any) => onChange?.(Number(e.target.value)),
                }),
                error ? React.createElement('span', { role: 'alert' }, error) : null,
            ),
        Radio,
        Select: ({ placeholder, value, data, onChange, error }: any) =>
            React.createElement(
                'div',
                null,
                React.createElement(
                    'select',
                    {
                        'aria-label': placeholder,
                        'value': value ?? '',
                        'onChange': (e: any) => onChange?.(e.target.value),
                    },
                    [
                        React.createElement('option', { key: '', value: '' }, '--'),
                        ...data.map((opt: any) =>
                            React.createElement('option', { key: opt.value, value: opt.value }, opt.label),
                        ),
                    ],
                ),
                error ? React.createElement('span', { role: 'alert' }, error) : null,
            ),
    };
});

vi.mock('@mantine/dates', () => ({
    DatePickerInput: ({ label, value, onChange, error }: any) =>
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
    MonthPickerInput: ({ label, value, onChange }: any) =>
        React.createElement('input', {
            'aria-label': label,
            'value': value ?? '',
            'onChange': (e: any) => onChange?.(e.target.value),
        }),
}));

vi.mock('@mantine/form', () => ({
    useForm: ({ initialValues, validate }: any) => {
        const [values, setValues] = React.useState(initialValues);
        const [errors, setErrors] = React.useState<Record<string, string>>({});

        return {
            values,
            errors,
            setValues: (v: any) => setValues((prev: any) => ({ ...prev, ...v })),
            resetDirty: vi.fn(),
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

vi.mock('jotai', () => ({
    useAtom: () => [{ workplaces: [{ id: 3, name: 'Workplace A' }] }, vi.fn()],
    atom: vi.fn(),
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: {},
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        now: () => ({ format: () => '2026-05-10' }),
        // Only used to narrow a stored value to another format, so the suffix is dropped.
        parseAndFormat: (value: string | null, _from: string, to: string) => {
            if (!value) return '';
            return to === 'YYYY-MM' ? value.slice(0, 7) : value.slice(0, 10);
        },
    },
    DATE_STORE_FORMAT: 'YYYY-MM-DD',
    DATE_STORE_FORMAT_MONTH: 'YYYY-MM',
    DATE_STORE_FORMAT_WITH_TIME: 'YYYY-MM-DD HH:mm:ss',
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/pages/manage/salary/process/api', () => ({
    useCreateSalaryProcessMutation: () => ({ mutateAsync: createMutateMock }),
    useUpdateSalaryProcessMutation: () => ({ mutateAsync: updateMutateMock }),
}));

const existingProcess = (overrides: Partial<SalaryProcess> = {}): SalaryProcess =>
    ({
        id: 12,
        month: '2026-04',
        workplace_id: 3,
        status: 'pending',
        priority: 7,
        scheduled_at: '2026-06-01 03:00:00',
        executed_at: null,
        workplace: { id: 3, name: 'Workplace A' },
        ...overrides,
    }) as SalaryProcess;

const save = async () => {
    await userEvent.click(screen.getByRole('button', { name: 'Save' }));
};

describe('SalaryProcessCreateUpdateModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        createMutateMock.mockResolvedValue({ data: null });
        updateMutateMock.mockResolvedValue({ data: null });
    });

    it('renders nothing while closed', () => {
        const { container } = render(
            <SalaryProcessCreateUpdateModal salaryProcess={null} opened={false} onClose={vi.fn()} />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('opens as a create form when no schedule was passed', () => {
        render(<SalaryProcessCreateUpdateModal salaryProcess={null} opened onClose={vi.fn()} />);

        expect(screen.getByRole('heading', { name: 'Create salary payment schedule' })).toBeInTheDocument();
        // Scheduling is on by default, so the date field is there from the start.
        expect(screen.getByLabelText('Scheduled date')).toBeInTheDocument();
    });

    it('offers the workplaces from the master data', () => {
        render(<SalaryProcessCreateUpdateModal salaryProcess={null} opened onClose={vi.fn()} />);

        expect(screen.getByRole('option', { name: 'Workplace A' })).toBeInTheDocument();
    });

    it('refuses to submit without a workplace', async () => {
        render(<SalaryProcessCreateUpdateModal salaryProcess={null} opened onClose={vi.fn()} />);

        await save();

        await waitFor(() => expect(screen.getAllByRole('alert').length).toBeGreaterThan(0));
        expect(createMutateMock).not.toHaveBeenCalled();
    });

    it('refuses to submit a scheduled run without a date', async () => {
        render(<SalaryProcessCreateUpdateModal salaryProcess={null} opened onClose={vi.fn()} />);
        await userEvent.selectOptions(screen.getByRole('combobox'), '3');

        await save();

        await waitFor(() => expect(screen.getAllByRole('alert').length).toBeGreaterThan(0));
        expect(createMutateMock).not.toHaveBeenCalled();
    });

    it('creates the schedule with the month narrowed to Y-m', async () => {
        const onClose = vi.fn();
        render(<SalaryProcessCreateUpdateModal salaryProcess={null} opened onClose={onClose} />);
        await userEvent.selectOptions(screen.getByRole('combobox'), '3');
        await userEvent.type(screen.getByLabelText('Scheduled date'), '2026-06-01');

        await save();

        await waitFor(() =>
            expect(createMutateMock).toHaveBeenCalledWith({
                workplace_id: '3',
                month: '2026-05',
                priority: 0,
                scheduled_at: '2026-06-01',
            }),
        );
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully create salary payment schedule');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('omits the date entirely when the run is not scheduled', async () => {
        render(<SalaryProcessCreateUpdateModal salaryProcess={null} opened onClose={vi.fn()} />);
        await userEvent.selectOptions(screen.getByRole('combobox'), '3');

        await userEvent.click(screen.getByRole('radio', { name: 'No' }));

        expect(screen.queryByLabelText('Scheduled date')).not.toBeInTheDocument();
        await save();

        await waitFor(() => expect(createMutateMock).toHaveBeenCalledTimes(1));
        expect(createMutateMock.mock.calls[0][0]).not.toHaveProperty('scheduled_at');
    });

    it('reports a failed creation and keeps the modal open', async () => {
        createMutateMock.mockRejectedValue(new Error('boom'));
        const onClose = vi.fn();
        render(<SalaryProcessCreateUpdateModal salaryProcess={null} opened onClose={onClose} />);
        await userEvent.selectOptions(screen.getByRole('combobox'), '3');
        await userEvent.type(screen.getByLabelText('Scheduled date'), '2026-06-01');

        await save();

        await waitFor(() =>
            expect(showErrorMock).toHaveBeenCalledWith('error|Could not create salary payment schedule'),
        );
        expect(onClose).not.toHaveBeenCalled();
    });

    it('opens as an update form prefilled from the schedule', () => {
        render(<SalaryProcessCreateUpdateModal salaryProcess={existingProcess()} opened onClose={vi.fn()} />);

        expect(screen.getByRole('heading', { name: 'Update salary payment schedule' })).toBeInTheDocument();
        expect(screen.getByRole('combobox')).toHaveValue('3');
        expect(screen.getByLabelText('Priority')).toHaveValue('7');
        expect(screen.getByLabelText('Scheduled date')).toHaveValue('2026-06-01');
        expect(screen.getByRole('radio', { name: 'Yes' })).toBeChecked();
    });

    it('updates the schedule it was given rather than creating a new one', async () => {
        const onClose = vi.fn();
        render(<SalaryProcessCreateUpdateModal salaryProcess={existingProcess()} opened onClose={onClose} />);

        await save();

        await waitFor(() =>
            expect(updateMutateMock).toHaveBeenCalledWith({
                id: 12,
                data: { workplace_id: '3', month: '2026-04', priority: 7, scheduled_at: '2026-06-01' },
            }),
        );
        expect(createMutateMock).not.toHaveBeenCalled();
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully update salary payment schedule');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('reports a failed update with the update wording', async () => {
        updateMutateMock.mockRejectedValue(new Error('locked'));
        render(<SalaryProcessCreateUpdateModal salaryProcess={existingProcess()} opened onClose={vi.fn()} />);

        await save();

        await waitFor(() =>
            expect(showErrorMock).toHaveBeenCalledWith('error|Could not update salary payment schedule'),
        );
    });

    it('reverts to a blank create form when the selected schedule is cleared', () => {
        const { rerender } = render(
            <SalaryProcessCreateUpdateModal salaryProcess={existingProcess()} opened onClose={vi.fn()} />,
        );
        expect(screen.getByLabelText('Priority')).toHaveValue('7');

        rerender(<SalaryProcessCreateUpdateModal salaryProcess={null} opened onClose={vi.fn()} />);

        expect(screen.getByRole('heading', { name: 'Create salary payment schedule' })).toBeInTheDocument();
        expect(screen.getByLabelText('Priority')).toHaveValue('0');
        expect(screen.getByRole('combobox')).toHaveValue('');
    });

    it('closes without writing anything when cancelled', async () => {
        const onClose = vi.fn();
        render(<SalaryProcessCreateUpdateModal salaryProcess={null} opened onClose={onClose} />);

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(createMutateMock).not.toHaveBeenCalled();
        expect(updateMutateMock).not.toHaveBeenCalled();
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
