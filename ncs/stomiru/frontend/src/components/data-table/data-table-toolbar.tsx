import * as React from "react";
import type { Column, Table } from "@tanstack/react-table";
import MSearchBox from "@/components/custom/m-search-box";
import { SelectDropdown } from "@/components/custom/select-dropdown";
import { DatePickerWithRange } from "@/components/custom/date-range-picker";
import { cn } from "@/lib/utils";

interface DataTableToolbarProps<TData> extends React.ComponentProps<"div"> {
  table: Table<TData>;
}

export function DataTableToolbar<TData>({
  table,
  className,
  ...props
}: DataTableToolbarProps<TData>) {
  const columns = React.useMemo(
    () =>
      table
        .getAllColumns()
        .filter((column) => column.getCanFilter())
        .sort(
          (a, b) =>
            (a.columnDef?.meta?.displayOrder ?? 0) -
            (b.columnDef?.meta?.displayOrder ?? 0)
        ),
    [table]
  );

  return (
    <div
      role="toolbar"
      className={cn("flex gap-2 whitespace-nowrap items-center", className)}
      {...props}
    >
      {columns.map((column) => (
        <DataTableToolbarFilter key={column.id} column={column} />
      ))}
    </div>
  );
}

interface DataTableToolbarFilterProps<TData> {
  column: Column<TData>;
}

function DataTableToolbarFilter<TData>({
  column,
}: DataTableToolbarFilterProps<TData>) {
  {
    const columnMeta = column.columnDef.meta;

    const onFilterRender = React.useCallback(() => {
      if (!columnMeta?.variant) return null;

      switch (columnMeta.variant) {
        case "simpleInput":
          return (
            <MSearchBox
              placeholder={columnMeta.placeholder ?? columnMeta.label}
              value={(column.getFilterValue() as string) ?? ""}
              onSearch={(value) => column.setFilterValue(value)}
              className={cn("", columnMeta.controlClassName)}
            />
          );

        case "select":
          return (
            <>
              <span className="text-sm font-bold">{columnMeta.label}</span>
              <SelectDropdown
                className={cn("", columnMeta.controlClassName)}
                defaultValue={(column.getFilterValue() as string) ?? null}
                onValueChange={(value) => column.setFilterValue(value)}
                placeholder={columnMeta.placeholder ?? columnMeta.label}
                title={columnMeta.label ?? column.id}
                options={columnMeta.options ?? []}
                allValue={columnMeta.allValue}
              />
            </>
          );

        case "dateRange":
          return (
            <DatePickerWithRange
              className={cn("", columnMeta.controlClassName)}
              value={(column.getFilterValue() as string) ?? undefined}
              onChange={(value) => column.setFilterValue(value)}
              placeholder={columnMeta.placeholder ?? columnMeta.label}
            />
          );

        default:
          return null;
      }
    }, [column, columnMeta]);

    return onFilterRender();
  }
}
