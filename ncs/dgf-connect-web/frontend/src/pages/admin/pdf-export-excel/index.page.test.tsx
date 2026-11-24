import React from 'react';
import { MantineProvider } from '@mantine/core';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import PdfExportExcelPage from './index.page';

const {
    previewMutateMock,
    createMutateMock,
    sampleMutateMock,
    getPdfTemplatesMock,
    getExcelLimitsMock,
    usePermissionsMock,
    showSuccessMock,
    showErrorMock,
} = vi.hoisted(() => ({
    previewMutateMock: vi.fn(),
    createMutateMock: vi.fn(),
    sampleMutateMock: vi.fn(),
    getPdfTemplatesMock: vi.fn(),
    getExcelLimitsMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: any) => React.createElement('a', { href }, children),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) =>
            Object.entries(opts ?? {}).reduce((acc, [name, value]) => acc.replace(`{{${name}}}`, String(value)), key),
    }),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: (_route: string, params: any) => `/export-history/${params.id}` },
}));

vi.mock('@/pages/admin/pdf-template/api', () => ({
    useGetPdfTemplatesQuery: (...args: unknown[]) => getPdfTemplatesMock(...args),
}));

vi.mock('@/pages/admin/pdf-export-excel/api', () => ({
    usePreviewExcelExportMutation: () => ({ mutateAsync: previewMutateMock, isPending: false }),
    useCreateExcelBatchExportMutation: () => ({ mutateAsync: createMutateMock, isPending: false }),
    useDownloadExcelSampleMutation: () => ({ mutateAsync: sampleMutateMock, isPending: false }),
    useGetExcelExportLimitsQuery: (...args: unknown[]) => getExcelLimitsMock(...args),
}));

vi.mock('./partials/excel_export_upload_form', async (importOriginal) => ({
    ...(await importOriginal<typeof import('./partials/excel_export_upload_form')>()),
    default: ({
        templateOptions,
        onTemplateChange,
        onFileChange,
        onSubmit,
        errorMessage,
        onDownloadSample,
        maxFileSizeKb,
    }: any) =>
        React.createElement(
            'div',
            { 'data-testid': 'upload-form' },
            React.createElement('span', { 'data-testid': 'max-size' }, String(maxFileSizeKb)),
            templateOptions.map((option: any) =>
                React.createElement(
                    'button',
                    {
                        key: option.value,
                        type: 'button',
                        disabled: option.disabled,
                        onClick: () => onTemplateChange(option.value),
                    },
                    option.label,
                ),
            ),
            React.createElement(
                'button',
                { type: 'button', onClick: () => onFileChange(new File(['x'], 'list.xlsx')) },
                'pick file',
            ),
            React.createElement(
                'button',
                { type: 'button', onClick: () => onFileChange(new File(['y'], 'other.xlsx')) },
                'pick other file',
            ),
            React.createElement('button', { type: 'button', onClick: () => onFileChange(null) }, 'reject file'),
            React.createElement('button', { type: 'button', onClick: onSubmit }, 'submit'),
            React.createElement('button', { type: 'button', onClick: onDownloadSample }, 'sample'),
            errorMessage ? React.createElement('p', { 'data-testid': 'error' }, errorMessage) : null,
        ),
}));

vi.mock('./partials/excel_export_preview', () => ({
    default: ({ preview, onBack, onConfirm }: any) =>
        React.createElement(
            'div',
            { 'data-testid': 'preview' },
            React.createElement('span', null, `matched:${preview.summary.matched_count}`),
            React.createElement('button', { type: 'button', onClick: onBack }, 'back'),
            React.createElement('button', { type: 'button', onClick: onConfirm }, 'confirm'),
        ),
}));

const previewData = {
    template: { id: 7, name: 'Contract' },
    file_name: 'list.xlsx',
    lock_info: null,
    can_export: true,
    summary: {
        total_rows: 3,
        matched_count: 2,
        unmatched_count: 1,
        duplicate_count: 0,
        disabled_count: 0,
        name_mismatch_count: 0,
        numeric_match_count: 0,
    },
    matched: [],
    unmatched: [],
    duplicates: [],
};

const renderPage = () =>
    render(
        <MantineProvider env="test">
            <PdfExportExcelPage />
        </MantineProvider>,
    );

