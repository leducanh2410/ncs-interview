import React from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Text } from '@mantine/core';
import { ExcelUnmatchedRow } from '@/types/models/pdf_export_excel';
import DDataTable from '@/components/DDataTable';

type Props = {
    rows: ExcelUnmatchedRow[];
};

const ExcelUnmatchedTable = ({ rows }: Props) => {
    const { t } = useTranslation();

    const reasonLabel = (row: ExcelUnmatchedRow) => {
        switch (row.reason) {
            case 'ambiguous':
                return t('Multiple users share this number');
            case 'missing_staff_no':
                return t('Staff number is empty');
            default:
                return t('Staff number not found');
        }
    };

    return (
        <DDataTable<ExcelUnmatchedRow>
            tableKey="pdf-export-excel-unmatched"
            columns={[
                { name: t('Row'), key: 'row_number', width: 80, align: 'center' },
                { name: t('Staff No (file)'), key: 'staff_no', width: 140, render: (row) => row.staff_no || '-' },
                { name: t('Name (file)'), key: 'name', render: (row) => row.name ?? '-' },
                {
                    name: t('Reason'),
                    key: 'reason',
                    render: (row) => (
                        <Badge color={row.reason === 'ambiguous' ? 'orange' : 'red'} variant="light">
                            {reasonLabel(row)}
                        </Badge>
                    ),
                },
                {
                    name: t('Candidates'),
                    key: 'candidates',
                    render: (row) =>
                        row.candidates.length > 0 ? (
                            <Text size="sm">
                                {row.candidates.map((user) => `${user.name} (${user.staff_no ?? '-'})`).join(', ')}
                            </Text>
                        ) : (
                            '-'
                        ),
                },
            ]}
            data={rows}
            isFetching={false}
        />
    );
};

export default ExcelUnmatchedTable;
