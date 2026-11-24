import React from 'react';
import { useTranslation } from 'react-i18next';
import { ExcelDuplicateRow } from '@/types/models/pdf_export_excel';
import DDataTable from '@/components/DDataTable';

type Props = {
    tableKey: string;
    rows: ExcelDuplicateRow[];
};

const ExcelDuplicatesTable = ({ tableKey, rows }: Props) => {
    const { t } = useTranslation();

    return (
        <DDataTable<ExcelDuplicateRow>
            tableKey={tableKey}
            columns={[
                { name: t('Row'), key: 'row_number', width: 80, align: 'center' },
                { name: t('Staff No (file)'), key: 'staff_no', width: 140 },
                { name: t('Name (file)'), key: 'name', render: (row) => row.name ?? '-' },
                { name: t('User (system)'), key: 'user', render: (row) => row.user.name },
                { name: t('First seen at row'), key: 'first_row_number', width: 150, align: 'center' },
            ]}
            data={rows}
            isFetching={false}
        />
    );
};

export default ExcelDuplicatesTable;
