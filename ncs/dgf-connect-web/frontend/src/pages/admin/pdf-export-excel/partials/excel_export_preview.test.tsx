import React from 'react';
import { MantineProvider } from '@mantine/core';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ExcelExportPreview as ExcelExportPreviewData } from '@/types/models/pdf_export_excel';
import ExcelExportPreview from './excel_export_preview';

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) =>
            Object.entries(opts ?? {}).reduce((acc, [name, value]) => acc.replace(`{{${name}}}`, String(value)), key),
    }),
}));

vi.mock('@/components/DDataTable', () => ({
    default: ({ columns, data }: any) =>
        React.createElement(
            'table',
            null,
            React.createElement(
                'tbody',
                null,
                data.map((row: any, index: number) =>
                    React.createElement(
                        'tr',
                        { key: index },
                        columns.map((column: any) =>
                            React.createElement(
                                'td',
                                { key: column.key },
                                column.render ? column.render(row) : String(row[column.key] ?? ''),
                            ),
                        ),
                    ),
                ),
            ),
        ),
}));

const basePreview: ExcelExportPreviewData = {
    template: { id: 1, name: 'Contract' },
    file_name: 'list.xlsx',
    lock_info: null,
    can_export: true,
    summary: {
        total_rows: 4,
        matched_count: 2,
        unmatched_count: 1,
        duplicate_count: 1,
        disabled_count: 1,
        name_mismatch_count: 1,
        numeric_match_count: 1,
    },
    matched: [
        {
            row_number: 2,
            staff_no: '12',
            name: 'Yamada Taro',
            user: { id: 10, name: 'YAMADA TARO', staff_no: '0012', is_disabled: false },
            matched_by: 'numeric',
            is_disabled: false,
            name_mismatch: false,
        },
        {
            row_number: 3,
            staff_no: '1002',
            name: 'Wrong Name',
            user: { id: 11, name: 'SUZUKI HANAKO', staff_no: '1002', is_disabled: true },
            matched_by: 'exact',
            is_disabled: true,
            name_mismatch: true,
        },
    ],
    unmatched: [{ row_number: 4, staff_no: '9999', name: 'Nobody', reason: 'not_found', candidates: [] }],
    duplicates: [
        {
            row_number: 5,
            staff_no: '1002',
            name: null,
            first_row_number: 3,
            user: { id: 11, name: 'SUZUKI HANAKO', staff_no: '1002', is_disabled: true },
        },
    ],
};

const renderPreview = (preview: ExcelExportPreviewData, onConfirm = vi.fn(), onBack = vi.fn()) =>
    render(
        <MantineProvider env="test">
            <ExcelExportPreview preview={preview} onBack={onBack} onConfirm={onConfirm} isConfirming={false} />
        </MantineProvider>,
    );

describe('ExcelExportPreview', () => {
    it('shows the summary, warning badges and enables export', async () => {
        const onConfirm = vi.fn();
        const user = userEvent.setup();

        renderPreview(basePreview, onConfirm);

        expect(screen.getByText('Contract')).toBeInTheDocument();
        expect(screen.getByText('list.xlsx')).toBeInTheDocument();
        expect(screen.getByText('Leading zeros')).toBeInTheDocument();
        expect(screen.getByText('Name differs')).toBeInTheDocument();
        expect(screen.getByText('Disabled')).toBeInTheDocument();
        expect(screen.getByText(/Highlighted rows have warnings/)).toBeInTheDocument();

        const exportButton = screen.getByRole('button', { name: 'Export 2 PDF files' });
        expect(exportButton).toBeEnabled();
        await user.click(exportButton);
        expect(onConfirm).toHaveBeenCalledTimes(1);
    });

    it('switches to the not-found and duplicates tabs', async () => {
        const user = userEvent.setup();
        renderPreview(basePreview);

        await user.click(screen.getByRole('tab', { name: 'Not found (1)' }));
        expect(screen.getByText('Staff number not found')).toBeInTheDocument();
        expect(screen.getByText('9999')).toBeInTheDocument();

        await user.click(screen.getByRole('tab', { name: 'Duplicates (1)' }));
        expect(screen.getByRole('tab', { name: 'Duplicates (1)' })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getAllByText('SUZUKI HANAKO')).toHaveLength(2);
    });

    it('disables export when nothing matched', () => {
        renderPreview({
            ...basePreview,
            can_export: false,
            matched: [],
            summary: { ...basePreview.summary, matched_count: 0 },
        });

        expect(screen.getByRole('button', { name: 'Export 0 PDF files' })).toBeDisabled();
        expect(screen.getByText(/No user in the file matches/)).toBeInTheDocument();
    });

    it('disables export and names the locker when the template is locked', () => {
        renderPreview({
            ...basePreview,
            can_export: false,
            lock_info: { user_id: 1, reason: 'editing', user_name: 'Someone', locked_at: '', ttl: 60 },
        });

        expect(screen.getByRole('button', { name: 'Export 2 PDF files' })).toBeDisabled();
        expect(
            screen.getByText('Template is currently locked by Someone. Please try again later.'),
        ).toBeInTheDocument();
    });
});
