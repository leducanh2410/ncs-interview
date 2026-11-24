import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { buildUserExportFilters } from '@/pages/admin/user/build_export_filters';
import ExportPdfModal from './export_pdf_modal';

const { createBatchMock, getPdfTemplatesMock, usePermissionsMock, showErrorMock, notificationsShowMock } = vi.hoisted(
    () => ({
        createBatchMock: vi.fn(),
        getPdfTemplatesMock: vi.fn(),
        usePermissionsMock: vi.fn(),
        showErrorMock: vi.fn(),
        notificationsShowMock: vi.fn(),
    }),
);

vi.mock('next/link', () => ({
    default: ({ children, href }: any) => React.createElement('a', { href }, children),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: any) => {
            if (opts?.count != null) return key.replace('{{count}}', String(opts.count));
            return key;
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled: disabled || loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, children) : null,
    Select: ({ label, data, value, onChange, disabled }: any) =>
        React.createElement(
            'select',
            {
                'aria-label': label,
                'value': value ?? '',
                disabled,
                'onChange': (e: any) => onChange?.(e.target.value || null),
            },
            React.createElement('option', { value: '' }, '-- select --'),
            (data ?? []).map((opt: any) =>
                React.createElement('option', { key: opt.value, value: opt.value }, opt.label),
            ),
        ),
    Text: ({ children, c }: any) => React.createElement('span', { 'data-c': c }, children),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: { show: (...args: unknown[]) => notificationsShowMock(...args) },
}));

vi.mock('@/helpers/api.helper', () => ({
    ApiError: class ApiError extends Error {
        status: number;
        constructor({ status, message }: { status: number; message: string; data: any }) {
            super(message);
            this.status = status;
        }
    },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showError: (...args: unknown[]) => showErrorMock(...args) },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: (_: string, params: any) => `/admin/user/export-history/${params.id}` },
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/pdf-template/api', () => ({
    useGetPdfTemplatesQuery: (...args: unknown[]) => getPdfTemplatesMock(...args),
    useCreateBatchExportMutation: () => ({ mutateAsync: createBatchMock, isPending: false }),
}));

vi.mock('react-icons/lu', () => ({
    LuFileText: () => React.createElement('span', null, '📄'),
}));

const defaultProps = {
    isOpened: true,
    onClose: vi.fn(),
    selectedUserIds: [1, 2, 3],
    selectAll: false,
    totalUsers: 100,
    filters: buildUserExportFilters({}),
};

const makeTemplates = (items: { id: number; name: string; lock_info?: object | null }[]) => ({
    data: { data: items },
});

describe('ExportPdfModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue({ PDF_TEMPLATE_LIST: true, PDF_TEMPLATE_EXPORT: true });
        getPdfTemplatesMock.mockReturnValue({
            data: makeTemplates([
                { id: 1, name: 'Template A', lock_info: null },
                { id: 2, name: 'Template B (locked)', lock_info: { by: 'user' } },
            ]),
            isPending: false,
        });
    });

    it('renders nothing when closed', () => {
        render(<ExportPdfModal {...defaultProps} isOpened={false} />);

        expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
    });

    it('shows user count for selected users', () => {
        render(<ExportPdfModal {...defaultProps} />);

        expect(screen.getByText(/Exporting for 3 selected users/)).toBeInTheDocument();
    });

    it('shows total count when selectAll is true', () => {
        render(<ExportPdfModal {...defaultProps} selectAll={true} totalUsers={50} />);

        expect(screen.getByText(/Exporting for all 50 users matching current filters/)).toBeInTheDocument();
    });

    it('lists only unlocked templates in the selector', () => {
        render(<ExportPdfModal {...defaultProps} />);

        const select = screen.getByRole('combobox', { name: 'Template' });
        expect(select).toBeInTheDocument();
        // locked template should not appear
        const options = Array.from(select.querySelectorAll('option')).map((o) => o.textContent);
        expect(options).toContain('Template A');
        expect(options).not.toContain('Template B (locked)');
    });

    it('shows no-templates message when all templates are locked', () => {
        getPdfTemplatesMock.mockReturnValue({
            data: makeTemplates([{ id: 1, name: 'Locked', lock_info: { by: 'user' } }]),
            isPending: false,
        });

        render(<ExportPdfModal {...defaultProps} />);

        expect(screen.getByText('No unlocked templates available')).toBeInTheDocument();
    });

    it('Export button is disabled when no template is selected', () => {
        render(<ExportPdfModal {...defaultProps} />);

        expect(screen.getByRole('button', { name: 'Export' })).toBeDisabled();
    });

    it('calls mutation and shows success notification on export', async () => {
        const user = userEvent.setup();
        createBatchMock.mockResolvedValue({ data: { id: 99 } });

        render(<ExportPdfModal {...defaultProps} />);

        await user.selectOptions(screen.getByRole('combobox', { name: 'Template' }), '1');
        await user.click(screen.getByRole('button', { name: 'Export' }));

        await waitFor(() => {
            expect(createBatchMock).toHaveBeenCalledWith(
                expect.objectContaining({ template_id: 1, user_ids: [1, 2, 3], select_all: false }),
            );
        });
        expect(notificationsShowMock).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
    });

    it('shows locked-template error for ApiError with status 423', async () => {
        const user = userEvent.setup();
        const { ApiError } = await import('@/helpers/api.helper');
        createBatchMock.mockRejectedValue(new ApiError({ status: 423, message: 'Locked', data: {} }));

        render(<ExportPdfModal {...defaultProps} />);

        await user.selectOptions(screen.getByRole('combobox', { name: 'Template' }), '1');
        await user.click(screen.getByRole('button', { name: 'Export' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith('Template is currently locked. Please try again later.');
        });
    });
});
