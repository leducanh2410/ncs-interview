import React from 'react';
import { MantineProvider } from '@mantine/core';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ExcelExportUploadForm, { formatFileSizeMb } from './excel_export_upload_form';

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) =>
            Object.entries(opts ?? {}).reduce((acc, [name, value]) => acc.replace(`{{${name}}}`, String(value)), key),
    }),
}));

const renderForm = (overrides: Partial<React.ComponentProps<typeof ExcelExportUploadForm>> = {}) =>
    render(
        <MantineProvider env="test">
            <ExcelExportUploadForm
                templateOptions={[{ value: '7', label: 'Contract' }]}
                isTemplatesLoading={false}
                templateId="7"
                onTemplateChange={vi.fn()}
                file={null}
                onFileChange={vi.fn()}
                maxFileSizeKb={10240}
                onSubmit={vi.fn()}
                isSubmitting={false}
                onDownloadSample={vi.fn()}
                isDownloadingSample={false}
                errorMessage={null}
                {...overrides}
            />
        </MantineProvider>,
    );

describe('ExcelExportUploadForm', () => {
    it('shows the configured file size limit', () => {
        renderForm();

        expect(screen.getByText('Accepted: .xlsx, .xls, .csv. Max file size: 10MB')).toBeInTheDocument();
    });

    it('locks the template select and dropzone while the preview request is running', () => {
        const { container } = renderForm({ isSubmitting: true });

        expect(screen.getByPlaceholderText('Select a template')).toBeDisabled();
        expect(container.querySelector('.mantine-Dropzone-root')).toHaveAttribute('data-disabled', 'true');
    });

    it('keeps the inputs editable when idle', () => {
        const { container } = renderForm();

        expect(screen.getByPlaceholderText('Select a template')).toBeEnabled();
        expect(container.querySelector('.mantine-Dropzone-root')).not.toHaveAttribute('data-disabled');
    });

    it('formats the limit in megabytes', () => {
        expect(formatFileSizeMb(5120)).toBe('5');
        expect(formatFileSizeMb(2560)).toBe('2.5');
    });
});
