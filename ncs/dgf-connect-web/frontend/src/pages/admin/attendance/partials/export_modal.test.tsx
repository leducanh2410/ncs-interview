import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AttendanceExportModal from '@/pages/admin/attendance/partials/export_modal';

const { exportMock, showErrorMock } = vi.hoisted(() => ({
    exportMock: vi.fn(),
    showErrorMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { onClick, disabled: disabled || loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@mantine/dates', () => ({
    MonthPickerInput: ({ value, onChange, label }: any) =>
        React.createElement('input', {
            'aria-label': label,
            'data-testid': 'month-picker',
            'value': value ?? '',
            'onChange': (event: any) => onChange(event.target.value || null),
        }),
}));

vi.mock('@/components/DUserSelectRemote', () => ({
    // Comma-separated in the stub; the real component hands back an array of ids.
    DUserSelectRemoteMultiple: ({ value, onChange, label, extraParams }: any) =>
        React.createElement('input', {
            'aria-label': label,
            'data-testid': 'user-select',
            'data-permission': JSON.stringify(extraParams?.permission ?? []),
            'value': (value ?? []).join(','),
            'onChange': (event: any) => onChange(event.target.value ? event.target.value.split(',') : []),
        }),
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showError: (...args: unknown[]) => showErrorMock(...args) },
}));

vi.mock('@/pages/admin/attendance/api', () => ({
    exportMonthlyAttendance: (...args: unknown[]) => exportMock(...args),
}));

vi.mock('@/helpers/date.helper', () => ({
    DATE_STORE_FORMAT: 'YYYY-MM-DD',
    DATE_STORE_FORMAT_MONTH: 'YYYY-MM',
    default: {
        now: () => ({ format: () => '2026-08-13' }),
        // Only ever called to narrow YYYY-MM-DD down to its month.
        parseAndFormat: (value: string) => (value ? value.slice(0, 7) : ''),
    },
}));

const openModal = (props: Record<string, unknown> = {}) =>
    render(<AttendanceExportModal opened onClose={vi.fn()} {...props} />);

describe('Admin attendance export modal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        exportMock.mockResolvedValue(undefined);
    });

    it('exports the current month for all admins by default', async () => {
        openModal();

        fireEvent.click(screen.getByText('Export excel'));

        await waitFor(() => expect(exportMock).toHaveBeenCalledWith('2026-08', []));
    });

    it('exports only the selected admin', async () => {
        openModal();

        fireEvent.change(screen.getByTestId('user-select'), { target: { value: '7' } });
        fireEvent.click(screen.getByText('Export excel'));

        await waitFor(() => expect(exportMock).toHaveBeenCalledWith('2026-08', ['7']));
    });

    it('exports several admins at once', async () => {
        openModal();

        fireEvent.change(screen.getByTestId('user-select'), { target: { value: '7,9' } });
        fireEvent.click(screen.getByText('Export excel'));

        await waitFor(() => expect(exportMock).toHaveBeenCalledWith('2026-08', ['7', '9']));
    });

    it('opens on the admin selected in the page filter', async () => {
        openModal({ defaultUserIds: ['42'] });

        fireEvent.click(screen.getByText('Export excel'));

        await waitFor(() => expect(exportMock).toHaveBeenCalledWith('2026-08', ['42']));
    });

    it('exports the month the user picked instead of the current one', async () => {
        openModal();

        fireEvent.change(screen.getByTestId('month-picker'), { target: { value: '2026-03-01' } });
        fireEvent.click(screen.getByText('Export excel'));

        await waitFor(() => expect(exportMock).toHaveBeenCalledWith('2026-03', []));
    });

    it('closes itself once the download starts', async () => {
        const onClose = vi.fn();
        render(<AttendanceExportModal opened onClose={onClose} />);

        fireEvent.click(screen.getByText('Export excel'));

        await waitFor(() => expect(onClose).toHaveBeenCalled());
    });

    it('reports a failed export and stays open so the admin can retry', async () => {
        const onClose = vi.fn();
        exportMock.mockRejectedValue(new Error('boom'));
        render(<AttendanceExportModal opened onClose={onClose} />);

        fireEvent.click(screen.getByText('Export excel'));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalled());
        expect(onClose).not.toHaveBeenCalled();
    });

    it('only offers admins who can sign in and self check-in, matching what the export covers', () => {
        openModal();

        expect(screen.getByTestId('user-select')).toHaveAttribute(
            'data-permission',
            JSON.stringify(['manager.auth', 'manager.attendance.check_in']),
        );
    });

    it('does nothing when the month has been cleared', () => {
        openModal();

        fireEvent.change(screen.getByTestId('month-picker'), { target: { value: '' } });
        fireEvent.click(screen.getByText('Export excel'));

        expect(exportMock).not.toHaveBeenCalled();
    });
});
