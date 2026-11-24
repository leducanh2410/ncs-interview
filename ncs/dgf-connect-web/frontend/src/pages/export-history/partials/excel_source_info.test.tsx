import React from 'react';
import { MantineProvider } from '@mantine/core';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ExportBatch } from '@/types/models/pdf_template';
import ExcelSourceInfo from './excel_source_info';

const { downloadMutateMock, showErrorMock } = vi.hoisted(() => ({
    downloadMutateMock: vi.fn(),
    showErrorMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showError: (...args: unknown[]) => showErrorMock(...args) },
}));

vi.mock('@/pages/admin/pdf-export-excel/api', () => ({
    useDownloadSourceFileMutation: () => ({ mutateAsync: downloadMutateMock, isPending: false }),
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

const batch = {
    id: 42,
    type: 'pdf',
    source: 'excel',
    status: 'completed',
    meta: {
        source_file_name: 'targets.xlsx',
        source_file_path: 'pdf-export-sources/2026/abc.xlsx',
        excel: {
            summary: {
                total_rows: 5,
                matched_count: 3,
                unmatched_count: 1,
                duplicate_count: 1,
                disabled_count: 0,
                name_mismatch_count: 1,
                numeric_match_count: 0,
            },
            unmatched: [{ row_number: 4, staff_no: '9999', name: 'Nobody', reason: 'not_found', candidates: [] }],
            duplicates: [],
            name_mismatches: [
                {
                    row_number: 3,
                    staff_no: '2002',
                    name: 'Wrong Name',
                    user: { id: 2, name: 'SUZUKI HANAKO', staff_no: '2002', is_disabled: false },
                },
            ],
            truncated: true,
        },
    },
} as unknown as ExportBatch;

const renderInfo = (canDownload = true) =>
    render(
        <MantineProvider env="test">
            <ExcelSourceInfo batch={batch} canDownload={canDownload} />
        </MantineProvider>,
    );

describe('ExcelSourceInfo', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows the input file, summary and unmatched rows', () => {
        renderInfo();

        expect(screen.getByText('targets.xlsx')).toBeInTheDocument();
        expect(screen.getByText('Matched users: 3')).toBeInTheDocument();
        expect(screen.getByText('Not found: 1')).toBeInTheDocument();
        expect(screen.getByText('9999')).toBeInTheDocument();
        expect(screen.getByText('Only the first rows of each list were stored in the history.')).toBeInTheDocument();
    });

    it('shows name mismatches on their tab', async () => {
        const user = userEvent.setup();
        renderInfo();

        await user.click(screen.getByRole('tab', { name: 'Name mismatches (1)' }));

        expect(screen.getByText('Wrong Name')).toBeInTheDocument();
        expect(screen.getByText('SUZUKI HANAKO')).toBeInTheDocument();
    });

    it('downloads the input file for the batch', async () => {
        const user = userEvent.setup();
        downloadMutateMock.mockResolvedValue('https://example.test/file.xlsx');
        renderInfo();

        await user.click(screen.getByRole('button', { name: 'Download input file' }));

        await waitFor(() => expect(downloadMutateMock).toHaveBeenCalledWith(42));
    });

    it('reports a failed download and disables the button without permission', async () => {
        const user = userEvent.setup();
        downloadMutateMock.mockRejectedValue(new Error('nope'));
        renderInfo();

        await user.click(screen.getByRole('button', { name: 'Download input file' }));
        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('Could not download input file'));

        renderInfo(false);
        expect(screen.getAllByRole('button', { name: 'Download input file' })[1]).toBeDisabled();
    });
});