describe('PdfExportExcelPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue({ PDF_TEMPLATE_LIST: true, PDF_TEMPLATE_EXPORT: true });
        getExcelLimitsMock.mockReturnValue({ data: { data: { max_rows: 5000, max_file_size_kb: 10240 } } });
        getPdfTemplatesMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    data: [
                        { id: 7, name: 'Contract', lock_info: null },
                        { id: 8, name: 'Locked one', lock_info: { user_name: 'Someone' } },
                        { id: 9, name: 'Letter', lock_info: null },
                    ],
                },
            },
        });
    });

    it('passes the server file size limit to the upload form and error messages', async () => {
        const user = userEvent.setup();

        renderPage();

        expect(screen.getByTestId('max-size')).toHaveTextContent('10240');

        await user.click(screen.getByRole('button', { name: 'reject file' }));

        expect(screen.getByTestId('error')).toHaveTextContent('Invalid file. Please upload an Excel file under 10MB.');
    });

    it('falls back to 5MB while the limits are not loaded', async () => {
        const user = userEvent.setup();
        getExcelLimitsMock.mockReturnValue({ data: undefined });

        renderPage();

        expect(screen.getByTestId('max-size')).toHaveTextContent('5120');

        await user.click(screen.getByRole('button', { name: 'reject file' }));

        expect(screen.getByTestId('error')).toHaveTextContent('Invalid file. Please upload an Excel file under 5MB.');
    });

    it('exports the template and file that produced the preview even if inputs change meanwhile', async () => {
        const user = userEvent.setup();
        let resolvePreview: (value: unknown) => void = () => undefined;
        previewMutateMock.mockImplementation(
            () =>
                new Promise((resolve) => {
                    resolvePreview = resolve;
                }),
        );
        createMutateMock.mockResolvedValue({ data: { id: 42, total_count: 2 } });

        renderPage();

        await user.click(screen.getByRole('button', { name: 'Contract' }));
        await user.click(screen.getByRole('button', { name: 'pick file' }));
        await user.click(screen.getByRole('button', { name: 'submit' }));

        await user.click(screen.getByRole('button', { name: 'Letter' }));
        await user.click(screen.getByRole('button', { name: 'pick other file' }));

        resolvePreview({ data: previewData });
        await waitFor(() => expect(screen.getByTestId('preview')).toBeInTheDocument());

        await user.click(screen.getByRole('button', { name: 'confirm' }));

        await waitFor(() => expect(createMutateMock).toHaveBeenCalledTimes(1));
        const payload = createMutateMock.mock.calls[0][0];
        expect(payload.templateId).toBe('7');
        expect(payload.file.name).toBe('list.xlsx');
    });

    it('marks locked templates as disabled options', () => {
        renderPage();

        expect(screen.getByRole('button', { name: 'Contract' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Locked one (Locked)' })).toBeDisabled();
    });

    it('walks upload -> preview -> export and shows the batch link', async () => {
        const user = userEvent.setup();
        previewMutateMock.mockResolvedValue({ data: previewData });
        createMutateMock.mockResolvedValue({ data: { id: 42, total_count: 2 } });

        renderPage();

        await user.click(screen.getByRole('button', { name: 'Contract' }));
        await user.click(screen.getByRole('button', { name: 'pick file' }));
        await user.click(screen.getByRole('button', { name: 'submit' }));

        await waitFor(() => expect(screen.getByTestId('preview')).toBeInTheDocument());
        expect(previewMutateMock).toHaveBeenCalledWith(
            expect.objectContaining({ templateId: '7', file: expect.any(File) }),
        );
        expect(screen.getByText('matched:2')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'confirm' }));

        await waitFor(() => expect(screen.getByText('View progress')).toBeInTheDocument());
        expect(createMutateMock).toHaveBeenCalledWith(
            expect.objectContaining({ templateId: '7', file: expect.any(File) }),
        );
        expect(screen.getByText('View progress').closest('a')).toHaveAttribute('href', '/export-history/42');
        expect(screen.getByText('2 PDF files are being generated in the background.')).toBeInTheDocument();
        expect(showSuccessMock).toHaveBeenCalledWith('Export started successfully');
    });

    it('returns to the upload step from the preview', async () => {
        const user = userEvent.setup();
        previewMutateMock.mockResolvedValue({ data: previewData });

        renderPage();

        await user.click(screen.getByRole('button', { name: 'Contract' }));
        await user.click(screen.getByRole('button', { name: 'pick file' }));
        await user.click(screen.getByRole('button', { name: 'submit' }));
        await waitFor(() => expect(screen.getByTestId('preview')).toBeInTheDocument());

        await user.click(screen.getByRole('button', { name: 'back' }));

        expect(screen.getByTestId('upload-form')).toBeInTheDocument();
        expect(screen.queryByTestId('preview')).not.toBeInTheDocument();
    });

    it('maps backend error codes to a readable message on the upload step', async () => {
        const user = userEvent.setup();
        previewMutateMock.mockRejectedValue(
            new ApiError({ status: 422, message: 'PDF_EXPORT_EXCEL_TOO_MANY_ROWS', data: { max_rows: 5000 } }),
        );

        renderPage();

        await user.click(screen.getByRole('button', { name: 'Contract' }));
        await user.click(screen.getByRole('button', { name: 'pick file' }));
        await user.click(screen.getByRole('button', { name: 'submit' }));

        await waitFor(() =>
            expect(screen.getByTestId('error')).toHaveTextContent('The file has too many rows. Maximum is 5000 rows.'),
        );
        expect(screen.queryByTestId('preview')).not.toBeInTheDocument();
    });

    it('shows a notification when the export cannot be started', async () => {
        const user = userEvent.setup();
        previewMutateMock.mockResolvedValue({ data: previewData });
        createMutateMock.mockRejectedValue(new ApiError({ status: 423, message: 'PDF_TEMPLATE_LOCKED', data: {} }));

        renderPage();

        await user.click(screen.getByRole('button', { name: 'Contract' }));
        await user.click(screen.getByRole('button', { name: 'pick file' }));
        await user.click(screen.getByRole('button', { name: 'submit' }));
        await waitFor(() => expect(screen.getByTestId('preview')).toBeInTheDocument());

        await user.click(screen.getByRole('button', { name: 'confirm' }));

        await waitFor(() =>
            expect(showErrorMock).toHaveBeenCalledWith('Template is currently locked. Please try again later.'),
        );
        expect(screen.getByTestId('preview')).toBeInTheDocument();
    });

    it('downloads the sample file', async () => {
        const user = userEvent.setup();
        sampleMutateMock.mockResolvedValue(undefined);

        renderPage();
        await user.click(screen.getByRole('button', { name: 'sample' }));

        expect(sampleMutateMock).toHaveBeenCalledTimes(1);
    });
});
