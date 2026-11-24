import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CheckIn } from '@/types/models/check_in';
import CheckInUpdateModal from '@/pages/manage/work-history/partials/check_in_update_modal';

const { updateCheckInMutateAsync, showSuccessMock } = vi.hoisted(() => ({
    updateCheckInMutateAsync: vi.fn(),
    showSuccessMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

vi.mock('react-icons/lu', () => ({
    LuCalendar: () => null,
    LuSave: () => null,
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, type, loading }: any) =>
        React.createElement('button', { onClick, 'type': type || 'button', 'aria-busy': loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened
            ? React.createElement('div', { 'data-testid': 'modal' }, React.createElement('h2', null, title), children)
            : null,
    NumberInput: ({ label, value, onChange, min }: any) =>
        React.createElement('input', {
            'aria-label': label,
            'type': 'number',
            'min': min,
            'value': value ?? '',
            'onChange': (e: any) => onChange(e.target.value === '' ? '' : Number(e.target.value)),
        }),
    TextInput: ({ label, value, disabled }: any) =>
        React.createElement('input', { 'aria-label': label, 'value': value ?? '', disabled, 'readOnly': true }),
    Textarea: ({ label, value, onChange, error }: any) =>
        React.createElement('div', null, [
            React.createElement('input', {
                'key': 'input',
                'aria-label': label,
                'value': value ?? '',
                'onChange': (e: any) => onChange(e.target.value),
            }),
            error ? React.createElement('span', { key: 'err', role: 'alert' }, error) : null,
        ]),
}));

vi.mock('@mantine/dates', () => ({
    DatePickerInput: ({ label, value, onChange }: any) =>
        React.createElement('input', {
            'aria-label': label,
            'value': value ?? '',
            'onChange': (e: any) => onChange(e.target.value),
        }),
}));

vi.mock('@/components/DTimeInput', () => ({
    default: ({ label, value, onChange }: any) =>
        React.createElement('input', {
            'aria-label': label,
            'value': value ?? '',
            'onChange': (e: any) => onChange(e.target.value),
        }),
}));

vi.mock('@mantine/form', () => ({
    useForm: ({ initialValues, validate }: any) => {
        const [values, setValuesState] = React.useState(initialValues);
        const [errors, setErrors] = React.useState<Record<string, string>>({});

        return {
            values,
            errors,
            setValues: (v: any) => setValuesState((prev: any) => ({ ...prev, ...v })),
            resetDirty: () => undefined,
            reset: () => {
                setValuesState(initialValues);
                setErrors({});
            },
            getInputProps: (field: string) => ({
                value: values[field] ?? '',
                onChange: (v: any) => setValuesState((prev: any) => ({ ...prev, [field]: v })),
                error: errors[field],
            }),
            onSubmit: (handler: (v: any) => void) => (e: any) => {
                e?.preventDefault?.();
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

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parse: (value: string) => {
            const [datePart, timePart] = value.split(' ');
            return { format: (fmt: string) => (fmt === 'HH:mm:ss' ? timePart : datePart) };
        },
        parseAndFormat: (v: string) => v ?? '',
        formatWorkHour: () => ({ startTime: '09:00', endTime: '18:00' }),
    },
    DATE_STORE_FORMAT: 'YYYY-MM-DD',
    DATE_STORE_FORMAT_WITH_TIME: 'YYYY-MM-DD HH:mm:ss',
}));

vi.mock('@/helpers/form.helper', () => ({
    default: {
        toParams: (values: Record<string, any>) => values,
        setFormError: vi.fn(),
    },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showSuccess: (...args: unknown[]) => showSuccessMock(...args), showError: vi.fn() },
}));

vi.mock('@/pages/manage/work-history/api', () => ({
    useUpdateCheckInMutation: () => ({ mutateAsync: updateCheckInMutateAsync, isPending: false }),
}));

const makeCheckIn = (overrides: Record<string, any> = {}) =>
    ({
        id: 1,
        shift_id: 10,
        shift_user_id: 5,
        user_id: 3,
        check_in_time: '2026-05-10 09:00:00',
        check_out_time: '2026-05-10 18:00:00',
        checked_in_by: null,
        checked_out_by: null,
        work_hours: '8.00',
        work_minutes: 480,
        break_minutes: 30,
        shift: {
            id: 10,
            date: '2026-05-10',
            break_minutes: null,
            workplace: { id: 1, name: 'Test Workplace' },
            workplace_shift: { start_time: '09:00:00', end_time: '18:00:00', break_duration: 30 },
        },
        user: { id: 3, name: 'Test User', staff_no: 'EMP001' },
        workplace: { id: 1, name: 'Test Workplace' },
        ...overrides,
    }) as unknown as CheckIn;

describe('CheckInUpdateModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        updateCheckInMutateAsync.mockResolvedValue({ data: { id: 1, status: 'pending' } });
    });

    it('renders nothing when checkIn is null', () => {
        render(<CheckInUpdateModal checkIn={null} onClose={vi.fn()} />);

        expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
    });

    it('blocks submission and requires a reason only for the field the user changed', async () => {
        render(<CheckInUpdateModal checkIn={makeCheckIn()} onClose={vi.fn()} />);

        fireEvent.change(screen.getByLabelText('Check in time'), { target: { value: '09:30:00' } });
        fireEvent.click(screen.getByText('Save'));

        await waitFor(() => {
            expect(screen.getAllByRole('alert')).toHaveLength(1);
        });
        expect(updateCheckInMutateAsync).not.toHaveBeenCalled();
    });

    it('submits once the required reason is filled, without requiring reasons for unchanged fields', async () => {
        const onClose = vi.fn();
        render(<CheckInUpdateModal checkIn={makeCheckIn()} onClose={onClose} />);

        fireEvent.change(screen.getByLabelText('Check in time'), { target: { value: '09:30:00' } });
        fireEvent.change(screen.getByLabelText('Reason for change in check-in time'), {
            target: { value: 'Máy chấm công lỗi' },
        });
        fireEvent.click(screen.getByText('Save'));

        await waitFor(() => {
            expect(updateCheckInMutateAsync).toHaveBeenCalledWith(
                expect.objectContaining({
                    id: '1',
                    data: expect.objectContaining({
                        check_in_time: '09:30:00',
                        check_in_reason: 'Máy chấm công lỗi',
                        check_out_reason: '',
                        break_minutes_reason: '',
                    }),
                }),
            );
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Edit request submitted, pending approval');
        expect(onClose).toHaveBeenCalled();
    });

    it('does not require any reason when no field is changed', async () => {
        render(<CheckInUpdateModal checkIn={makeCheckIn()} onClose={vi.fn()} />);

        fireEvent.click(screen.getByText('Save'));

        await waitFor(() => {
            expect(updateCheckInMutateAsync).toHaveBeenCalled();
        });
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
});
