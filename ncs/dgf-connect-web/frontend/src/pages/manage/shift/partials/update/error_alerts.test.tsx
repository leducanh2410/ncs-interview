import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ShiftCsvError } from '@/types/models/shift';
import ErrorAlerts from '@/pages/manage/shift/partials/update/error_alerts';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Box: ({ children }: any) => React.createElement('div', null, children),
}));

vi.mock('@/components/DAlert', () => ({
    default: ({ children }: any) => React.createElement('div', { role: 'alert' }, children),
}));

describe('ErrorAlerts', () => {
    it('renders nothing when there are no errors', () => {
        const { container } = render(<ErrorAlerts csvImportErrorData={[]} />);

        expect(container.querySelectorAll('[role="alert"]').length).toBe(0);
    });

    it('renders the formNotMemberError alert when provided', () => {
        render(<ErrorAlerts formNotMemberError="Not a member" csvImportErrorData={[]} />);

        expect(screen.getByText('Not a member')).toBeInTheDocument();
    });

    it('renders the formDisabledError alert when provided', () => {
        render(<ErrorAlerts formDisabledError="User disabled: Alice" csvImportErrorData={[]} />);

        expect(screen.getByText('User disabled: Alice')).toBeInTheDocument();
    });

    it('renders formTotalErrors items', () => {
        render(
            <ErrorAlerts
                formTotalErrors={[
                    { key: 'a', error: 'Error A' },
                    { key: 'b', error: 'Error B' },
                ]}
                csvImportErrorData={[]}
            />,
        );

        expect(screen.getByText('Error A')).toBeInTheDocument();
        expect(screen.getByText('Error B')).toBeInTheDocument();
    });

    it('does not render the formTotalErrors alert when the list is empty', () => {
        render(<ErrorAlerts formTotalErrors={[]} csvImportErrorData={[]} />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('renders csv import errors with user name and staff no, mapping the error code to a message', () => {
        const csvImportErrorData: ShiftCsvError[] = [
            { staff_no: '001', user_name: 'Yamada', error_code: 'SHIFT_CSV_INVALID_WORKPLACE_POSITION' },
        ];
        render(<ErrorAlerts csvImportErrorData={csvImportErrorData} />);

        expect(screen.getByText('Yamada (001):')).toBeInTheDocument();
        expect(screen.getByText('validation|Invalid workplace position')).toBeInTheDocument();
    });

    it('falls back to staff_no alone when user_name is null', () => {
        const csvImportErrorData: ShiftCsvError[] = [
            { staff_no: '002', user_name: null, error_code: 'SCHEDULE_SHIFT_OVERLAP' },
        ];
        render(<ErrorAlerts csvImportErrorData={csvImportErrorData} />);

        expect(screen.getByText('002:')).toBeInTheDocument();
        expect(screen.getByText('validation|Shift overlap')).toBeInTheDocument();
    });

    it('shows an unknown-error message for an unrecognized error code', () => {
        const csvImportErrorData: ShiftCsvError[] = [
            { staff_no: '003', user_name: null, error_code: 'SOME_UNKNOWN_CODE' },
        ];
        render(<ErrorAlerts csvImportErrorData={csvImportErrorData} />);

        expect(screen.getByText('error|Unknown error')).toBeInTheDocument();
    });
});
