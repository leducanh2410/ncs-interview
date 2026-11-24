import React, { ReactNode, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Box, MantineStyleProp } from '@mantine/core';
import {
    DataTable,
    DataTableRowExpansionProps,
    DataTableSelectionTrigger,
    useDataTableColumns,
} from 'mantine-datatable';
import { PaginatedData } from '@/types/api';
import { Enum } from '@/types/misc';
import EnumHelper from '@/helpers/enum.helper';
import DPagination from '@/components/DPagination';
import TableEmptyState, { NoPermissionState } from '@/components/TableEmptyState';
import classes from './DDataTable.module.css';

type RowData = { [key: string | number]: any };

export type ColumnDefinition<T extends RowData> = {
    name: string;
    key: string;
    render?: (rowData: T) => ReactNode;
    enumObj?: Enum;
    width?: string | number;
    align?: 'left' | 'center' | 'right';
    toggleable?: boolean;
    defaultToggle?: boolean;
    /** Lets the header label wrap instead of being ellipsis-truncated. See `headerWrapClassName`. */
    titleClassName?: string;
};

/** Pass as a column's `titleClassName` to wrap its header label instead of truncating it. */
export const headerWrapClassName = classes.headerWrap;

type Props<T extends RowData> = {
    tableKey?: string;
    columns: ColumnDefinition<T>[];
    data: PaginatedData<T> | T[];
    isFetching: boolean;
    height?: string | number;
    maxHeight?: string | number;
    highlightOnHover?: boolean;
    noHeader?: boolean;
    withTableBorder?: boolean;
    rowExpansion?: DataTableRowExpansionProps<T>;
    selectedRecords?: T[];
    onSelectedRecordsChange?: (records: T[]) => void;
    selectionTrigger?: DataTableSelectionTrigger;
    isRecordSelectable?: (record: T) => boolean;
    rowStyle?: (record: T, index: number) => MantineStyleProp | undefined;
    showPageSize?: boolean;
    onPageChanged?: (page: number) => void;
    noPermission?: boolean;
    emptyMinHeight?: number;
    pinFirstColumn?: boolean;
};

const isPaginated = <T,>(data: PaginatedData<T> | T[]): data is PaginatedData<T> => {
    return !Array.isArray(data);
};

const DDataTable = <T extends RowData>({
    tableKey,
    columns: columnDefinitions,
    data,
    isFetching,
    height,
    maxHeight,
    emptyMinHeight = 150,
    withTableBorder = true,
    selectedRecords,
    onSelectedRecordsChange,
    selectionTrigger,
    rowStyle,
    showPageSize = true,
    onPageChanged,
    noPermission,
    ...rest
}: Props<T>) => {
    const { t } = useTranslation();
    const hasPagination = isPaginated(data);
    const hasData = hasPagination ? data.data.length > 0 : data.length > 0;

    const { effectiveColumns, resetColumnsWidth } = useDataTableColumns({
        key: tableKey,
        columns: columnDefinitions.map((column) => {
            return {
                accessor: column.key,
                title: column.name,
                titleClassName: column.titleClassName,
                textAlign: column.align,
                width: column.width,
                toggleable: column.toggleable,
                defaultToggle: column.defaultToggle,
                render: (record: T) => {
                    if (!column.render) {
                        if (column.enumObj) {
                            return EnumHelper.getText(column.enumObj, record[column.key]);
                        }
                        return record[column.key];
                    }
                    return column.render(record);
                },
            };
        }),
    });

    useEffect(() => {
        resetColumnsWidth();
    }, []);

    return (
        <Box pos="relative">
            <DataTable
                height={height}
                minHeight={!hasData ? emptyMinHeight : undefined}
                maxHeight={maxHeight}
                classNames={{
                    table: classes.table,
                    header: classes.header,
                }}
                withTableBorder={withTableBorder}
                withColumnBorders
                records={hasPagination ? data.data : data}
                fetching={isFetching}
                emptyState={noPermission ? <NoPermissionState /> : <TableEmptyState />}
                storeColumnsKey={tableKey}
                columns={effectiveColumns}
                selectedRecords={selectedRecords}
                onSelectedRecordsChange={onSelectedRecordsChange}
                selectionTrigger={selectionTrigger}
                rowStyle={rowStyle}
                {...rest}
            />
            {hasPagination && data.total > 0 && (
                <DPagination data={data} showPageSize={showPageSize} onPageChanged={onPageChanged} />
            )}
        </Box>
    );
};

export default DDataTable;
